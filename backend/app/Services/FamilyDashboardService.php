<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\PetStatusPeriodKind;
use App\Enums\RoutineType;
use App\Models\ActivityLog;
use App\Models\Family;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\PetContract;
use App\Models\PetDailyStep;
use App\Models\PetStatusPeriod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Parent dashboard data for a whole family (M2-01, ADR-012).
 *
 * Per-child stats are computed on read from `activities_log.actor_user_id`
 * and `pet_daily_steps` — every action on a shared pet counts for the child
 * who did it. Routines, Care Score, traffic light and 12-week progress per
 * child and per pet (M2-06, David 2026-10-04) come from CareScoreService on
 * top of the routine ledger (RoutineLedgerService).
 */
class FamilyDashboardService
{
    public const STATS_DAYS = 7;

    /** Timeline items per pet in the dashboard. */
    public const TIMELINE_ITEMS = 20;

    public function __construct(private readonly CareScoreService $scores) {}

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

        // Callers validate (422); arrays or objects never reach ctype_digit.
        if (! is_scalar($petId) || ! ctype_digit((string) $petId)) {
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
        $contracts = PetContract::whereIn('pet_id', $pets->pluck('id'))->get(['pet_id', 'user_id', 'signed_at']);
        $children = $family->children()->get(['users.id', 'users.name', 'users.birth_year', DB::raw('(users.password IS NULL) AS pin_only')]);
        // Signed-in devices per child (M2-02) = Sanctum tokens.
        $devices = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $children->pluck('id'))
            ->selectRaw('tokenable_id, count(*) as n')
            ->groupBy('tokenable_id')
            ->pluck('n', 'tokenable_id');
        $parents = $family->parents()->get(['users.id', 'users.name']);

