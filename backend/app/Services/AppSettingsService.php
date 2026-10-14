<?php

namespace App\Services;

use App\Enums\CatsAvailability;
use App\Enums\FamilyRole;
use App\Enums\UserRole;
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
 * applies. Read by AiSpendGuard; the AI Lab budget stays env-only.
 */
class AppSettingsService
{
    public const CATS_KEY = 'cats_availability';

    public const AI_BUDGET_KEY = 'ai_budget';

    /** Sanity ceilings for the admin form (a typo must not lift the cap to $50 000). */
    public const AI_BUDGET_MAX_DAILY_USD = 500.0;

    public const AI_BUDGET_MAX_MONTHLY_USD = 5000.0;

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

        $this->store($actor, self::AI_BUDGET_KEY, [
            'daily_usd' => $dailyUsd === null ? null : round($dailyUsd, 2),
            'monthly_usd' => $monthlyUsd === null ? null : round($monthlyUsd, 2),
        ]);
    }

    /**
     * One audited write of a setting (row lock, change row, log), then the cache
     * is forgotten after the commit so the next read in any container sees it.
     *
     * @param  array<string, mixed>  $new
     */
    private function store(User $actor, string $key, array $new): void
    {
        DB::transaction(function () use ($actor, $key, $new): void {
            $row = AppSetting::whereKey($key)->lockForUpdate()->first();
            $old = $row?->value;
            if ($old === $new) {
                return;
            }

            AppSetting::updateOrCreate(['key' => $key], ['value' => $new, 'updated_by' => $actor->id]);
            AppSettingChange::create(['key' => $key, 'user_id' => $actor->id, 'old' => $old, 'new' => $new]);

            Log::info('Admin changed a product switch', [
                'key' => $key,
                'user_id' => $actor->id,
                'old' => $old,
                'new' => $new,
            ]);
        });

        Cache::forget(self::cacheKey($key));
    }

    public static function cacheKey(string $key): string
    {
        return 'app_settings:'.$key;
    }
}
