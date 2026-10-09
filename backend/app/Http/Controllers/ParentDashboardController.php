<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Requests\HardStopRequest;
use App\Models\ActivityLog;
use App\Models\Family;
use App\Models\Pet;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\FamilyDashboardService;
use App\Services\FamilyService;
use App\Services\HardStopService;
use App\Services\Media\PetMediaService;
use App\Services\PetPlanPayload;
use App\Services\PetProfilePayload;
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
        private readonly HardStopService $hardStops,
        private readonly PetMediaService $media,
    ) {}

    /**
     * GET /api/parent/dashboard
     * Family (parents; children with 7-day stats, traffic light, Care
     * Score, today's routines, last 7 days and 12-week progress; pets with
     * caretakers, traffic light, metrics, Care Score, today and timeline —
     * M2-05 / M2-06) + legacy single-pet state, legacy traffic light
     * (escalation based, deprecated), quiet hours, recent activities.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $parent = $request->user();

        if (! $parent->can('manageFamily', User::class)) {
            return response()->json(['message' => 'Only parent profiles can access the dashboard.'], 403);
        }

        // GET never writes: a parent without a family (legacy data) gets the
        // empty state instead of a new family.
        $family = $this->families->familyOf($parent);

        if ($family === null) {
            return response()->json([
                'message' => 'No child profile paired yet.',
                'timezone' => $parent->familyTimezone(),
                'pet' => null,
                'traffic_light' => 'green',
                'quiet_hours' => null,
                'recent_activities' => [],
                'family' => null,
            ], 200);
        }

        $familyData = $this->dashboard->family($family, $parent);
        // What the server applies: the stored row, else the defaults (saved: false).
        $quietHours = QuietHours::where('family_id', $family->id)->first() ?? QuietHours::defaultFor($family);
        $pet = $this->dashboard->legacyPet($family);

        if ($pet === null) {
            $hasChildren = $familyData['children'] !== [];

            return response()->json([
                'message' => $hasChildren ? 'No active pet session found.' : 'No child profile paired yet.',
                'timezone' => $family->timezone,
                'pet' => null,
                'traffic_light' => 'green',
                'quiet_hours' => $hasChildren
                    ? array_merge($quietHours->withoutRelations()->toArray(), ['saved' => (bool) $quietHours->exists])
                    : null,
                'recent_activities' => [],
                'family' => $familyData,
            ], 200);
        }

        $child = $pet->caretakers()->first();
        $media = $this->media->mediaFor($pet);

        return response()->json([
            'timezone' => $family->timezone,
            'pet' => [
                'id' => $pet->id,
                'breed_type' => $pet->breed_type->value,
                // M5-R06-01: dog | cat.
                'species' => $pet->speciesValue()->value,
                // M5-R08: optional name set by a parent (null = none) — a label only.
                'name' => $pet->name,
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
                // M3-11: plan + payment status (paywall per pet).
                'plan' => PetPlanPayload::for($pet, $family->timezone)->toArray(),
                // M5-R01: origin, age, life stage and today's rules.
                'profile' => PetProfilePayload::for($pet)->toArray(),
                // AI media (M4-05): signed URLs to our stored copies.
                'current_video_url' => $media->currentVideoUrl,
                'reference_image_url' => $media->referenceImageUrl,
                'media_status' => $pet->media_status,
                'media' => $media->toArray(),
            ],
            'child' => $child ? [
                'id' => $child->id,
                'name' => $child->name,
            ] : null,
            'traffic_light' => FamilyDashboardService::trafficLight($pet),
            'quiet_hours' => $this->formatQuietHours($quietHours),
            'recent_activities' => $this->activityItems($pet->activities()
                // M3-11: a free pet's history is limited to the last 7 days.
                ->when($pet->isFreePlan(), fn ($q) => $q->where('created_at', '>=', FamilyDashboardService::historyStart($pet)))
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

        $request->validate([
            'pet_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $empty = ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]];
        $petId = $request->query('pet_id');
        $family = $this->families->familyOf($parent); // GET: lookup only

        if ($family === null) {
            return $petId !== null
                ? response()->json(['message' => 'Pet not found in your family.'], 404)
                : response()->json($empty, 200);
        }

        $pet = $this->dashboard->targetPet($family, $petId);

        if ($pet === null || ($petId === null && ! $pet->is_active)) {
            if ($petId !== null && $pet === null) {
                return response()->json(['message' => 'Pet not found in your family.'], 404);
            }

            return response()->json($empty, 200);
        }

        $perPage = (int) $request->query('per_page', 20);
        $paginated = $pet->activities()
            // M3-11: a free pet's history is limited to the last 7 family-local days.
            ->when($pet->isFreePlan(), fn ($q) => $q->where('created_at', '>=', FamilyDashboardService::historyStart($pet)))
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
     * POST /api/parent/hard-stop {pet_id?, active?}
     * Sets (`active` given — idempotent, M2-05 review) or toggles (no
     * `active` — deprecated, old app builds) the hard stop of one active pet
     * of the family (default: the legacy pet). Any parent of the family may
     * do it. Response `changed: false` = already in that state, no broadcast.
     */
    public function toggleHardStop(HardStopRequest $request): JsonResponse
    {
        $parent = $request->user();

        if (! $parent->can('manageFamily', User::class)) {
            return response()->json(['message' => 'Only parent profiles can trigger hard stop.'], 403);
        }

        $family = $this->families->familyOf($parent);

        if ($family === null || ! $this->hasChildren($family)) {
            return response()->json(['message' => 'No child profile paired.'], 404);
        }

        $pet = $this->dashboard->targetPet($family, $request->input('pet_id'));

        if (! $pet || ! $pet->is_active || ! $parent->can('manage', $pet)) {
            return response()->json(['message' => 'No active pet session found.'], 404);
        }

        // Row lock + status period + one broadcast after commit (HardStopService).
        $result = $this->hardStops->set($pet->id, $request->desiredState());

        if ($result === null) {
            return response()->json(['message' => 'No active pet session found.'], 404);
        }

        $stopped = (bool) $result['pet']->is_hard_stopped;
        $petId = (int) $result['pet']->id;
        $changed = (bool) $result['changed'];

        return response()->json([
            'message' => $stopped
                ? 'Hard stop activated. Child app locked.'
                : 'Hard stop deactivated. Child app unlocked.',
            'pet_id' => $petId,
            'is_hard_stopped' => $stopped,
            // false = the pet already was in the requested state (nothing written, no broadcast).
            'changed' => $changed,
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
                // Ignored warnings and M5-R02 behaviour events (accident, chewing) are negative.
                'is_positive' => ! $log->activity_type->isNegativeEvent(),
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
            // false = QuietHours::DEFAULTS apply, no stored row yet.
            'saved' => (bool) $quietHours->exists,
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
