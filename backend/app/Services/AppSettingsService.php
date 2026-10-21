<?php

namespace App\Services;

use App\Enums\CatsAvailability;
use App\Enums\FamilyRole;
use App\Enums\UserRole;
use App\Jobs\ResumeStateMedia;
use App\Models\AppSetting;
use App\Models\AppSettingChange;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Product switches stored in `app_settings` (M5-R06-09, David 2026-10-10:
 * switched in /admin → Funkcije, not in the server .env).
 *
 * `cats_availability` = {mode: off|test_families|everyone, test_parent_ids:
 * list<int>}. No row → off. Env PETPREP_CATS_ENABLED=true
 * (`petprep.cats_enabled`) stays as a backwards-compatible override meaning
 * `everyone`. Reads are cached for CACHE_TTL_SECONDS (shared cache store →
 * every PHP container) and the cache is forgotten after every save.
 *
 * `ai_budget` (M4-07b, David 2026-10-14: raise the AI budget in /admin, not
 * in the server .env) = {daily_usd: float|null, monthly_usd: float|null}.
 * Null / no row → the env cap (AI_DAILY_BUDGET_USD / AI_MONTHLY_BUDGET_USD)
 * applies. Read by AiSpendGuard.
 *
 * `ai_lab_budget` (M4-07c, David 2026-10-14) = {daily_usd, monthly_usd,
 * max_run_usd}: the separate AI Lab budget (AI_LAB_DAILY_USD /
 * AI_LAB_MONTHLY_USD / AI_LAB_MAX_RUN_USD), same rules.
 *
 * `state_media` (M5-T08b, David 2026-10-21: product switches belong in the
 * superadmin panel; on from the start) = {enabled: bool}: state key images,
 * seamless loops and transitions (MediaEntitlementService::usesStateMedia).
 * No row → env AI_STATE_MEDIA_ENABLED (default true).
 */
class AppSettingsService
{
    public const CATS_KEY = 'cats_availability';

    public const AI_BUDGET_KEY = 'ai_budget';

    /** Sanity ceilings for the admin form (a typo must not lift the cap to $50 000). */
    public const AI_BUDGET_MAX_DAILY_USD = 500.0;

    public const AI_BUDGET_MAX_MONTHLY_USD = 5000.0;

    /**
     * M5-R21 (David 2026-10-14): `{enabled: bool}` — every new pet of a paid
     * breed gets its own AI media (true) or shares the media of its breed +
     * colour (false, the default: no row → false).
     */
    public const UNIQUE_MEDIA_KEY = 'unique_pet_media';

    public const AI_LAB_BUDGET_KEY = 'ai_lab_budget';

    /** M5-T08b: `{enabled: bool}` — state media on / off (no row → env default, on). */
    public const STATE_MEDIA_KEY = 'state_media';

    public const AI_LAB_MAX_DAILY_USD = 200.0;

    public const AI_LAB_MAX_MONTHLY_USD = 2000.0;

    public const AI_LAB_MAX_RUN_CEILING_USD = 50.0;

    public const CACHE_TTL_SECONDS = 60;

    /**
     * The stored value (DB / cache only, without the env override).
     *
     * @return array{mode: CatsAvailability, test_parent_ids: list<int>}
     */
    public function storedCats(): array
    {
        // `[]` (not null) for "no row", so the default is cached too.
        /** @var array{mode?: string, test_parent_ids?: list<int|string>} $value */
        $value = Cache::remember(self::cacheKey(self::CATS_KEY), self::CACHE_TTL_SECONDS,
            fn (): array => AppSetting::find(self::CATS_KEY)?->value ?? []);

        return [
            'mode' => CatsAvailability::tryFrom((string) ($value['mode'] ?? '')) ?? CatsAvailability::Off,
            'test_parent_ids' => array_values(array_map('intval', $value['test_parent_ids'] ?? [])),
        ];
    }

    /**
     * True while env PETPREP_CATS_ENABLED=true forces `everyone`.
     */
    public function catsForcedByEnv(): bool
    {
        return (bool) config('petprep.cats_enabled', false);
    }

    /**
     * The effective mode (env override first).
     */
    public function catsMode(): CatsAvailability
    {
        return $this->catsForcedByEnv() ? CatsAvailability::Everyone : $this->storedCats()['mode'];
    }

    /**
     * May members of this family create a NEW cat (server side; the app
     * build must still declare `species_cat`)? A family is a test family
     * when any of its parents is in `test_parent_ids`.
     */
    public function catsEnabledForFamily(?Family $family): bool
    {
        return match ($this->catsMode()) {
            CatsAvailability::Everyone => true,
            CatsAvailability::Off => false,
            CatsAvailability::TestFamilies => $family !== null && $this->isTestFamily($family),
        };
    }

