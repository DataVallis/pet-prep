<?php

namespace App\Services;

use App\Enums\BreedType;
use App\Enums\ClientFeature;
use App\Enums\StageParamKey;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\User;
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
 * RecordClientFeatures) — the same "both apps" rule as a new pet (ClientFeature).
 * Until then nothing changes for the dog, so a child is never expected to do a
 * task the app on their phone cannot show.
 *
 * Which dogs: born, active, not game over, a profiled dog (not legacy) of a breed
 * with at least one care row (`*_frequency` or `no_walk_in_heat` in
 * breed_stage_params). Unborn / inactive / game-over dogs are left alone (an
 * unborn dog is picked up once it is born).
 *
 * Switching on (under the pet's row lock, idempotent): `care_tasks_enabled` =
 * true, `care_tasks_since` = the next family-local midnight after the run. No
 * slot, refusal, reminder or heat warning exists for an earlier day; closed days
 * are never touched (RoutineLedgerService); the period the midnight falls in
 * expects only its share (CareTaskService::timesFrom). Run by
 * `pets:care-tasks` every 5 minutes.
 */
class CareTaskActivationService
{
    /** Dogs looked at per run. */
    public const MAX_PETS_PER_RUN = 500;

    public function __construct(private readonly FamilyService $families) {}

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
    public function run(?CarbonInterface $now = null, bool $dryRun = false): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $breeds = $this->careBreeds();
        if ($breeds === []) {
            return ['candidates' => 0, 'activated' => 0, 'waiting' => 0];
        }

        $pets = $this->candidates($breeds)->limit(self::MAX_PETS_PER_RUN)->with('family')->get();
        $activated = 0;
        $waiting = 0;
        foreach ($pets as $pet) {
            if (! $this->appsReady($pet)) {
                $waiting++;

                continue;
            }
            if ($dryRun) {
                $activated++;

                continue;
            }
            try {
                $activated += $this->activate($pet->id, $now) !== null ? 1 : 0;
            } catch (Throwable $e) {
                // One dog never stops the run; it is tried again on the next run.
                Log::error('CareTaskActivationService: activation failed', ['pet_id' => $pet->id, 'error' => $e->getMessage()]);
                report($e);
            }
        }

        return ['candidates' => $pets->count(), 'activated' => $activated, 'waiting' => $waiting];
    }

    /**
     * The dogs that could be switched on (before the app check).
     *
     * @param  list<string>  $breeds
     * @return Builder<Pet>
     */
    public function candidates(array $breeds)
    {
        return Pet::born()
            ->where('is_active', true)
            ->where('is_game_over', false)
            ->where('care_tasks_enabled', false)
            ->where('species', 'dog')
            ->whereNotNull('arrival_age_months')
            ->whereIn('breed_type', $breeds)
            ->orderBy('id');
    }

    /** Every active caretaker child's app and at least one parent's app declare `care_tasks`. */
    public function appsReady(Pet $pet): bool
    {
        $children = User::whereIn('id', PetCaretaker::where('pet_id', $pet->id)->active()->select('user_id'))->get();
        if ($children->isEmpty() || $children->contains(fn (User $c) => ! $c->appSupports(ClientFeature::CareTasks))) {
            return false;
        }

        return $this->families->parentRecipients($pet)->contains(fn (User $p) => $p->appSupports(ClientFeature::CareTasks));
    }

    /** The next family-local midnight after $now (UTC). */
    public static function nextMidnight(Pet $pet, CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->setTimezone($pet->familyTimezone())->addDay()->startOfDay()->utc();
    }

    /**
     * Switch one dog on (row lock, re-checked). Returns `care_tasks_since`, or
     * null when the dog no longer qualifies / is already on.
     */
    public function activate(int $petId, CarbonInterface $now): ?CarbonImmutable
    {
        return DB::transaction(function () use ($petId, $now): ?CarbonImmutable {
            $pet = Pet::whereKey($petId)->lockForUpdate()->first();
            if ($pet === null || $pet->care_tasks_enabled || $pet->isUnborn() || ! $pet->is_active || $pet->is_game_over
                || ! $pet->isDog() || $pet->isLegacyProfile()) {
                return null;
            }
            $since = self::nextMidnight($pet, $now);
            $pet->forceFill(['care_tasks_enabled' => true, 'care_tasks_since' => $since])->saveQuietly();
            Log::info('Care tasks switched on for an existing dog', ['pet_id' => $pet->id, 'since' => $since->toIso8601String()]);

            return $since;
        });
    }
}
