<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Family;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\PetContract;
use App\Models\PetDailyStep;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Parent dashboard data for a whole family (M2-01, ADR-012).
 *
 * Per-child stats are computed on read from `activities_log.actor_user_id`
 * and `pet_daily_steps` — every action on a shared pet counts for the child
 * who did it. The per-child score / traffic light / certificate formulas
 * are not specified yet (pending David); these are the raw counts they will
 * be built on.
 */
class FamilyDashboardService
{
    public const STATS_DAYS = 7;

    private const CHILD_ACTIONS = [
        ActivityType::FedPet,
        ActivityType::WateredPet,
        ActivityType::CleanedPoop,
        ActivityType::WalkedPet,
    ];

    /**
     * The pet the legacy single-pet dashboard fields describe (old app
     * builds): the family's oldest active pet, else its latest game-over pet.
     */
    public function legacyPet(Family $family): ?Pet
    {
        return Pet::where('family_id', $family->id)->where('is_active', true)->orderBy('id')->first()
            ?? Pet::where('family_id', $family->id)->where('is_game_over', true)->orderByDesc('id')->first();
    }

    /**
     * The pet a parent's request targets: `pet_id` when given (must belong
     * to the family, else null → 404), otherwise the legacy pet.
     */
    public function targetPet(Family $family, mixed $petId): ?Pet
    {
        if ($petId === null || $petId === '') {
            return $this->legacyPet($family);
        }

        if (! ctype_digit((string) $petId)) {
            return null;
        }

        return Pet::where('family_id', $family->id)->find((int) $petId);
    }

    /**
     * @return array<string, mixed>
     */
    public function family(Family $family, User $viewer): array
    {
        $tz = $family->timezone;
        $pets = Pet::where('family_id', $family->id)->orderBy('id')->get();
        $caretakers = PetCaretaker::whereIn('pet_id', $pets->pluck('id'))->orderBy('id')->get();
        $contracts = PetContract::whereIn('pet_id', $pets->pluck('id'))->get(['pet_id', 'user_id']);
        $children = $family->children()->get(['users.id', 'users.name']);
        $parents = $family->parents()->get(['users.id', 'users.name']);

        $from = now()->setTimezone($tz)->startOfDay()->subDays(self::STATS_DAYS - 1);
        $fromUtc = $from->copy()->utc();

        $actionCounts = ActivityLog::query()
            ->whereIn('actor_user_id', $children->pluck('id'))
            ->whereIn('pet_id', $pets->pluck('id'))
            ->where('created_at', '>=', $fromUtc)
            ->selectRaw('actor_user_id, activity_type, count(*) as n')
            ->groupBy('actor_user_id', 'activity_type')
            ->get()
            ->groupBy('actor_user_id');

        $steps = PetDailyStep::query()
            ->whereIn('user_id', $children->pluck('id'))
            ->whereIn('pet_id', $pets->pluck('id'))
            ->where('local_date', '>=', $from->toDateString())
            ->selectRaw('user_id, sum(steps) as total, count(*) filter (where steps > 0) as days')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        return [
            'id' => $family->id,
            'timezone' => $tz,
            'parents' => $parents->map(fn (User $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'is_me' => $p->id === $viewer->id,
            ])->values()->all(),
            'children' => $children->map(function (User $child) use ($caretakers, $pets, $actionCounts, $steps, $contracts) {
                $petIds = $caretakers->where('user_id', $child->id)->pluck('pet_id');
                $current = $pets->whereIn('id', $petIds)->sortByDesc(fn (Pet $p) => [(int) $p->is_active, $p->id])->first();
                $counts = $this->countsByType($actionCounts->get($child->id, collect()));
                $stepRow = $steps->get($child->id);

                return [
                    'id' => $child->id,
                    'name' => $child->name,
                    'pet_id' => $current?->id,
                    'contract_signed' => $current !== null
                        && $contracts->where('pet_id', $current->id)->where('user_id', $child->id)->isNotEmpty(),
                    // Last 7 family-local days, only this child's own actions.
                    'stats' => [
                        'days' => self::STATS_DAYS,
                        'fed' => $counts[ActivityType::FedPet->value],
                        'watered' => $counts[ActivityType::WateredPet->value],
                        'cleaned' => $counts[ActivityType::CleanedPoop->value],
                        // Days on which this child's sync completed the walk goal.
                        'walk_goals' => $counts[ActivityType::WalkedPet->value],
                        'actions_total' => array_sum($counts),
                        'steps' => (int) ($stepRow->total ?? 0),
                        'active_step_days' => (int) ($stepRow->days ?? 0),
                    ],
                ];
            })->values()->all(),
            'pets' => $pets->map(fn (Pet $pet) => [
                'id' => $pet->id,
                'breed_type' => $pet->breed_type->value,
                'born_at' => $pet->born_at?->toIso8601String(),
                'awaiting_contract' => $pet->isUnborn(),
                'is_active' => (bool) $pet->is_active,
                'is_game_over' => (bool) $pet->is_game_over,
                'is_hard_stopped' => (bool) $pet->is_hard_stopped,
                'is_ill' => $pet->isIll(),
                'traffic_light' => self::trafficLight($pet),
                'caretakers' => $caretakers->where('pet_id', $pet->id)->map(fn (PetCaretaker $c) => [
                    'child_id' => $c->user_id,
                    'contract_signed' => $contracts->where('pet_id', $pet->id)->where('user_id', $c->user_id)->isNotEmpty(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Pet-level traffic light (dashboard banner): red = game over, phase 3
     * or ill; amber = escalation 1–2; green otherwise.
     */
    public static function trafficLight(Pet $pet): string
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
     * @param  Collection<int, ActivityLog>  $rows
     * @return array<string, int>
     */
    private function countsByType(Collection $rows): array
    {
        $counts = [];
        foreach (self::CHILD_ACTIONS as $type) {
            $row = $rows->first(fn ($r) => ($r->activity_type instanceof ActivityType ? $r->activity_type->value : $r->activity_type) === $type->value);
            $counts[$type->value] = (int) ($row->n ?? 0);
        }

        return $counts;
    }
}
