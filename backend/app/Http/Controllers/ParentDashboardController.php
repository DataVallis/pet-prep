<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\QuietHours;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParentDashboardController extends Controller
{
    /**
     * GET /api/parent/dashboard
     * Returns combined state: pet metrics, traffic light status, quiet hours, recent activities.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $parent = $request->user();

        if (! $parent->isParent()) {
            return response()->json(['message' => 'Only parent profiles can access the dashboard.'], 403);
        }

        // Get the child's pet (MVP: 1 parent → 1 child → 1 pet)
        $child = $parent->children()->first();

        if (! $child) {
            return response()->json([
                'message' => 'No child profile paired yet.',
                'pet' => null,
                'traffic_light' => 'green',
                'quiet_hours' => null,
                'recent_activities' => [],
            ], 200);
        }

        $pet = $child->activePet();

        if (! $pet) {
            // Check for a game-over pet (is_active=false but is_game_over=true)
            $pet = $child->pet()->where('is_game_over', true)->first();

            if (! $pet) {
                return response()->json([
                    'message' => 'No active pet session found.',
                    'pet' => null,
                    'traffic_light' => 'green',
                    'quiet_hours' => $parent->quietHours,
                    'recent_activities' => [],
                ], 200);
            }
        }

        // Calculate traffic light status
        $trafficLight = $this->calculateTrafficLight($pet);

        // Get recent activities (last 20)
        $recentActivities = $pet->activities()
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'activity_type' => $log->activity_type->value,
                'value' => $log->value,
                'created_at' => $log->created_at?->toIso8601String(),
                'is_positive' => $log->activity_type !== ActivityType::IgnoredWarning,
            ]);

        // Get weekly performance data (last 7 days)
        $weeklyPerformance = $this->getWeeklyPerformance($pet);

        return response()->json([
            'pet' => [
                'id' => $pet->id,
                'breed_type' => $pet->breed_type->value,
                'hunger_level' => $pet->hunger_level,
                'thirst_level' => $pet->thirst_level,
                'energy_level' => $pet->energy_level,
                'hygiene_level' => $pet->hygiene_level,
                'pet_state' => $pet->pet_state->value,
                'is_active' => $pet->is_active,
                'is_ill' => $pet->isIll(),
                'is_game_over' => $pet->is_game_over,
                'is_hard_stopped' => $pet->is_hard_stopped,
                'escalation_level' => $pet->escalation_level,
                'virtual_age_months' => $pet->virtualAgeInMonths(),
                'current_video_url' => $pet->current_video_url,
            ],
            'child' => [
                'id' => $child->id,
                'name' => $child->name,
            ],
            'traffic_light' => $trafficLight,
            'quiet_hours' => $parent->quietHours ? [
                'school_start' => $parent->quietHours->school_start,
                'school_end' => $parent->quietHours->school_end,
                'bedtime_start' => $parent->quietHours->bedtime_start,
                'bedtime_end' => $parent->quietHours->bedtime_end,
                'is_active' => $parent->quietHours->is_active,
            ] : null,
            'recent_activities' => $recentActivities,
            'weekly_performance' => $weeklyPerformance,
        ], 200);
    }

    /**
     * GET /api/parent/activities
     * Paginated activity logs for the timeline.
     */
    public function activities(Request $request): JsonResponse
    {
        $parent = $request->user();

        if (! $parent->isParent()) {
            return response()->json(['message' => 'Only parent profiles can access activities.'], 403);
        }

        $child = $parent->children()->first();

        if (! $child) {
            return response()->json(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]], 200);
        }

        $pet = $child->activePet();

        if (! $pet) {
            return response()->json(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]], 200);
        }

        $perPage = (int) $request->query('per_page', 20);
        $paginated = $pet->activities()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'activity_type' => $log->activity_type->value,
                'value' => $log->value,
                'created_at' => $log->created_at?->toIso8601String(),
                'is_positive' => $log->activity_type !== ActivityType::IgnoredWarning,
            ]),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
            ],
        ], 200);
    }

    /**
     * POST /api/parent/hard-stop
     * Toggles hard stop state and broadcasts PetUpdated event.
     */
    public function toggleHardStop(Request $request): JsonResponse
    {
        $parent = $request->user();

        if (! $parent->isParent()) {
            return response()->json(['message' => 'Only parent profiles can trigger hard stop.'], 403);
        }

        $child = $parent->children()->first();

        if (! $child) {
            return response()->json(['message' => 'No child profile paired.'], 404);
        }

        $pet = $child->activePet();

        if (! $pet) {
            return response()->json(['message' => 'No active pet session found.'], 404);
        }

        // Toggle hard stop
        $pet->update([
            'is_hard_stopped' => ! $pet->is_hard_stopped,
        ]);

        // Broadcast the update to parent and child via Reverb
        $eventType = $pet->is_hard_stopped ? 'hard_stop_activated' : 'hard_stop_deactivated';
        broadcast(new PetUpdated($pet->fresh(), $eventType));

        return response()->json([
            'message' => $pet->is_hard_stopped
                ? 'Hard stop activated. Child app locked.'
                : 'Hard stop deactivated. Child app unlocked.',
            'is_hard_stopped' => $pet->fresh()->is_hard_stopped,
        ], 200);
    }

    // ──────────────────────────────────────────────────────────────
    //  Traffic Light Calculation
    // ──────────────────────────────────────────────────────────────

    /**
     * Calculate the traffic light status for the dashboard banner.
     * Green: All routines met consistently.
     * Amber: Missed >2 tasks today (escalation level 1-2).
     * Red: Critical neglect / alert active (escalation level 3 or game over).
     */
    private function calculateTrafficLight(Pet $pet): string
    {
        if ($pet->is_game_over || $pet->escalation_level >= 3 || $pet->isIll()) {
            return 'red';
        }

        if ($pet->escalation_level >= 1) {
            return 'amber';
        }

        return 'green';
    }

    /**
     * Get weekly performance data — daily routine completion counts
     * for the last 7 days.
     *
     * @return array<int, array{date: string, completed: int, missed: int}>
     */
    private function getWeeklyPerformance(Pet $pet): array
    {
        $performance = [];
        $positiveActivities = [
            ActivityType::FedPet->value,
            ActivityType::WateredPet->value,
            ActivityType::WalkedPet->value,
            ActivityType::CleanedPoop->value,
        ];

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->startOfDay();
            $nextDate = (clone $date)->endOfDay();

            $completed = ActivityLog::where('pet_id', $pet->id)
                ->whereIn('activity_type', $positiveActivities)
                ->whereBetween('created_at', [$date, $nextDate])
                ->count();

            $missed = ActivityLog::where('pet_id', $pet->id)
                ->where('activity_type', ActivityType::IgnoredWarning->value)
                ->whereBetween('created_at', [$date, $nextDate])
                ->count();

            $performance[] = [
                'date' => $date->toDateString(),
                'completed' => $completed,
                'missed' => $missed,
            ];
        }

        return $performance;
    }
}
