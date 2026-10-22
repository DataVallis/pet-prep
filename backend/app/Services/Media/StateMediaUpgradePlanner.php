<?php

namespace App\Services\Media;

use App\Enums\BreedType;
use App\Enums\ChallengePaidSource;
use App\Enums\PetPlan;
use App\Enums\Species;
use App\Jobs\UpgradePetsToStateMedia;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\FalAiService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * M5-T16 (David 2026-10-22): existing paid pets do NOT get the new media (key images,
 * loops, transitions, school / game phase clips — state media version 3) automatically.
 * The superadmin first sees a calculation (preview, no spend), then decides and upgrades
 * with a click (/admin → AI media → »Upgrade existing pets to new media«, or
 * `media:backfill --state-media [--dry-run]` on the console — same preview, same upgrade).
 *
 * Eligible = active, born, paid full set (challenge paid by a purchase), profiled,
 * `state_media_version` < 3, not game over. Grouped per shared look + life stage
 * (breed + colour + stage): a look item is paid once, however many pets show it
 * (PetMediaService::lookMissingItems — the claims make one fal call per look row);
 * pets with unique media (app_settings.unique_pet_media) are priced per pet.
 * Prices = the model profiles' estimates (images 0.15 $, Kling 0.112 $/s × clip length).
 *
 * The upgrade itself only moves the pets to version 3 and queues their pipeline through
 * PetMediaService::generateMissing(upgradeStateMedia: true) in queued chunk jobs
 * (UpgradePetsToStateMedia) — every fal call still goes through FalGateway + AiSpendGuard
 * (a refused item is retried by the nightly media:retry) and both superadmin switches.
 *
 * QA round (#344): the confirmed set + total are frozen and re-checked on »Upgrade«
 * (never more pets / money than shown), stage-behind pets are priced with the full set of
 * their new stage, the console real run uses this planner too, chunks catch per pet.
 */
class StateMediaUpgradePlanner
{
    /** app_setting_changes key of the audit row of one bulk upgrade (who, when, what). */
    public const AUDIT_KEY = 'state_media_bulk_upgrade';

    /** Pets per queued UpgradePetsToStateMedia job. */
    public const CHUNK = 25;

    /** QA m6: lock against a double submission of »Upgrade« (seconds). */
    public const LOCK_KEY = 'state-media-bulk-upgrade';

    public const LOCK_SECONDS = 30;

    /** Days estimate horizon (longer = »never« in practice). */
    private const MAX_DAYS = 731;

    /** Plan categories shown per look (planMissing list → label key). */
    public const CATEGORIES = [
        PetMedia::KIND_IMAGE => 'image',
        PetMedia::KIND_KEYFRAME => 'keyframes',
        PetMedia::KIND_VIDEO => 'loops',
        PetMedia::KIND_TRANSITION => 'transitions',
        PetMedia::KIND_POSE => 'poses',
        PetMedia::KIND_PHASE => 'phases',
    ];

    public function __construct(
        private readonly PetMediaService $media,
        private readonly MediaEntitlementService $entitlements,
        private readonly AiSpendGuard $guard,
        private readonly FalAiService $fal,
    ) {}

    /**
     * Pets that may be upgraded (before the switches / profiles are looked at).
     *
     * @return Builder<Pet>
     */
    public function eligibleQuery(?Species $species = null, ?BreedType $breed = null): Builder
    {
        return Pet::query()
            ->where('is_active', true)
            ->whereNotNull('born_at')
            ->where('is_game_over', false)
            ->whereNotNull('arrival_age_months')
            ->where('plan', PetPlan::Challenge->value)
            ->where('challenge_paid_source', ChallengePaidSource::Purchase->value)
            ->where(fn (Builder $q) => $q->whereNull('state_media_version')
                ->orWhere('state_media_version', '<', MediaEntitlementService::CURRENT_MEDIA_VERSION))
            ->when($species !== null, fn (Builder $q) => $q->where('species', $species->value))
            ->when($breed !== null, fn (Builder $q) => $q->where('breed_type', $breed->value));
    }

    /**
     * The calculation — reads only, never calls fal and never writes.
     *
     * QA M2: a pet whose image is behind its life stage is priced with the full set of its
     * new stage (the stage image re-queues all its clips) and counted in `stage_remake`.
     *
     * @return array{
     *     groups: list<array{key: string, look_id: int|null, pet_id: int|null, breed: string, species: string, colour: string|null, stage: string|null, unique: bool, pets: int, pet_ids: list<int>, stage_remake: int, items: array<string, int>, slots: int, cost_usd: float}>,
     *     eligible: int, pets: int, pet_ids: list<int>, looks: int, complete_looks: int, cost_usd: float, blocked: string|null,
     *     phase_clips: bool, budget: array{daily_cap_usd: float, monthly_cap_usd: float, left_today_usd: float, left_month_usd: float, today_usd: float, today_share_percent: int|null, days: int|null, fits_today: bool, over_month: bool, timezone: string}
     * }
     */
    public function preview(?Species $species = null, ?BreedType $breed = null, bool $onlyComplete = false, ?int $petId = null): array
    {
        $blocked = $this->blockedReason();
        /** @var array<string, array{pets: list<Pet>, plans: list<array<string, mixed>>}> $buckets */
        $buckets = [];
        $eligible = 0;

        $this->eligibleQuery($species, $breed)
            ->when($petId !== null, fn (Builder $q) => $q->whereKey($petId))
            ->with(['look', 'media']) // QA m7: planMissing reads the eager-loaded media
            ->orderBy('id')
            ->get()
            ->each(function (Pet $pet) use (&$buckets, &$eligible): void {
                $eligible++;
                $plan = $this->media->planMissing($pet, upgradeStateMedia: true, stageRemake: true);

                if (! $plan['upgrade']) {
                    return; // switch off / no key image profile: nothing this upgrade would make
                }

                $key = $pet->usesLookPool() ? "look:{$pet->pet_look_id}:".($pet->life_stage?->value ?? '-') : "pet:{$pet->id}";
                $buckets[$key]['pets'][] = $pet;
                $buckets[$key]['plans'][] = $plan;
            });

        $groups = [];
        foreach ($buckets as $key => $bucket) {
            $groups[] = $this->group($key, $bucket['pets'], $bucket['plans']);
        }

        if ($onlyComplete) {
            $groups = array_values(array_filter($groups, fn (array $g): bool => $g['cost_usd'] <= 0.0));
        }

        usort($groups, fn (array $a, array $b): int => [$a['breed'], (string) $a['colour'], (string) $a['stage'], $a['key']] <=> [$b['breed'], (string) $b['colour'], (string) $b['stage'], $b['key']]);

        $cost = round(array_sum(array_column($groups, 'cost_usd')), 2);
        $petIds = array_merge([], ...array_column($groups, 'pet_ids'));
        sort($petIds);

        return [
            'groups' => $groups,
            'eligible' => $eligible,
            'pets' => count($petIds),
            'pet_ids' => $petIds,
            'looks' => count($groups),
            'complete_looks' => count(array_filter($groups, fn (array $g): bool => $g['cost_usd'] <= 0.0)),
            'cost_usd' => $cost,
            'blocked' => $blocked,
            'phase_clips' => $this->entitlements->phaseClipsEnabled(),
            'budget' => $this->budgetFor($cost, (int) array_sum(array_column($groups, 'slots'))),
        ];
    }

    /**
     * Why an upgrade would generate nothing right now (null = it can run).
     *
     * @return 'state_media_off'|'no_keyframe_profile'|'fal_disabled'|'fal_balance'|null
     */
    public function blockedReason(bool $fresh = false): ?string
    {
        return match (true) {
            ! $this->entitlements->stateMediaEnabled($fresh) => 'state_media_off',
            app(MediaProfiles::class)->keyframe() === null => 'no_keyframe_profile',
            ! $this->fal->isEnabled() => 'fal_disabled',
            FalGateway::balanceExhaustedAt() !== null => 'fal_balance', // QA m1
            default => null,
        };
    }

    /**
     * »Upgrade« (admin page confirmation, `media:backfill --state-media`) — spends nothing itself.
     *
     * QA M1: `$shownPetIds` / `$shownUsd` are what the admin confirmed (frozen when the
     * confirmation opened). The calculation is redone now with the same filters; when a pet
     * not in the confirmed set appeared or the total grew, nothing is queued (`changed` —
     * »check again«). Otherwise the fresh set (⊆ the confirmed one) is audited — shown and
     * recomputed numbers — and queued in chunks. QA m6: a short cache lock refuses a second
     * submission while it holds (`busy`); it is kept after a queued run, so a double click
     * cannot queue (and audit) the same pets twice.
     *
     * @param  array{species?: string|null, breed?: string|null, only_complete?: bool, pet?: int|null, source?: string}  $filters
     * @param  list<int>  $shownPetIds
     * @return array{status: 'queued'|'changed'|'blocked'|'nothing'|'busy', pets: int, cost_usd: float, reason: string|null}
     */
    public function upgrade(?User $actor, array $filters, array $shownPetIds, float $shownUsd): array
    {
        $result = fn (string $status, int $pets = 0, float $cost = 0.0, ?string $reason = null): array => ['status' => $status, 'pets' => $pets, 'cost_usd' => round($cost, 2), 'reason' => $reason];
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return $result('busy');
        }

        if (($reason = $this->blockedReason(fresh: true)) !== null) {
            $lock->release();

            return $result('blocked', reason: $reason);
        }

        $onlyComplete = (bool) ($filters['only_complete'] ?? false);
        $fresh = $this->preview(
            Species::tryFrom((string) ($filters['species'] ?? '')),
            BreedType::tryFrom((string) ($filters['breed'] ?? '')),
            $onlyComplete,
            isset($filters['pet']) ? (int) $filters['pet'] : null,
        );
        $shown = array_values(array_unique(array_map('intval', $shownPetIds)));

        if (array_diff($fresh['pet_ids'], $shown) !== [] || $fresh['cost_usd'] > round($shownUsd, 2) + 0.005) {
            $lock->release();
            Log::info('Bulk state media upgrade refused: the numbers changed since the confirmation (M5-T16)', [
                'shown_pets' => count($shown), 'fresh_pets' => $fresh['pets'], 'shown_usd' => round($shownUsd, 2), 'fresh_usd' => $fresh['cost_usd'],
            ]);

            return $result('changed', $fresh['pets'], $fresh['cost_usd']);
        }

        $petIds = array_values(array_intersect($fresh['pet_ids'], $shown));

        if ($petIds === []) {
            $lock->release();

            return $result('nothing');
        }

        $audit = [
            'pets' => count($petIds),
            'estimate_usd' => round($shownUsd, 2),
            'shown_pets' => count($shown),
            'recomputed_pets' => $fresh['pets'],
            'recomputed_usd' => $fresh['cost_usd'],
            'today_share_percent' => $fresh['budget']['today_share_percent'],
            'filters' => $filters,
            'pet_ids' => $petIds,
        ];

        AppSettingChange::create(['key' => self::AUDIT_KEY, 'user_id' => $actor?->id, 'old' => null, 'new' => $audit]);
        Log::info('Bulk state media upgrade queued (M5-T16)', ['user_id' => $actor?->id] + array_diff_key($audit, ['pet_ids' => true]));

        $this->dispatchChunks($petIds, $onlyComplete);

        return $result('queued', count($petIds), $fresh['cost_usd']);
    }

    /**
     * @param  list<int>  $petIds
     */
    public function dispatchChunks(array $petIds, bool $onlyComplete = false): void
    {
        foreach (array_chunk($petIds, self::CHUNK) as $chunk) {
            UpgradePetsToStateMedia::dispatch($chunk, $onlyComplete);
        }
    }

    /**
     * One chunk (queued job): re-check each pet, then the existing single-pet upgrade path.
     * Never inside a DB transaction — generateMissing only queues jobs; FalGateway refuses HTTP
     * inside a transaction anyway. Idempotent (a pet already on version 3 is no longer eligible).
     *
     * QA M1: with `$onlyComplete` a pet whose fresh plan costs anything is skipped.
     * QA m3: a failure is caught per pet and its media version is rolled back, so the pet
     * stays eligible for the next upgrade instead of being stuck half way.
     *
     * @param  list<int>  $petIds
     * @return array{done: int, skipped: int, failed: int}
     */
    public function upgradeChunk(array $petIds, bool $onlyComplete = false): array
    {
        $counts = ['done' => 0, 'skipped' => 0, 'failed' => 0];

        // Both switches are read fresh by the media jobs; the state media switch here too,
        // so an upgrade queued just before it was turned off moves no pet.
        if (($reason = $this->blockedReason(fresh: true)) !== null) {
            $counts['skipped'] = count($petIds);
            Log::info('Bulk state media upgrade chunk skipped (M5-T16)', ['reason' => $reason] + $counts);

            return $counts;
        }

        $pets = $this->eligibleQuery()->whereKey($petIds)->orderBy('id')->get();
        $counts['skipped'] = count($petIds) - $pets->count();

        foreach ($pets as $pet) {
            $plan = $this->media->planMissing($pet, upgradeStateMedia: true, stageRemake: true);

            if (! $plan['upgrade'] || ($onlyComplete && $plan['cost_usd'] > 0.0)) {
                $counts['skipped']++;

                continue;
            }

            $version = $pet->state_media_version;

            try {
                $this->media->generateMissing($pet, upgradeStateMedia: true);
                $counts['done']++;
            } catch (Throwable $e) {
                $counts['failed']++;
                Pet::query()->whereKey($pet->id)->update(['state_media_version' => $version]);
                Log::warning('Bulk state media upgrade: queueing a pet failed — media version rolled back (M5-T16)', [
                    'pet_id' => $pet->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Bulk state media upgrade chunk done (M5-T16)', $counts + ['pets' => count($petIds)]);

        return $counts;
    }

    /**
     * @param  list<Pet>  $pets
     * @param  list<array<string, mixed>>  $plans
     * @return array{key: string, look_id: int|null, pet_id: int|null, breed: string, species: string, colour: string|null, stage: string|null, unique: bool, pets: int, pet_ids: list<int>, stage_remake: int, items: array<string, int>, slots: int, cost_usd: float}
     */
    private function group(string $key, array $pets, array $plans): array
    {
        $first = $pets[0];
        $unique = ! $first->usesLookPool();
        $items = array_fill_keys(array_values(self::CATEGORIES), 0);

        if ($unique) {
            $plan = $plans[0];
            // `download` stores the existing fal image on our disk — free, not a paid image.
            $items['image'] = in_array($plan['image'], ['generate', 'stage'], true) ? 1 : 0;
            $items['keyframes'] = count($plan['keyframes']);
            $items['loops'] = count($plan['videos']);
            $items['transitions'] = count($plan['transitions']);
            $items['poses'] = count($plan['poses']);
            $items['phases'] = count($plan['phases']);
            $cost = (float) $plan['cost_usd'];
            $slots = array_sum($items);
        } else {
            // Union of what the pets of the look need (training on / off, behaviour events
            // differ per pet), then only what the look does not have yet — each item once.
            $union = ['image' => 'ok', 'videos' => [], 'keyframes' => [], 'transitions' => [], 'poses' => [], 'phases' => [], 'upgrade' => true];
            foreach ($plans as $plan) {
                if ($union['image'] === 'ok' && $plan['image'] !== 'ok') {
                    $union['image'] = $plan['image'];
                }
                foreach (['videos', 'keyframes', 'transitions', 'poses', 'phases'] as $list) {
                    $union[$list] = array_values(array_unique([...$union[$list], ...$plan[$list]]));
                }
            }

            $missing = $this->media->lookMissingItems($first, $union);
            foreach ($missing as $item) {
                $items[self::CATEGORIES[$item['kind']]]++;
            }
            $cost = array_sum(array_column($missing, 'cost_usd'));
            // every pet of the look waits on (and media:retry re-queues) its own slot per item
            $slots = count($pets) * count(array_filter($missing, fn (array $item): bool => $item['cost_usd'] > 0.0));
        }

        return [
            'key' => $key,
            'look_id' => $first->pet_look_id,
            'pet_id' => $unique ? $first->id : null,
            'breed' => $first->breed_type->value,
            'species' => $first->speciesValue()->value,
            'colour' => $first->look?->colour_key ?? ($first->pet_dna['colour_key'] ?? null),
            'stage' => $first->life_stage?->value,
            'unique' => $unique,
            'pets' => count($pets),
            'pet_ids' => array_map(fn (Pet $p): int => $p->id, $pets),
            'stage_remake' => count(array_filter($plans, fn (array $plan): bool => $plan['image'] === 'stage')),
            'items' => $items,
            'slots' => (int) $slots,
            'cost_usd' => round($cost, 4),
        ];
    }

    /**
     * The total against what the production budget has left (David 2026-10-22: a bulk
     * upgrade may use the whole daily budget — no reserve for new buyers), the share of
     * today's remaining budget it takes, and how many budget days it would need.
     *
     * QA m5 — days: today counts only when money is left today; every later day spends at
     * most the daily cap, what is left of the month (the monthly cap resets on the 1st, in
     * the budget timezone) and what one nightly media:retry re-queues
     * (ReferenceImageRetryService::DEFAULT_LIMIT pet slots, `$slots` = the slots of the
     * upgrade). null = never (0 $ cap) or more than two years.
     *
     * @return array{daily_cap_usd: float, monthly_cap_usd: float, left_today_usd: float, left_month_usd: float, today_usd: float, today_share_percent: int|null, days: int|null, fits_today: bool, over_month: bool, timezone: string}
     */
    public function budgetFor(float $costUsd, int $slots = 0): array
    {
        $daily = $this->guard->dailyCapUsd();
        $monthly = $this->guard->monthlyCapUsd();
        $leftMonth = max(0.0, $monthly - $this->guard->spentThisMonthUsd());
        $leftToday = min(max(0.0, $daily - $this->guard->spentTodayUsd()), $leftMonth);
        $today = min(max(0.0, $costUsd), $leftToday);

        return [
            'daily_cap_usd' => round($daily, 2),
            'monthly_cap_usd' => round($monthly, 2),
            'left_today_usd' => round($leftToday, 2),
            'left_month_usd' => round($leftMonth, 2),
            'today_usd' => round($today, 2),
            'today_share_percent' => $leftToday > 0.0 ? (int) round(100 * $today / $leftToday) : null,
            'days' => $this->budgetDays($costUsd, $slots, $leftToday, $leftMonth, $daily, $monthly),
            'fits_today' => $costUsd <= $leftToday + 0.00001,
            'over_month' => $costUsd > $leftMonth + 0.00001,
            'timezone' => $this->guard->timezone(),
        ];
    }

    private function budgetDays(float $costUsd, int $slots, float $leftToday, float $leftMonth, float $daily, float $monthly): ?int
    {
        if ($costUsd <= 0.0) {
            return 0;
        }

        $remaining = $costUsd - min($costUsd, $leftToday);
        $days = $leftToday > 0.0 ? 1 : 0;

        if ($remaining <= 0.00001) {
            return $days;
        }

        if ($daily <= 0.0 || $monthly <= 0.0) {
            return null;
        }

        $nightly = $slots > 0 ? ReferenceImageRetryService::DEFAULT_LIMIT * $costUsd / $slots : INF;
        $monthLeft = $leftMonth - min($costUsd, $leftToday);
        $date = CarbonImmutable::now($this->guard->timezone())->startOfDay();

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            $date = $date->addDay();
            if ($date->day === 1) {
                $monthLeft = $monthly;
            }

            $spend = max(0.0, min($remaining, $daily, $monthLeft, $nightly));
            $monthLeft -= $spend;
            $remaining -= $spend;
            $days++;

            if ($remaining <= 0.00001) {
                return $days;
            }
        }

        return null;
    }
}
