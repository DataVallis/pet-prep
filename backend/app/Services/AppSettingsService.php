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
 */
class AppSettingsService
{
    public const CATS_KEY = 'cats_availability';

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

        $new = ['mode' => $mode->value, 'test_parent_ids' => $ids];

        DB::transaction(function () use ($actor, $new): void {
            $row = AppSetting::whereKey(self::CATS_KEY)->lockForUpdate()->first();
            $old = $row?->value;
            if ($old === $new) {
                return;
            }

            AppSetting::updateOrCreate(['key' => self::CATS_KEY], ['value' => $new, 'updated_by' => $actor->id]);
            AppSettingChange::create(['key' => self::CATS_KEY, 'user_id' => $actor->id, 'old' => $old, 'new' => $new]);

            Log::info('Admin changed a product switch', [
                'key' => self::CATS_KEY,
                'user_id' => $actor->id,
                'old' => $old,
                'new' => $new,
            ]);
        });

        // After the commit: the next read in any container sees the new value.
        Cache::forget(self::cacheKey(self::CATS_KEY));
    }

    public static function cacheKey(string $key): string
    {
        return 'app_settings:'.$key;
    }
}