    public function isTestFamily(Family $family): bool
    {
        $ids = $this->storedCats()['test_parent_ids'];
        if ($ids === []) {
            return false;
        }

        return FamilyMember::where('family_id', $family->id)
            ->where('role', FamilyRole::Parent->value)
            ->whereIn('user_id', $ids)
            ->exists();
    }

    /**
     * Save the cats switch (superadmin), audit it and bust the cache.
     *
     * @param  list<int|string>  $testParentIds  parent users; anything else is refused
     *
     * @throws InvalidArgumentException a listed id is not a parent account
     */
    public function updateCats(User $actor, CatsAvailability $mode, array $testParentIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $testParentIds)));
        sort($ids);

        $parents = User::whereIn('id', $ids)->where('role', UserRole::Parent->value)->count();
        if ($parents !== count($ids)) {
            throw new InvalidArgumentException('Test families can only be picked by parent accounts.');
        }

        $this->store($actor, self::CATS_KEY, ['mode' => $mode->value, 'test_parent_ids' => $ids]);
    }

    /**
     * The production AI budget set in /admin (null = the env cap applies).
     *
     * @return array{daily_usd: float|null, monthly_usd: float|null}
     */
    public function storedAiBudget(): array
    {
        /** @var array{daily_usd?: float|int|string|null, monthly_usd?: float|int|string|null} $value */
        $value = Cache::remember(self::cacheKey(self::AI_BUDGET_KEY), self::CACHE_TTL_SECONDS,
            fn (): array => AppSetting::find(self::AI_BUDGET_KEY)?->value ?? []);

        $read = fn (mixed $v): ?float => is_numeric($v) ? max(0.0, (float) $v) : null;

        return ['daily_usd' => $read($value['daily_usd'] ?? null), 'monthly_usd' => $read($value['monthly_usd'] ?? null)];
    }

    /**
     * Save the production AI budget (superadmin), audit it and bust the cache.
     * Null = back to the env cap. 0 = no AI calls (as AI_DAILY_BUDGET_USD=0).
     *
     * @throws InvalidArgumentException a value is negative or above the sanity ceiling
     */
    public function updateAiBudget(User $actor, ?float $dailyUsd, ?float $monthlyUsd): void
    {
        foreach ([[$dailyUsd, self::AI_BUDGET_MAX_DAILY_USD, 'daily'], [$monthlyUsd, self::AI_BUDGET_MAX_MONTHLY_USD, 'monthly']] as [$value, $max, $name]) {
            if ($value !== null && (! is_finite($value) || $value < 0 || $value > $max)) {
                throw new InvalidArgumentException("The {$name} AI budget must be between 0 and {$max} USD.");
            }
        }

        // Decimal strings: jsonb turns 20.0 into 20, so a float would never compare
        // equal to the stored value and an unchanged save would add an audit row.
        $this->store($actor, self::AI_BUDGET_KEY, [
            'daily_usd' => $dailyUsd === null ? null : sprintf('%.2f', $dailyUsd),
            'monthly_usd' => $monthlyUsd === null ? null : sprintf('%.2f', $monthlyUsd),
        ]);
    }

    /**
     * The AI Lab budget set in /admin (null = the env value applies).
     *
     * @return array{daily_usd: float|null, monthly_usd: float|null, max_run_usd: float|null}
     */
    public function storedAiLabBudget(): array
    {
        /** @var array<string, mixed> $value */
        $value = Cache::remember(self::cacheKey(self::AI_LAB_BUDGET_KEY), self::CACHE_TTL_SECONDS,
            fn (): array => AppSetting::find(self::AI_LAB_BUDGET_KEY)?->value ?? []);

        $read = fn (mixed $v): ?float => is_numeric($v) ? max(0.0, (float) $v) : null;

        return [
            'daily_usd' => $read($value['daily_usd'] ?? null),
            'monthly_usd' => $read($value['monthly_usd'] ?? null),
            'max_run_usd' => $read($value['max_run_usd'] ?? null),
        ];
    }

    /**
     * Save the AI Lab budget (superadmin), audited. Null = the env value.
     *
     * @throws InvalidArgumentException a value is negative or above the sanity ceiling
     */
    public function updateAiLabBudget(User $actor, ?float $dailyUsd, ?float $monthlyUsd, ?float $maxRunUsd): void
    {
        foreach ([
            [$dailyUsd, self::AI_LAB_MAX_DAILY_USD, 'daily AI Lab budget'],
            [$monthlyUsd, self::AI_LAB_MAX_MONTHLY_USD, 'monthly AI Lab budget'],
            [$maxRunUsd, self::AI_LAB_MAX_RUN_CEILING_USD, 'AI Lab limit per run'],
        ] as [$value, $max, $name]) {
            if ($value !== null && (! is_finite($value) || $value < 0 || $value > $max)) {
                throw new InvalidArgumentException("The {$name} must be between 0 and {$max} USD.");
            }
        }

        $fmt = fn (?float $v): ?string => $v === null ? null : sprintf('%.2f', $v);

        $this->store($actor, self::AI_LAB_BUDGET_KEY, [
            'daily_usd' => $fmt($dailyUsd),
            'monthly_usd' => $fmt($monthlyUsd),
            'max_run_usd' => $fmt($maxRunUsd),
        ]);
    }

    /**
     * One audited write of a setting (row lock, change row, log), then the cache
     * is forgotten after the commit so the next read in any container sees it.
     *
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>|null  $default  the value "no row" stands for (saving it over no row is no change)
     */
    private function store(User $actor, string $key, array $new, ?array $default = null): void
    {
        DB::transaction(function () use ($actor, $key, $new, $default): void {
            $row = AppSetting::whereKey($key)->lockForUpdate()->first();
            $old = $row?->value;
            // "No row" and "every value empty" mean the same (env defaults):
            // saving an untouched form must not write an audit row.
            $blank = fn (mixed $v): bool => $v === null || (is_array($v) && array_filter($v, fn ($x) => $x !== null) === []);
            // jsonb returns keys reordered (by length, then bytes): compare without key order.
            // M5-R21: a key may name the value "no row" stands for ($default).
            if (self::sameValue($old, $new) || ($row === null && ($blank($new) || ($default !== null && self::sameValue($new, $default))))) {
                return;
            }

            AppSetting::updateOrCreate(['key' => $key], ['value' => $new, 'updated_by' => $actor->id]);
            AppSettingChange::create(['key' => $key, 'user_id' => $actor->id, 'old' => $old, 'new' => $new]);

            Log::info('Admin changed an app setting', [
                'key' => $key,
                'user_id' => $actor->id,
                'old' => $old,
                'new' => $new,
            ]);
        });

        Cache::forget(self::cacheKey($key));
    }

    /**
     * M5-R21: unique AI media per pet (true) or shared per breed + colour
     * (false, default — SharedBreedLookService).
     */
    public function uniquePetMedia(): bool
    {
        /** @var array{enabled?: bool} $value */
        $value = Cache::remember(self::cacheKey(self::UNIQUE_MEDIA_KEY), self::CACHE_TTL_SECONDS,
            fn (): array => AppSetting::find(self::UNIQUE_MEDIA_KEY)?->value ?? []);

        return (bool) ($value['enabled'] ?? false);
    }

    /**
     * Save the "unique AI media per pet" switch (superadmin), audit it and bust
     * the cache. Only NEW pets follow the switch; existing pets keep their media.
     */
    public function updateUniquePetMedia(User $actor, bool $enabled): void
    {
        // No row already means "off", so saving "off" over no row is not a change.
        $this->store($actor, self::UNIQUE_MEDIA_KEY, ['enabled' => $enabled], ['enabled' => false]);
    }

    /**
     * M5-T08b: state key images, loops and transitions on (default) or off.
     * Off also stops what is already queued (PetMediaService, retry, sweep).
     * No row → env AI_STATE_MEDIA_ENABLED (`media.state_media.enabled`, default true).
     */
    public function stateMedia(bool $fresh = false): bool
    {
        // M5-T08b (QA m4): `$fresh` reads the row itself (no cache) — the fal entry points
        // use it so queue workers stop spending the moment the switch is turned off.
        /** @var array{enabled?: bool} $value */
        $value = $fresh
            ? (AppSetting::find(self::STATE_MEDIA_KEY)?->value ?? [])
            : Cache::remember(self::cacheKey(self::STATE_MEDIA_KEY), self::CACHE_TTL_SECONDS,
                fn (): array => AppSetting::find(self::STATE_MEDIA_KEY)?->value ?? []);

        return array_key_exists('enabled', $value)
            ? (bool) $value['enabled']
            : self::stateMediaDefault();
    }

    /**
     * Save the state media switch (superadmin), audit it and bust the cache.
     */
    public function updateStateMedia(User $actor, bool $enabled): void
    {
        $wasOn = $this->stateMedia(fresh: true);

        // No row already means the env default, so saving that over no row is not a change.
        $this->store($actor, self::STATE_MEDIA_KEY, ['enabled' => $enabled], ['enabled' => self::stateMediaDefault()]);

        // M5-T08b (QA M1): off → on resumes what waited while it was off.
        if ($enabled && ! $wasOn) {
            ResumeStateMedia::dispatch();
        }
    }

    /** The value "no row" stands for (env AI_STATE_MEDIA_ENABLED, default on). */
    public static function stateMediaDefault(): bool
    {
        return (bool) config('media.state_media.enabled', true);
    }

    /** Equal settings values, ignoring the key order of (nested) arrays. */
    private static function sameValue(mixed $a, mixed $b): bool
    {
        $norm = function (mixed $v) use (&$norm): mixed {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($norm, $v);
        };

        return $norm($a) === $norm($b);
    }

    public static function cacheKey(string $key): string
    {
        return 'app_settings:'.$key;
    }
}
