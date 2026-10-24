<?php

namespace App\Services;

use App\Enums\BreedType;
use App\Enums\ClientFeature;
use App\Enums\FamilyRole;
use App\Enums\StageParamKey;
use App\Events\PetUpdated;
use App\Models\Pet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Breed care tasks for EXISTING dogs (M5-R14 – R20 follow-up, David 2026-10-24,
 * answer (e)): a dog created without `care_tasks_enabled` (before the feature, or
 * from an older app build) gets its breed's care tasks, parent reminders and heat
 * warning too — from the NEXT family-local midnight, never retroactively.
 *
 * Old app builds cannot show the care mini-games, so a dog is switched on only
 * when the apps can show them (Claude's variant, DECISIONS 2026-10-24): every
 * active caretaker child's app AND at least one parent's app declared
 * `care_tasks` on their latest request (`users.client_features`,
 * RecordClientFeatures — one value per USER, the latest request from any of
 * their devices) — the same "both apps" rule as a new pet (ClientFeature).
 * Until then nothing changes for the dog, so a child is never expected to do a
 * task the app on their phone cannot show.
 *
 * Which dogs: born, active, not game over, a profiled dog (not legacy) of a breed
 * with at least one care row (`*_frequency` or `no_walk_in_heat` in
 * breed_stage_params). Unborn / inactive / game-over dogs are left alone (an
 * unborn dog is picked up once it is born).
 *
 * Switching on (under the pet's row lock, idempotent, re-checked): `care_tasks_enabled` =
 * true, `care_tasks_since` = the next family-local midnight after the run. No
 * slot, refusal, reminder or heat warning exists for an earlier day; closed days
 * are never touched (RoutineLedgerService); a child task's period the midnight
 * falls in expects only its share (CareTaskService::timesFrom); the groomer /
 * bath reminders count their periods from it (first reminder after one full
 * period). One PetUpdated('care_tasks_enabled') after the commit, so open apps
 * refetch.
 *
 * Which dogs a run reads (QA PR #377 M2 / m5): the app check is part of the SQL
 * (`users.client_features @> ["care_tasks"]`), so only ready dogs are loaded —
 * in id chunks over the WHOLE set until the run's time budget is spent (the rest
 * stay candidates for the next run; a switched-on dog drops out), no per-dog
 * queries for the dogs still waiting. Run by `pets:care-tasks` every 5 minutes.
 */
class CareTaskActivationService
{
    /** Dogs read per query. */
    public const CHUNK = 200;

    /** Dogs read per query in this instance (tests set a small one). */
    public int $chunk = self::CHUNK;

    /** Seconds one run may spend (the schedule runs every 5 minutes). */
    public const TIME_BUDGET_SECONDS = 60;

    /**
     * BreedType values with at least one care row in breed_stage_params.
     *
     * @return list<string>
     */
    public function careBreeds(): array
    {
        return self::breedsWithKeys(array_values(array_filter(
            StageParamKey::cases(),
            fn (StageParamKey $k) => $k->isCareTaskFrequency() || $k === StageParamKey::NoWalkInHeat,
        )));
    }

    /**
     * BreedType values with a breed_stage_params row of any of $keys.
     *
     * @param  list<StageParamKey>  $keys
     * @return list<string>
     */
    public static function breedsWithKeys(array $keys): array
    {
        $slugs = DB::table('breed_stage_params')
            ->whereIn('key', array_map(fn (StageParamKey $k) => $k->value, $keys))
            ->distinct()
            ->pluck('breed_slug')
            ->all();

        return array_values(array_map(
            fn (BreedType $b) => $b->value,
            array_filter(BreedType::cases(), fn (BreedType $b) => in_array($b->slug(), $slugs, true)),
        ));
    }

    /**
     * Switch on every dog whose apps are ready.
     *
     * @return array{candidates: int, activated: int, waiting: int}
     */
    public function run(?CarbonInterface $now = null, bool $dryRun = false, ?float $budgetSeconds = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $breeds = $this->careBreeds();
        if ($breeds === []) {
            return ['candidates' => 0, 'activated' => 0, 'waiting' => 0];
        }

        $candidates = $this->candidates($breeds)->count();
        $ready = $this->ready($this->candidates($breeds));
        $readyCount = (clone $ready)->count();
        if ($dryRun) {
            return ['candidates' => $candidates, 'activated' => $readyCount, 'waiting' => $candidates - $readyCount];
        }

        $deadline = microtime(true) + ($budgetSeconds ?? self::TIME_BUDGET_SECONDS);
        $activated = 0;
        $ready->select('pets.id')->chunkById($this->chunk, function ($pets) use ($now, $deadline, &$activated): bool {
            foreach ($pets as $pet) {
                if (microtime(true) > $deadline) {
                    return false; // the rest stays a candidate — the next run continues
                }
                try {
                    $activated += $this->activate($pet->id, $now) !== null ? 1 : 0;
                } catch (Throwable $e) {
                    // One dog never stops the run; it is tried again on the next run.
                    Log::error('CareTaskActivationService: activation failed', ['pet_id' => $pet->id, 'error' => $e->getMessage()]);
                    report($e);
                }
            }

            return true;
        }, 'pets.id', 'id');

        return ['candidates' => $candidates, 'activated' => $activated, 'waiting' => $candidates - $readyCount];
    }

    /**
     * The dogs that could be switched on (before the app check).
     *
     * @param  list<string>  $breeds
     * @return Builder<Pet>
     */
    public function candidates(array $breeds): Builder
    {
        return Pet::born()
            ->where('pets.is_active', true)
            ->where('pets.is_game_over', false)
            ->where('pets.care_tasks_enabled', false)
            ->where('pets.species', 'dog')
            ->whereNotNull('pets.arrival_age_months')
            ->whereIn('pets.breed_type', $breeds);
    }

    /**
     * Narrow $query to dogs whose apps are ready, in SQL: at least one active
     * caretaker child, every active caretaker child's app AND at least one of the
     * family's parents' apps declared `care_tasks` (users.client_features).
     *
     * @param  Builder<Pet>  $query
     * @return Builder<Pet>
     */
    public function ready(Builder $query): Builder
    {
        $feature = ClientFeature::CareTasks->value;

        return $query
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('pet_caretakers')
                ->whereColumn('pet_caretakers.pet_id', 'pets.id')
                ->whereNotNull('pet_caretakers.user_id')->whereNull('pet_caretakers.ended_at'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('pet_caretakers')
                ->join('users', 'users.id', '=', 'pet_caretakers.user_id')
                ->whereColumn('pet_caretakers.pet_id', 'pets.id')
                ->whereNull('pet_caretakers.ended_at')
                ->where(fn ($w) => $w->whereNull('users.client_features')->orWhereJsonDoesntContain('users.client_features', $feature)))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('family_user')
                ->join('users', 'users.id', '=', 'family_user.user_id')
                ->whereColumn('family_user.family_id', 'pets.family_id')
                ->where('family_user.role', FamilyRole::Parent->value)
                ->whereJsonContains('users.client_features', $feature));
    }

    /** Every active caretaker child's app and at least one parent's app declare `care_tasks`. */
    public function appsReady(Pet $pet): bool
    {
        return $this->ready(Pet::query()->whereKey($pet->id))->exists();
    }

    /** The next family-local midnight after $now (UTC). */
    public static function nextMidnight(Pet $pet, CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->setTimezone($pet->familyTimezone())->addDay()->startOfDay()->utc();
    }

    /**
     * Switch one dog on (row lock, everything re-checked — a concurrent run that
     * got there first wins, this one changes nothing). Returns `care_tasks_since`,
     * or null when the dog no longer qualifies / is already on.
     */
    public function activate(int $petId, CarbonInterface $now): ?CarbonImmutable
    {
        return DB::transaction(function () use ($petId, $now): ?CarbonImmutable {
            $pet = Pet::whereKey($petId)->lockForUpdate()->first();
            if ($pet === null || $pet->care_tasks_enabled || $pet->isUnborn() || ! $pet->is_active || $pet->is_game_over
                || ! $pet->isDog() || $pet->isLegacyProfile() || ! $this->appsReady($pet)) {
                return null;
            }
            $since = self::nextMidnight($pet, $now);
            $pet->forceFill(['care_tasks_enabled' => true, 'care_tasks_since' => $since, 'care_reminder_check_at' => null])->saveQuietly();
            Log::info('Care tasks switched on for an existing dog', ['pet_id' => $pet->id, 'since' => $since->toIso8601String()]);
            // Open apps refetch (the tasks themselves start at `since`).
            PetUpdated::afterCommit($pet, 'care_tasks_enabled');

            return $since;
        });
    }
}
