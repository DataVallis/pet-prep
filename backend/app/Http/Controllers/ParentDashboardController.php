<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\Family;
use App\Models\Pet;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\FamilyDashboardService;
use App\Services\FamilyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parent dashboard (family model M2-01, ADR-012). Every parent of the family
 * sees the same data: all children and all pets.
 *
 * Backward compatibility: the single-pet fields (`pet`, `child`,
 * `traffic_light`, `recent_activities`, `weekly_performance`) still describe
 * one pet — the family's oldest active pet (else its latest game-over pet) —
 * so current app builds keep working. The whole family is under `family`.
 */
class ParentDashboardController extends Controller
{
    public function __construct(
        private readonly FamilyService $families,
        private readonly FamilyDashboardService $dashboard,
    ) {}

    /**
     * GET /api/parent/dashboard
     * Family (parents, children with per-child 7-day stats, pets with
     * caretakers) + legacy single-pet state, traffic light, quiet hours,
     * recent activities.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $parent = $request->user();

        if (! $parent->can('manageFamily', User::class)) {
            return response()->json(['message' => 'Only parent profiles can access the dashboard.'], 403);
        }

        $family = $this->families->ensureFamilyFor($parent);
        $familyData = $this->dashboard->family($family, $parent);
        $quietHours = QuietHours::where('family_id', $family->id)->first();
        $pet = $this->dashboard->legacyPet($family);

        if ($pet === null) {
            $hasChildren = $familyData['children'] !== [];

            return response()->json([
                'message' => $hasChildren ? 'No active pet session found.' : 'No child profile paired yet.',
                'timezone' => $family->timezone,
                'pet' => null,
                'traffic_light' => 'green',
                'quiet_hours' => $hasChildren ? $quietHours : null,
                'recent_activities' => [],
                'family' => $familyData,
            ], 200);
        }

        $child = $pet->caretakers()->first();

        return response()->json([
            'timezone' => $family->timezone,
            'pet' => [
                'id' => $pet->id,
                'breed_type' => $pet->breed_type->value,
                // Contract before birth (M1-07b): until the first contract,
                // born_at is null and awaiting_contract true (metrics 100,
                // nothing decays, no alerts).
                'born_at' => $pet->born_at?->toIso8601String(),
                'awaiting_contract' => $pet->isUnborn(),
                'hunger_level' => $pet->displayMetric('hunger_level'),
                'thirst_level' => $pet->displayMetric('thirst_level'),
                'energy_level' => $pet->displayMetric('energy_level'),
                'hygiene_level' => $pet->displayMetric('hygiene_level'),
                'pet_state' => $pet->pet_state->value,
                'is_active' => $pet->is_active,
                'is_ill' => $pet->isIll(),
                'is_game_over' => $pet->is_game_over,
                'is_hard_stopped' => $pet->is_hard_stopped,
                'escalation_level' => $pet->escalation_level,
                'virtual_age_months' => $pet->virtualAgeInMonths(),
                'current_video_url' => $pet->current_video_url,
            ],
            'child' => $child ? [
                'id' => $child->id,
                'name' => $child->name,
            ] : null,
            'traffic_light' => FamilyDashboardService::trafficLight($pet),
            'quiet_hours' => $this->formatQuietHours($quietHours),
            'recent_activities' => $this->activityItems($pet->activities()
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->limit(20)
                ->get()),
            'weekly_performance' => $this->getWeeklyPerformance($pet, $family->timezone),
            'family' => $familyData,
        ], 200);
    }

    /**
     * GET /api/parent/activities?pet_id=
     * Paginated activity logs for the timeline of one pet of the family
     * (default: the legacy pet). Each item names the acting child
     * (`actor_user_id`, null for system events).
     */
    public function activities(Request $request): JsonResponse
    {
        $parent = $request->user();

        if (! $parent->can('manageFamily', User::class)) {
            return response()->json(['message' => 'Only parent profiles can access activities.'], 403);
        }

        $family = $this->families->ensureFamilyFor($parent);
        $pet = $this->dashboard->targetPet($family, $request->query('pet_id'));

        if ($pet === null || ($request->query('pet_id') === null && ! $pet->is_active)) {
            if ($request->query('pet_id') !== null && $pet === null) {
                return response()->json(['message' => 'Pet not found in your family.'], 404);
            }

            return response()->json(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]], 200);
        }

        $perPage = (int) $request->query('per_page', 20);
        $paginated = $pet->activities()
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => $this->activityItems($paginated->getCollection()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
            ],
        ], 200);
    }

    /**
     * POST /api/parent/hard-stop {pet_id?}
     * Toggles hard stop on one active pet of the family (default: the legacy
     * pet) and broadcasts PetUpdated. Any parent of the family may do it.
     */
    public function toggleHardStop(Request $request): JsonResponse
    {
        $parent = $request->user();

        if (! $parent->can('manageFamily', User::class)) {
            return response()->json(['message' => 'Only parent profiles can trigger hard stop.'], 403);
        }

        $request->validate(['pet_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);

        $family = $this->families->ensureFamilyFor($parent);

        if (! $this->hasChildren($family)) {
            return response()->json(['message' => 'No child profile paired.'], 404);
        }

        $pet = $this->dashboard->targetPet($family, $request->input('pet_id'));

        if (! $pet || ! $pet->is_active || ! $parent->can('manage', $pet)) {
            return response()->json(['message' => 'No active pet session found.'], 404);
        }

        // Toggle hard stop
        $pet->update([
            'is_hard_stopped' => ! $pet->is_hard_stopped,
        ]);

        // Broadcast the update to every caretaker and parent via Reverb
        $eventType = $pet->is_hard_stopped ? 'hard_stop_activated' : 'hard_stop_deactivated';
        PetUpdated::afterCommit($pet->fresh(), $eventType);

        return response()->json([
            'message' => $pet->is_hard_stopped
                ? 'Hard stop activated. Child app locked.'
                : 'Hard stop deactivated. Child app unlocked.',
            'pet_id' => $pet->id,
            'is_hard_stopped' => $pet->fresh()->is_hard_stopped,
        ], 200);
    }

    private function hasChildren(Family $family): bool
    {
        return $family->children()->exists();
    }

    /**
     * @param  iterable<ActivityLog>  $logs
     * @return array<int, array<string, mixed>>
     */
    private function activityItems(iterable $logs): array
    {
        $items = [];
        foreach ($logs as $log) {
            $items[] = [
                'id' => $log->id,
                'activity_type' => $log->activity_type->value,
                'value' => $log->value,
                // The child who did it (M2-01); null for system events.
                'actor_user_id' => $log->actor_user_id,
                'created_at' => $log->created_at?->toIso8601String(),
                'is_positive' => $log->activity_type !== ActivityType::IgnoredWarning,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatQuietHours(?QuietHours $quietHours): ?array
    {
        return $quietHours ? [
            'school_start' => $quietHours->school_start,
            'school_end' => $quietHours->school_end,
            'bedtime_start' => $quietHours->bedtime_start,
            'bedtime_end' => $quietHours->bedtime_end,
            'is_active' => $quietHours->is_active,
        ] : null;
    }

    /**
     * Get weekly performance data — daily routine completion counts for the
     * last 7 days, bucketed by the family's local calendar day (M1-03).
     * `date` is the local date; DST days are 23 / 25 hours long.
     *
     * @return array<int, array{date: string, completed: int, missed: int}>
     */
    private function getWeeklyPerformance(Pet $pet, string $timezone): array
    {
        $performance = [];
        $positiveActivities = [
            ActivityType::FedPet->value,
            ActivityType::WateredPet->value,
            ActivityType::WalkedPet->value,
            ActivityType::CleanedPoop->value,
        ];

        $today = now()->setTimezone($timezone)->startOfDay();

        for ($i = 6; $i >= 0; $i--) {
            $localDay = $today->copy()->subDays($i);
            // created_at is stored in UTC: query with UTC bounds [start, next start).
            $start = $localDay->copy()->utc();
            $end = $localDay->copy()->addDay()->utc();

            $completed = ActivityLog::where('pet_id', $pet->id)
                ->whereIn('activity_type', $positiveActivities)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->count();

            $missed = ActivityLog::where('pet_id', $pet->id)
                ->where('activity_type', ActivityType::IgnoredWarning->value)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->count();

            $performance[] = [
                'date' => $localDay->toDateString(),
                'completed' => $completed,
                'missed' => $missed,
            ];
        }

        return $performance;
    }
}
