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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * M5-T16 (David 2026-10-22): existing paid pets do NOT get the new media (key images,
 * loops, transitions, school / game phase clips — state media version 3) automatically.
 * The superadmin first sees a calculation (preview, no spend), then decides and upgrades
 * with a click (/admin → AI media → »Upgrade existing pets to new media«, or
 * `media:backfill --state-media --dry-run` for the same preview on the console).
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
 */
class StateMediaUpgradePlanner
{
    /** app_setting_changes key of the audit row of one bulk upgrade (who, when, what). */
    public const AUDIT_KEY = 'state_media_bulk_upgrade';

    /** Pets per queued UpgradePetsToStateMedia job. */
    public const CHUNK = 25;

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
     * @return array{
     *     groups: list<array{key: string, look_id: int|null, pet_id: int|null, breed: string, species: string, colour: string|null, stage: string|null, unique: bool, pets: int, pet_ids: list<int>, items: array<string, int>, cost_usd: float}>,
     *     eligible: int, pets: int, looks: int, complete_looks: int, cost_usd: float, blocked: string|null,
     *     phase_clips: bool, budget: array{daily_cap_usd: float, monthly_cap_usd: float, left_today_usd: float, left_month_usd: float, days: int|null, fits_today: bool, over_month: bool, timezone: string}
     * }
     */
    public function preview(?Species $species = null, ?BreedType $breed = null, bool $onlyComplete = false, ?int $petId = null, ?int $limit = null): array
    {
        $blocked = $this->blockedReason();
        /** @var array<string, array{pets: list<Pet>, plans: list<array<string, mixed>>}> $buckets */
        $buckets = [];
        $eligible = 0;

        $this->eligibleQuery($species, $breed)
            ->when($petId !== null, fn (Builder $q) => $q->whereKey($petId))
            ->with('look')
            ->orderBy('id')
            ->when($limit !== null, fn (Builder $q) => $q->limit(max(1, $limit)))
            ->get()
            ->each(function (Pet $pet) use (&$buckets, &$eligible): void {
                $eligible++;
                $plan = $this->media->planMissing($pet, upgradeStateMedia: true);

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

        return [
            'groups' => $groups,
            'eligible' => $eligible,
            'pets' => array_sum(array_column($groups, 'pets')),
            'looks' => count($groups),
            'complete_looks' => count(array_filter($groups, fn (array $g): bool => $g['cost_usd'] <= 0.0)),
            'cost_usd' => $cost,
            'blocked' => $blocked,
            'phase_clips' => $this->entitlements->phaseClipsEnabled(),
            'budget' => $this->budgetFor($cost),
        ];
    }

    /**
     * Why an upgrade would generate nothing right now (null = it can run).
     *
     * @return 'state_media_off'|'no_keyframe_profile'|'fal_disabled'|null
     */
    public function blockedReason(bool $fresh = false): ?string
    {
        return match (true) {
            ! $this->entitlements->stateMediaEnabled($fresh) => 'state_media_off',
            app(MediaProfiles::class)->keyframe() === null => 'no_keyframe_profile',
            ! $this->fal->isEnabled() => 'fal_disabled',
            default => null,
        };
    }

    /**
     * »Upgrade« — audits who ran it and queues the chunk jobs. Spends nothing itself.
     *
     * @param  list<int>  $petIds  the pets of the confirmed preview
     * @param  array<string, mixed>  $filters
     */
    public function upgrade(User $actor, array $petIds, array $filters, float $estimateUsd): int
    {
        $petIds = array_values(array_unique(array_map('intval', $petIds)));

        if ($petIds === [] || $this->blockedReason(fresh: true) !== null) {
            return 0;
        }

        AppSettingChange::create([
            'key' => self::AUDIT_KEY,
            'user_id' => $actor->id,
            'old' => null,
            'new' => ['pets' => count($petIds), 'estimate_usd' => round($estimateUsd, 2), 'filters' => $filters, 'pet_ids' => $petIds],
        ]);

        Log::info('Admin queued the bulk state media upgrade (M5-T16)', [
            'user_id' => $actor->id,
            'pets' => count($petIds),
            'estimate_usd' => round($estimateUsd, 2),
            'filters' => $filters,
        ]);

        foreach (array_chunk($petIds, self::CHUNK) as $chunk) {
            UpgradePetsToStateMedia::dispatch($chunk);
        }

        return count($petIds);
    }

    /**
     * One chunk (queued job): re-check each pet, then the existing single-pet upgrade path.
     * Never inside a DB transaction — generateMissing only queues jobs; FalGateway refuses HTTP
     * inside a transaction anyway.
     *
     * @param  list<int>  $petIds
     */
    public function upgradeChunk(array $petIds): int
    {
        // Both switches are read fresh by the media jobs; the state media switch here too,
        // so an upgrade queued just before it was turned off moves no pet.
        if ($this->blockedReason(fresh: true) !== null) {
            Log::info('Bulk state media upgrade skipped (switch off / fal disabled)', ['pets' => count($petIds)]);

            return 0;
        }

        $done = 0;
        $this->eligibleQuery()->whereKey($petIds)->orderBy('id')->get()
            ->each(function (Pet $pet) use (&$done): void {
                if (! $this->media->planMissing($pet, upgradeStateMedia: true)['upgrade']) {
                    return;
                }
                $this->media->generateMissing($pet, upgradeStateMedia: true);
                $done++;
            });

        return $done;
    }

    /**
     * @param  list<Pet>  $pets
     * @param  list<array<string, mixed>>  $plans
     * @return array{key: string, look_id: int|null, pet_id: int|null, breed: string, species: string, colour: string|null, stage: string|null, unique: bool, pets: int, pet_ids: list<int>, items: array<string, int>, cost_usd: float}
     */
    private function group(string $key, array $pets, array $plans): array
    {
        $first = $pets[0];
        $unique = ! $first->usesLookPool();
        $items = array_fill_keys(array_values(self::CATEGORIES), 0);

        if ($unique) {
            $plan = $plans[0];
            $items['image'] = in_array($plan['image'], ['generate', 'stage', 'download'], true) ? 1 : 0;
            $items['keyframes'] = count($plan['keyframes']);
            $items['loops'] = count($plan['videos']);
            $items['transitions'] = count($plan['transitions']);
            $items['poses'] = count($plan['poses']);
            $items['phases'] = count($plan['phases']);
            $cost = (float) $plan['cost_usd'];
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
            'items' => $items,
            'cost_usd' => round($cost, 4),
        ];
    }

    /**
     * The total against what the production budget has left, and how many budget days
     * it would take at the current daily cap (the rest is refused and retried nightly).
     *
     * @return array{daily_cap_usd: float, monthly_cap_usd: float, left_today_usd: float, left_month_usd: float, days: int|null, fits_today: bool, over_month: bool, timezone: string}
     */
    public function budgetFor(float $costUsd): array
    {
        $daily = $this->guard->dailyCapUsd();
        $monthly = $this->guard->monthlyCapUsd();
        $leftMonth = max(0.0, $monthly - $this->guard->spentThisMonthUsd());
        $leftToday = min(max(0.0, $daily - $this->guard->spentTodayUsd()), $leftMonth);
        $fitsToday = $costUsd <= $leftToday + 0.00001;

        $days = match (true) {
            $costUsd <= 0.0 => 0,
            $fitsToday => 1,
            $daily <= 0.0 => null, // never at a 0 $ cap
            default => 1 + (int) ceil(($costUsd - $leftToday) / $daily - 0.00001),
        };

        return [
            'daily_cap_usd' => round($daily, 2),
            'monthly_cap_usd' => round($monthly, 2),
            'left_today_usd' => round($leftToday, 2),
            'left_month_usd' => round($leftMonth, 2),
            'days' => $days,
            'fits_today' => $fitsToday,
            'over_month' => $costUsd > $leftMonth + 0.00001,
            'timezone' => $this->guard->timezone(),
        ];
    }
}