        // Routines, Care Score, traffic light (M2-06): one board for the family.
        $board = $this->scores->board($pets, $tz, $caretakers, $contracts);
        $timelines = $this->timelines($pets, $children->pluck('name', 'id')->all());

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
            'children' => $children->map(function (User $child) use ($caretakers, $pets, $actionCounts, $steps, $contracts, $devices, $board) {
                $petIds = $caretakers->where('user_id', $child->id)->pluck('pet_id');
                $current = $pets->whereIn('id', $petIds)->sortByDesc(fn (Pet $p) => [(int) $p->is_active, $p->id])->first();
                $counts = $this->countsByType($actionCounts->get($child->id, collect()));
                $stepRow = $steps->get($child->id);

                return [
                    'id' => $child->id,
                    'name' => $child->name,
                    'birth_year' => $child->birth_year,
                    // M2-02: `pin` = profile without e-mail / password;
                    // `email` = legacy child account (deprecated).
                    'login' => $child->getAttribute('pin_only') ? 'pin' : 'email',
                    'devices' => (int) ($devices[$child->id] ?? 0),
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
                    // M2-06: traffic_light {color, reasons}, care_score,
                    // today, last_7_days, progress (12-week challenge).
                    ...$this->scores->childSummary($board, $child->id, $current),
                ];
            })->values()->all(),
            'pets' => $pets->map(fn (Pet $pet) => array_merge([
                'id' => $pet->id,
                'breed_type' => $pet->breed_type->value,
                'born_at' => $pet->born_at?->toIso8601String(),
                'awaiting_contract' => $pet->isUnborn(),
                'is_active' => (bool) $pet->is_active,
                'is_game_over' => (bool) $pet->is_game_over,
                'is_hard_stopped' => (bool) $pet->is_hard_stopped,
                'is_ill' => $pet->isIll(),
                'escalation_level' => (int) $pet->escalation_level,
                'caretakers' => $caretakers->where('pet_id', $pet->id)->map(fn (PetCaretaker $c) => [
                    'child_id' => $c->user_id,
                    'contract_signed' => $contracts->where('pet_id', $pet->id)->where('user_id', $c->user_id)->isNotEmpty(),
                ])->values()->all(),
                // Displayed metrics (same rounding the child sees).
                'metrics' => [
                    'hunger' => $pet->displayMetric('hunger_level'),
                    'thirst' => $pet->displayMetric('thirst_level'),
                    'energy' => $pet->displayMetric('energy_level'),
                    'hygiene' => $pet->displayMetric('hygiene_level'),
                ],
                'timeline' => $timelines[$pet->id] ?? [],
            ], $this->scores->petSummary($board, $pet)))->values()->all(),
        ];
    }

    /**
     * Detail report of one child of the family over the last $days
     * family-local days (7, 30 or 84 — the whole 12-week challenge):
     * all-time Care Score, the score of the period, traffic light, 12-week
     * progress, totals per routine type, one row per day, the missed
     * routines and the pet's illnesses in the period.
     *
     * @return array<string, mixed>
     */
    public function childReport(Family $family, User $child, int $days): array
    {
        $tz = $family->timezone;
        $caretakers = PetCaretaker::where('user_id', $child->id)->orderBy('id')->get();
        $pet = Pet::where('family_id', $family->id)
            ->whereIn('id', $caretakers->pluck('pet_id'))
            ->get()
            ->sortByDesc(fn (Pet $p) => [(int) $p->is_active, $p->id])
            ->first();

        $today = now()->setTimezone($tz)->toDateString();
        $from = Carbon::parse($today, 'UTC')->subDays($days - 1)->toDateString();

        $report = [
            'child' => ['id' => $child->id, 'name' => $child->name],
            'pet_id' => $pet?->id,
            'timezone' => $tz,
            'days' => $days,
            'from' => $from,
            'to' => $today,
        ];

        $pets = $pet !== null ? collect([$pet]) : collect();
        $board = $this->scores->board($pets, $tz);
        $summary = $this->scores->childSummary($board, $child->id, $pet);

        $byType = [];
        foreach (RoutineType::cases() as $type) {
            $byType[$type->value] = ['expected' => 0, 'done' => 0, 'done_by_child' => 0, 'missed' => 0, 'pending' => 0];
        }
        $missed = [];
        if ($pet !== null) {
            foreach ($board['routines'][$pet->id] ?? [] as $r) {
                if ($r->localDate < $from) {
                    continue;
                }
                $n = $this->scores->sharers($board, $pet, $r, $child->id);
                if ($n === 0) {
                    continue;
                }
                $t = &$byType[$r->type->value];
                $t['expected']++;
                if ($r->isDone()) {
                    $t['done']++;
                    $t['done_by_child'] += $this->scores->credited($board, $pet, $r, $child->id, $n) ? 1 : 0;
                } elseif ($r->isPending()) {
                    $t['pending']++;
                } else {
                    $t['missed']++;
                    $missed[] = $this->scores->missedItem($board, $r);
                }
                unset($t);
            }
        }

        $daily = [];
        for ($d = Carbon::parse($from, 'UTC'); $d->toDateString() <= $today; $d->addDay()) {
            $daily[] = $this->scores->dailyRow($board, $child->id, $pet, $d->toDateString());
        }

        $illnesses = $pet === null ? [] : PetStatusPeriod::where('pet_id', $pet->id)
            ->where('kind', PetStatusPeriodKind::Illness->value)
            ->where('started_at', '>=', Carbon::parse($from, $tz)->startOfDay()->utc())
            ->orderBy('started_at')
            ->get()
            ->map(fn (PetStatusPeriod $p) => [
                'started_at' => $p->started_at->copy()->setTimezone($tz)->toIso8601String(),
                'ended_at' => $p->ended_at?->copy()->setTimezone($tz)->toIso8601String(),
            ])->values()->all();

        return $report + [
            'traffic_light' => $summary['traffic_light'],
            'care_score' => $summary['care_score'],
            'period_score' => $this->scores->childScore($board, $child->id, $pet, $from),
            'progress' => $summary['progress'],
            'by_type' => $byType,
            'daily' => $daily,
            'missed' => array_reverse($missed),
            'illnesses' => $illnesses,
        ];
    }

    /**
     * The last activities of each pet (one query), newest first, with the
     * acting child's nickname (null for system rows / unknown actors).
     *
     * @param  Collection<int, Pet>  $pets
     * @param  array<int, string>  $nicknames  child id → nickname (this family only)
     * @return array<int, list<array<string, mixed>>>
     */
    public function timelines(Collection $pets, array $nicknames, int $limit = self::TIMELINE_ITEMS): array
    {
        if ($pets->isEmpty()) {
            return [];
        }

        $ranked = DB::table('activities_log')
            ->select(['id', 'pet_id', 'actor_user_id', 'activity_type', 'value', 'created_at'])
            ->selectRaw('row_number() over (partition by pet_id order by created_at desc, id desc) as rn')
            ->whereIn('pet_id', $pets->pluck('id'));

        $rows = DB::query()->fromSub($ranked, 't')
            ->where('rn', '<=', $limit)
            ->orderBy('pet_id')
            ->orderBy('rn')
            ->get();

        $tz = $pets->first()->familyTimezone();
        $out = [];
        foreach ($rows as $row) {
            $actor = $row->actor_user_id !== null ? (int) $row->actor_user_id : null;
            $out[(int) $row->pet_id][] = [
                'id' => (int) $row->id,
                'activity_type' => $row->activity_type,
                'value' => $row->value !== null ? (int) $row->value : null,
                'actor_user_id' => $actor,
                'actor_nickname' => $actor !== null ? ($nicknames[$actor] ?? null) : null,
                'created_at' => Carbon::parse($row->created_at, 'UTC')->setTimezone($tz)->toIso8601String(),
                'is_positive' => $row->activity_type !== ActivityType::IgnoredWarning->value,
            ];
        }

        return $out;
    }

    /**
     * Legacy pet-level traffic light (top-level `traffic_light` of the
     * dashboard, old app builds — deprecated since M2-06): red = game over,
     * phase 3 or ill; amber = escalation 1–2; green otherwise. The spec
     * light (PRODUCT_SPEC §9) is CareScoreService::petLight / childLight.
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
