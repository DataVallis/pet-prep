<?php

namespace Database\Seeders;

use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\StageParamKey;
use App\Services\LifeStageService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sourced life-stage data (M5-R01, David 2026-10-05) → `breed_stage_params`.
 *
 * Every value comes from docs/research/dog-data/data.json (`ref` = JSON path)
 * with the source id of docs/research/dog-data/sources.md. Rules:
 *  - `verified` = true only when the value is directly backed by the cited
 *    source or is a recorded David decision (`decision`); every value the
 *    research marked "UNSOURCED — proposal" without a decision stays
 *    `verified = false` and is shown as such in Filament.
 *  - David confirmed every open M5-R01 choice on 2026-10-05 (`CONFIRMED`,
 *    "potrdil David 2026-10-05"): feed window clock times (now 2 h each),
 *    stage boundaries 9 / 36 / 108–118 months, arrival ages (puppy 2 months,
 *    others the first month of the stage), exercise minutes (mutt adult 60,
 *    puppy / young 10 × age months, senior 75 % of adult) and senior 2 meals.
 *    Those rows are `verified = true` with the decision in `notes`; their
 *    `source_id` stays the underlying evidence (null where literature gives
 *    no number) — the decision is never presented as literature.
 *    Production rows from PR #37 were updated once by the data migration
 *    2026_10_12_120000_apply_david_stage_decisions (the seeder never updates).
 *  - INSERT-ONLY (like BreedConfigsSeeder, DECISIONS 2026-10-03): an
 *    existing (breed, stage, from month, key) row is never touched, so admin
 *    edits in Filament always win. Runs on every production deploy through
 *    BreedConfigsSeeder::run(). A breed missing in breed_configs is skipped.
 *  - NEVER RESURRECTS: a tuple an admin ever touched — it has a row in
 *    `breed_stage_param_changes` (created / updated / deleted; for an update
 *    that re-keyed the row also the ORIGINAL tuple, rebuilt from `old`) — is
 *    skipped, so a deleted or re-keyed value does not come back on the next
 *    deploy.
 *
 * tests/Feature/LifeStageDataTest cross-checks every row against data.json.
 *
 * DOG BREEDS (M5-R10): rows() loops DOG_BREEDS; the shared general_by_size
 * rows are written once in rows(), the breed-specific values (stage
 * boundaries, arrival ages, exercise minutes, weight, growth, Coren rank,
 * learning multiplier, individual variation, lifespan) come from
 * dogProfile(). A new dog breed = an enum case in DOG_BREEDS + a profile;
 * the mutt / Border Collie rows stay byte-identical (LabradorBreedTest
 * cross-checks the Labrador rows against data.json).
 *
 * CATS (M5-R06-03, M5-R06_PLAN T10): a separate rowset, catRows(), from
 * docs/research/cat-data/data.json (`ref` = "cat-data:" + JSON path, source
 * ids C1–C25 of docs/research/cat-data/sources.md). rows() stays the dog
 * rowset exactly as before; run() seeds allRows(). The existing stage values
 * are reused (puppy = kitten, young = young adult, adult = mature adult,
 * senior = senior — CAT_SPEC §2).
 *
 * Feed window times (David 2026-10-05): N meals → the first window at
 * 07:00, the last at 19:00, equal spacing (12 h / (N − 1)), each window
 * 2 hours long: 4 meals = 07–09, 11–13, 15–17, 19–21; 3 meals = 07–09,
 * 13–15, 19–21. Two meals have no row → the breed's existing
 * breed_configs.feed_windows (06–10, 17–21) stay in force.
 */
class BreedStageParamsSeeder extends Seeder
{
    public const DECISION = 'David 2026-10-05';

    /** David's answers to the M5-R01 open questions (2026-10-05). */
    public const CONFIRMED = 'potrdil David 2026-10-05';

    /** David's M5-R02 decisions (behaviour events, 2026-10-06). */
    public const CONFIRMED_R02 = 'potrdil David 2026-10-06';

    /**
     * David's M5-R03 decisions (training, 2026-10-06): Border Collie 2×, mixed
     * breed baseline ±20 %; M5-R03b: 5 min a day, +1 per praise, −2 per missed
     * day (production rows flipped by 2026_10_15_120000_apply_david_training_decisions),
     * starting progress per arrival stage.
     */
    public const CONFIRMED_R03 = 'potrdil David 2026-10-06';

    /**
     * David's M5-R03b answers (2026-10-07): the two training effects (potty
     * 0.75, place 0.5) — production rows flipped by
     * 2026_10_16_120000_apply_david_training_effect_decisions.
     */
    public const CONFIRMED_R03_EFFECTS = 'potrdil David 2026-10-07';

    /** M5-R03b: starting progress of a puppy (also bought) — David 2026-10-06. */
    public const PUPPY_STARTING_PROGRESS = ['sit' => 0, 'come' => 0, 'place' => 0, 'potty' => 0];

    /** M5-R03b: starting progress of a dog arriving young, adult or senior — David 2026-10-06. */
    public const GROWN_STARTING_PROGRESS = ['sit' => 50, 'come' => 30, 'place' => 0, 'potty' => 70];

    /** CAT_SPEC Q1–Q10, David 2026-10-08 13:47 (data.json `decision`). */
    public const CONFIRMED_CAT = 'potrdil David 2026-10-08 13:47';

    /** David's later answers on the M5-R06 plan, 2026-10-08 (data.json `decision`). */
    public const CONFIRMED_CAT_PLAN = 'potrdil David 2026-10-08 (načrt M5-R06)';

    /** David's answers for the cat play rules (M5-R06-04), 2026-10-08 ~20:40 (data.json `decision`). */
    public const CONFIRMED_CAT_PLAY = 'potrdil David 2026-10-08 20:40';

    /**
     * David's M5-R10 decisions for the Labrador Retriever (2026-10-09,
     * data.json proposed_game_parameters.labrador_retriever.*.decision):
     * exercise 90 min, senior 75 %, stages 9 / 36 / 118, arrival 2 / 9 / 36 /
     * 118, learning multiplier 1.8.
     */
    public const CONFIRMED_R10 = 'potrdil David 2026-10-09';

    /** Dog breeds of rows(), in seeding order (M5-R10: one profile per breed, dogProfile()). */
    public const DOG_BREEDS = [BreedType::Mutt, BreedType::BorderCollie, BreedType::LabradorRetriever];

    /** Prefix of a cat row's `data_ref` (path into docs/research/cat-data/data.json). */
    public const CAT_REF = 'cat-data:';

    /** @var list<array{0: string, 1: string}> */
    public const PUPPY_4_MEAL_WINDOWS = [['07:00', '09:00'], ['11:00', '13:00'], ['15:00', '17:00'], ['19:00', '21:00']];

    /** @var list<array{0: string, 1: string}> */
    public const PUPPY_3_MEAL_WINDOWS = [['07:00', '09:00'], ['13:00', '15:00'], ['19:00', '21:00']];

    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach (self::DOG_BREEDS as $breed) {
            $p = self::dogProfile($breed);
            $slug = $breed->slug();
            $add = function (string $stage, int $from, StageParamKey $key, mixed $value, array $meta) use (&$rows, $slug): void {
                $rows[] = array_merge([
                    'breed_slug' => $slug,
                    'stage' => $stage,
                    'age_from_months' => $from,
                    'key' => $key->value,
                    'value' => $value,
                    'unit' => null,
                    'source_id' => null,
                    'confidence' => 'low',
                    'verified' => false,
                    'quote' => null,
                    'notes' => null,
                    'ref' => null,
                    'decision' => null,
                ], $meta);
            };

            // ── Stage boundaries (AAHA life stages, S11) ──────────────────
            $add('puppy', 0, StageParamKey::StartsAtMonths, 0, [
                'unit' => 'months', 'source_id' => 'S11', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general_by_size.life_stages.puppy',
                'quote' => 'From birth to cessation of rapid growth (~6–9 months, varying with breed and size)',
            ]);
            foreach (['young', 'adult', 'senior'] as $stage) {
                [$month, $meta] = $p['starts_at'][$stage];
                $add($stage, 0, StageParamKey::StartsAtMonths, $month, $meta);
            }

            // ── Age at arrival (parent's choice → pets.arrival_age_months) ──
            $add('puppy', 0, StageParamKey::ArrivalAgeMonths, 2, [
                'unit' => 'months', 'source_id' => 'S36', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.training.start_age', 'decision' => self::CONFIRMED,
                'quote' => 'Puppies can begin very simple training starting as soon as they come home, usually around 8 weeks old.',
                'notes' => 'Game value backed by S36 ("home at ~8 weeks").',
            ]);
            foreach ($p['arrival'] as $stage => $age) {
                $add($stage, 0, StageParamKey::ArrivalAgeMonths, $age, $p['arrival_meta']);
            }

            // ── Meals per day (counts sourced) ──────────────────────────────
            $add('puppy', 0, StageParamKey::MealsPerDay, 4, [
                'unit' => 'meals/day', 'source_id' => 'S18', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general_by_size.feeding_meals_per_day.8_12_weeks',
                'quote' => 'Puppies eight to 12 weeks old need four meals a day.',
            ]);
            $add('puppy', 3, StageParamKey::MealsPerDay, 3, [
                'unit' => 'meals/day', 'source_id' => 'S18', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.feeding_meals_per_day.3_6_months',
                'quote' => 'Feed puppies three to six months old three meals a day.',
            ]);
            $add('puppy', 6, StageParamKey::MealsPerDay, 2, [
                'unit' => 'meals/day', 'source_id' => 'S18', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general_by_size.feeding_meals_per_day.6_12_months',
                'quote' => 'Feed puppies six months to one year two meals a day.',
            ]);
            $add('young', 0, StageParamKey::MealsPerDay, 2, [
                'unit' => 'meals/day', 'source_id' => 'S18', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general_by_size.feeding_meals_per_day.6_12_months',
                'quote' => 'Feed puppies six months to one year two meals a day.',
                'notes' => 'After 12 months the adult rule applies (S19: at least two meals) — also 2.',
            ]);
            $add('adult', 0, StageParamKey::MealsPerDay, 2, [
                'unit' => 'meals/day', 'source_id' => 'S19', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.feeding_meals_per_day.adult',
                'quote' => 'The most common recommendation is to feed your dog at least two meals per day.',
                'decision' => self::DECISION,
                'notes' => 'ASPCA (S18) says one meal can be enough; David chose 2 (2026-10-05).',
            ]);
            $add('senior', 0, StageParamKey::MealsPerDay, 2, [
                'unit' => 'meals/day', 'source_id' => 'S14', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.feeding_meals_per_day.senior', 'decision' => self::CONFIRMED,
                'quote' => 'feeding your dog smaller meals two or three times a day',
                'notes' => 'Game value inside the sourced 2–3: lower end, same as adult (David 2026-10-05: adults 2 meals).',
            ]);

            // ── Feed window clock times (David decision) ────────────────────
            $windowsMeta = [
                'unit' => 'HH:MM family-local [start, end)', 'verified' => true,
                'ref' => 'proposed_game_parameters.feed_window_times', 'decision' => self::CONFIRMED,
                'notes' => 'Game clock times (no literature number; the meal COUNT is sourced, S18). First window 07:00, last 19:00, equal spacing, 2 h each. A window entirely inside quiet hours is done by the parent.',
            ];
            $add('puppy', 0, StageParamKey::FeedWindows, self::PUPPY_4_MEAL_WINDOWS, $windowsMeta);
            $add('puppy', 3, StageParamKey::FeedWindows, self::PUPPY_3_MEAL_WINDOWS, $windowsMeta);

            // ── Exercise → step goal ────────────────────────────────────────
            $add('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth, 10, [
                'unit' => 'minutes/day per month of age', 'source_id' => 'S24', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general_by_size.exercise.puppy_rule_of_thumb', 'decision' => self::CONFIRMED,
                'quote' => 'five minutes of exercise per month of age, twice a day, until the puppy is full-grown',
                'notes' => 'Game rule; the source rule is CONTESTED (S25 calls it a misconception), so confidence stays low. 5 min × 2 per day; capped at the adult minutes.',
            ]);
            $add('young', 0, StageParamKey::ExerciseMinutesPerAgeMonth, 10, [
                'unit' => 'minutes/day per month of age', 'source_id' => 'S24', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general_by_size.exercise.puppy_rule_of_thumb', 'decision' => self::CONFIRMED,
                'quote' => 'five minutes of exercise per month of age, twice a day, until the puppy is full-grown',
                'notes' => $p['young_per_age_notes'],
            ]);
            foreach (['young', 'adult'] as $stage) {
                $add($stage, 0, StageParamKey::ExerciseMinutesPerDay, ...$p['adult_minutes']);
            }
            $add('senior', 0, StageParamKey::ExerciseMinutesPerDay, ...$p['senior_minutes']);
            $add('all', 0, StageParamKey::StepsPerExerciseMinute, 100, [
                'unit' => 'child steps per walking minute', 'source_id' => 'S45', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general_by_size.exercise.steps_conversion',
                'quote' => '≥100 steps/min is a consistent heuristic … associated with absolutely defined moderate intensity',
                'decision' => self::DECISION,
                'notes' => 'Adult human cadence (S45); David accepted 100 steps per minute of the dog\'s daily exercise (2026-10-05).',
            ]);

            // ── Sleep (stored for videos / behaviour later) ─────────────────
            $sleep = fn (string $ref, string $quote): array => [
                'unit' => 'hours/day', 'source_id' => 'S28', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.sleep.'.$ref, 'quote' => $quote,
            ];
            $add('puppy', 0, StageParamKey::SleepHours, [15, 20], $sleep('4_12_weeks', '15-20 hours of sleep each day'));
            $add('puppy', 3, StageParamKey::SleepHours, [14, 16], $sleep('3_6_months', '14-16 hours of sleep per day'));
            $add('puppy', 6, StageParamKey::SleepHours, [12, 14], $sleep('over_6_months', 'around 12-14 hours each day'));
            $add('young', 0, StageParamKey::SleepHours, [12, 14], $sleep('over_6_months', 'around 12-14 hours each day'));
            $add('adult', 0, StageParamKey::SleepHours, [12, 14], $sleep('over_6_months', 'around 12-14 hours each day'));
            $add('senior', 0, StageParamKey::SleepHours, null, [
                'unit' => 'hours/day', 'source_id' => 'S14', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.sleep.senior',
                'quote' => 'can become less energetic and tend to sleep more',
                'notes' => 'No hours given: "more than an adult".',
            ]);

            // ── Behaviour (M5-R02, David 2026-10-06) ────────────────────────
            $add('puppy', 0, StageParamKey::AccidentHoldHoursPerAgeMonth, 1, [
                'unit' => 'hours of hold per month of age', 'source_id' => 'S30,S31', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.accident_window_hours', 'decision' => self::CONFIRMED_R02,
                'quote' => 'one hour for each month of age, give or take an hour',
                'notes' => 'S30 (Penn Vet); S31 (WebMD, DVM-reviewed): "1 hour for every month of age until they\'re about a year old". Puppy stage only; the game clock counts only outside quiet hours.',
            ]);
            $add('puppy', 0, StageParamKey::ChewingChancePerDay, 0.5, [
                'unit' => 'probability per family-local day (teething puppy)', 'source_id' => 'S32,S33', 'confidence' => 'low', 'verified' => false,
                'ref' => 'proposed_game_parameters.chewing_teething_chance_per_day',
                'notes' => 'UNSOURCED proposal (Claude, M5-R02, waiting for David): the sources say teething puppies chew a lot (S32, S33) but give no frequency. Applies only inside teething_months.',
            ]);

            // ── Breed level ─────────────────────────────────────────────────
            $add('all', 0, StageParamKey::AdultWeightKg, ...$p['adult_weight']);
            $add('all', 0, StageParamKey::GrowthEndMonths, ...$p['growth_end']);
            $add('all', 0, StageParamKey::HouseTrainedByMonths, [4, 6], [
                'unit' => 'months', 'source_id' => 'S31', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.house_training.fully_trained',
                'quote' => 'It typically takes 4-6 months for a puppy to be fully house trained, but some puppies may take up to a year.',
            ]);
            $add('all', 0, StageParamKey::TeethingMonths, [3, 6], [
                'unit' => 'months', 'source_id' => 'S32', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general_by_size.teething_chewing.baby_teeth_shed',
                'quote' => 'your puppy\'s baby teeth start to shed (Weeks 12-16)',
                'notes' => 'Baby teeth shed from 12–16 weeks; adult teeth in and intense chewing over by ~6 months (S32, S33).',
            ]);
            $add('all', 0, StageParamKey::CorenRank, ...$p['coren_rank']);
            // ── Training (M5-R03, David 2026-10-06) ─────────────────────────
            $add('all', 0, StageParamKey::TrainingLearningMultiplier, ...$p['learning_multiplier']);
            if ($p['individual_variation'] !== null) {
                // No row (Border Collie, Labrador) = no individual variation.
                $add('all', 0, StageParamKey::TrainingIndividualVariation, ...$p['individual_variation']);
            }
            // M5-R03b: David confirmed the three numbers on 2026-10-06 and the two
            // effects on 2026-10-07 (verified, decision in notes).
            $add('all', 0, StageParamKey::TrainingMinutesPerDay, 5, [
                'unit' => 'minutes of mini-game per dog and family-local day', 'source_id' => 'S36,S37', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.training_minigame_minutes', 'decision' => self::CONFIRMED_R03,
                'notes' => 'Game value (no literature number): S36 / S37 say 5–10 min sessions and ≤ 15 min a day for puppies; 5 min = the dog\'s daily budget (≈ 6 sessions of 50 s), same for every stage, split equally between the children who care for the dog (M5-R03b).',
            ]);
            $add('all', 0, StageParamKey::TrainingProgressPerSuccess, 1.0, [
                'unit' => 'percentage points per correctly timed praise (baseline)', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.training_progress_per_success', 'decision' => self::CONFIRMED_R03,
                'notes' => 'Game value (no literature number): game balance — about 16 good sessions per command for a mixed breed.',
            ]);
            $add('all', 0, StageParamKey::TrainingDecayPerMissedDay, 2.0, [
                'unit' => 'percentage points per missed training day, every command', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.training_decay_per_missed_day', 'decision' => self::CONFIRMED_R03,
                'notes' => 'Game value (no literature number): no source gives a forgetting rate.',
            ]);
            $add('all', 0, StageParamKey::PottyTrainingAccidentReduction, 0.75, [
                'unit' => 'share of due puppy accidents avoided at 100 % potty training', 'source_id' => 'S47,S31', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.potty_training_accident_reduction', 'decision' => self::CONFIRMED_R03_EFFECTS,
                'notes' => 'Game value (no literature number for the size): a house-trained puppy learns to ask to go out (S47); the size of the effect is PetPrep\'s. Bladder hold (S30 / S31) unchanged.',
            ]);
            $add('all', 0, StageParamKey::PlaceTrainingChewingReduction, 0.5, [
                'unit' => 'share of the teething chewing chance removed at 100 % place training', 'source_id' => 'S33', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.place_training_chewing_reduction', 'decision' => self::CONFIRMED_R03_EFFECTS,
                'notes' => 'Game value (no literature number for the size): guidance teaches a puppy to chew its toys (S33); the size of the effect is PetPrep\'s. Chewing after a missed walk stays certain.',
            ]);

            // M5-R03b (David 2026-10-06): a dog arriving young, adult or senior
            // (bought or adopted) already knows some commands; a puppy starts at 0.
            foreach (LifeStage::ordered() as $stage) {
                $add($stage->value, 0, StageParamKey::TrainingStartingProgress, $stage === LifeStage::Puppy
                    ? self::PUPPY_STARTING_PROGRESS : self::GROWN_STARTING_PROGRESS, [
                        'unit' => 'progress % per command when the dog arrives in this stage', 'confidence' => 'low', 'verified' => true,
                        'ref' => 'proposed_game_parameters.training_starting_progress', 'decision' => self::CONFIRMED_R03,
                        'notes' => 'No source; Claude proposal confirmed by David. Applied once when a pet with training is created (arrival stage, any origin); not a session.',
                    ]);
            }

            $add('all', 0, StageParamKey::LifespanYears, ...$p['lifespan']);
        }

        return $rows;
    }

    /**
     * The breed-specific dog values of rows() (M5-R10): everything else in
     * rows() is general_by_size data shared by every dog breed. Each entry is
     * [value, meta] (meta as in rows()'s $add); `starts_at` is per stage
     * young / adult / senior, `arrival` the non-puppy arrival ages (stage →
     * months) with one shared `arrival_meta`; `individual_variation` null =
     * no row (no per-dog random factor).
     *
     * The mutt and Border Collie profiles are the values rows() had inline
     * before M5-R10 — their rows must stay byte-identical (DogRegressionSnapshotTest,
     * LifeStageDataTest).
     *
     * @return array{starts_at: array<string, array{0: int, 1: array<string, mixed>}>, arrival: array<string, int>, arrival_meta: array<string, mixed>, young_per_age_notes: string, adult_minutes: array{0: int, 1: array<string, mixed>}, senior_minutes: array{0: int, 1: array<string, mixed>}, adult_weight: array{0: list<float|int>, 1: array<string, mixed>}, growth_end: array{0: list<int>, 1: array<string, mixed>}, coren_rank: array{0: int|null, 1: array<string, mixed>}, learning_multiplier: array{0: float, 1: array<string, mixed>}, individual_variation: array{0: float, 1: array<string, mixed>}|null, lifespan: array{0: float, 1: array<string, mixed>}}
     */
    public static function dogProfile(BreedType $breed): array
    {
        // Shared by the mutt and the Border Collie (M5-R01, David 2026-10-05).
        $young = [9, [
            'unit' => 'months', 'source_id' => 'S11', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'general_by_size.life_stages.young_adult', 'decision' => self::CONFIRMED,
            'quote' => 'From cessation of rapid growth to completion of physical and social maturation',
            'notes' => 'Game boundary inside the sourced range (no exact month in the literature). AAHA: puppy ends ~6–9 months; 9 = upper end for medium dogs (still 72 % of adult weight at 6 months, S9 logistic proposal).',
        ]];
        $adult = [36, [
            'unit' => 'months', 'source_id' => 'S11', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'general_by_size.life_stages.mature_adult', 'decision' => self::CONFIRMED,
            'quote' => 'From completion of physical and social maturation until the last 25% of estimated lifespan',
            'notes' => 'Game boundary inside the sourced range (no exact month in the literature). Maturation completes at 3–4 years (S11); 36 months = lower end.',
        ]];
        $arrivalMeta = [
            'unit' => 'months', 'verified' => true,
            'ref' => 'proposed_game_parameters.arrival_age_months', 'decision' => self::CONFIRMED,
            'notes' => 'Game value (no literature number): representative age = first month of the stage, so the dog stays in this stage for the 12-week challenge.',
        ];
        $youngPerAgeNotes = 'Game rule; applies "until full-grown" (12–15 months, S10); capped at the adult minutes, so from ~12 months it equals the adult value.';
        $seniorMinutesMeta = [
            'unit' => 'minutes/day', 'verified' => true,
            'ref' => 'proposed_game_parameters.senior_exercise_minutes', 'decision' => self::CONFIRMED,
            'notes' => 'Game value (no literature number): 75 % of the adult minutes. Sources only say "frequent short walks instead of one long one" (S14) and that energy needs fall with age (S13).',
        ];
        $mediumGrowth = fn (string $ref): array => [[12, 15], [
            'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
            'ref' => $ref,
            'quote' => 'Medium (24–59 pounds): 12–15 months',
        ]];

        return match ($breed) {
            BreedType::Mutt => [
                'starts_at' => [
                    'young' => $young,
                    'adult' => $adult,
                    'senior' => [108, [
                        'unit' => 'months', 'source_id' => 'S11,S15', 'confidence' => 'medium', 'verified' => true,
                        'ref' => 'medium_mixed_breed.lifespan.senior_from',
                        'decision' => self::CONFIRMED,
                        'notes' => 'Derived game boundary: last 25 % of lifespan (S11) × median 12.0 y for crossbreeds (S15) = 9.0 y = 108 months. Dogs Trust rule of thumb: > 7 y (S14).',
                    ]],
                ],
                'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 108],
                'arrival_meta' => $arrivalMeta,
                'young_per_age_notes' => $youngPerAgeNotes,
                'adult_minutes' => [60, [
                    'unit' => 'minutes/day', 'verified' => true,
                    'ref' => 'medium_mixed_breed.exercise.adult_game_target', 'decision' => self::CONFIRMED,
                    'notes' => 'Game value (no literature number): 60 min inside the sourced 30–120 min adult range (S24) → ≈ 6,000 steps.',
                ]],
                'senior_minutes' => [45, $seniorMinutesMeta],
                'adult_weight' => [[15, 30], [
                    'unit' => 'kg', 'source_id' => 'S8', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'medium_mixed_breed.assumed_adult_weight', 'decision' => self::DECISION,
                    'quote' => 'Category IV: 15 to <30 kg',
                    'notes' => 'David 2026-10-05: the game\'s mutt is a medium mixed breed = Salt size category IV (S8).',
                ]],
                'growth_end' => $mediumGrowth('medium_mixed_breed.growth.adult_weight_reached'),
                'coren_rank' => [null, [
                    'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'medium_mixed_breed.trainability.coren',
                    'quote' => 'Non-AKC/CKC recognized breeds like Jack Russell Terriers were excluded from rankings',
                    'notes' => 'Mixed breeds are not ranked; the game models them as average + individual randomness (proposal, M5 training).',
                ]],
                'learning_multiplier' => [1.0, [
                    'unit' => '× baseline learning speed', 'source_id' => 'S35,S42', 'confidence' => 'low', 'verified' => true,
                    'ref' => 'medium_mixed_breed.trainability.learning_multiplier', 'decision' => self::CONFIRMED_R03,
                    'notes' => 'Game baseline: mixed breeds have no Coren rank (S35); breed explains ~9 % of individual behaviour (S42).',
                ]],
                'individual_variation' => [0.2, [
                    'unit' => '± share of the learning speed, drawn once per dog', 'source_id' => 'S42', 'confidence' => 'low', 'verified' => true,
                    'ref' => 'medium_mixed_breed.trainability.individual_variation', 'decision' => self::CONFIRMED_R03,
                    'notes' => 'Game value: uniform factor in [0.8, 1.2], seeded per pet and stored (pets.training_learning_factor). S42: individuals vary much more than breeds.',
                ]],
                'lifespan' => [12.0, [
                    'unit' => 'years', 'source_id' => 'S15', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'medium_mixed_breed.lifespan.median_uk_crossbreeds',
                    'quote' => 'This was slightly shorter for crossbred dogs at 12.0 years.',
                ]],
            ],

            BreedType::BorderCollie => [
                'starts_at' => [
                    'young' => $young,
                    'adult' => $adult,
                    'senior' => [118, [
                        'unit' => 'months', 'source_id' => 'S11,S15', 'confidence' => 'medium', 'verified' => true,
                        'ref' => 'border_collie.lifespan.senior_from',
                        'decision' => self::CONFIRMED,
                        'notes' => 'Derived game boundary: last 25 % of lifespan (S11) × median 13.1 y (S15) = 9.8 y = 118 months. Dogs Trust rule of thumb: > 7 y (S14).',
                    ]],
                ],
                'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 118],
                'arrival_meta' => $arrivalMeta,
                'young_per_age_notes' => $youngPerAgeNotes,
                'adult_minutes' => [120, [
                    'unit' => 'minutes/day', 'source_id' => 'S5', 'confidence' => 'high', 'verified' => true,
                    'ref' => 'border_collie.exercise.adult',
                    'quote' => 'Exercise: More than 2 hours per day',
                    'notes' => '"More than 2 hours" → 120 minutes (lower bound).',
                ]],
                'senior_minutes' => [90, $seniorMinutesMeta],
                'adult_weight' => [[13.6, 24.9], [
                    'unit' => 'kg', 'source_id' => 'S4', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'border_collie.adult_weight.akc', 'quote' => 'weigh between 30 and 55 pounds',
                ]],
                'growth_end' => $mediumGrowth('border_collie.growth.adult_weight_reached'),
                'coren_rank' => [1, [
                    'unit' => 'rank', 'source_id' => 'S34', 'confidence' => 'high', 'verified' => true,
                    'ref' => 'border_collie.trainability.coren_rank',
                    'quote' => '190 of the 199 judges ranked the Border Collie in the top 10',
                ]],
                'learning_multiplier' => [2.0, [
                    'unit' => '× mixed-breed learning speed', 'source_id' => 'S34,S35', 'confidence' => 'low', 'verified' => true,
                    'ref' => 'border_collie.trainability.learning_multiplier', 'decision' => self::CONFIRMED_R03,
                    'notes' => 'Game value (no literature factor): Coren rank #1 (S34), "brightest" tier < 5 repetitions vs 25–40 for average dogs (S35, secondary source). Multiplies the progress per correctly timed praise.',
                ]],
                'individual_variation' => null,
                'lifespan' => [13.1, [
                    'unit' => 'years', 'source_id' => 'S15', 'confidence' => 'high', 'verified' => true,
                    'ref' => 'border_collie.lifespan.median_uk',
                    'quote' => 'Border Collie (13.1 years)',
                ]],
            ],

            // M5-R10 (docs/research/dog-data/data.json labrador_retriever, S48–S62;
            // David's decisions 2026-10-09 in proposed_game_parameters.labrador_retriever).
            BreedType::LabradorRetriever => self::labradorProfile(),

            BreedType::DomesticCat, BreedType::MaineCoon => throw new InvalidArgumentException("{$breed->value} is not a dog breed (see catRows())."),
        };
    }

    /**
     * Labrador Retriever (M5-R10). Stage boundaries, arrival ages, exercise
     * minutes and the learning multiplier are David's decisions of 2026-10-09
     * (CONFIRMED_R10); weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function labradorProfile(): array
    {
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S8,S54', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.labrador_retriever.stage_boundaries_months', 'decision' => self::CONFIRMED_R10,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of RAPID growth (~6–9 months, S11), not the end of growth — a large dog still grows until 15–18 months (S10, up to 24 months S8), which ends inside the young stage. Same 9 as the other dogs (alternative 12 not chosen).')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [118, $boundary('Derived game boundary: last 25 % of lifespan (S11) × median 13.1 y (McMillan 2024, S54) = 9.8 y = 118 months. The VetCompass median 12.0 y (S55) would give 108 months (not chosen).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 118],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.labrador_retriever.arrival_age_months', 'decision' => self::CONFIRMED_R10,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (15–18 months for a large dog, S10); capped at the adult 90 minutes, which 10 × 9 months already reaches, so the whole young stage walks the adult minutes.',
            'adult_minutes' => [90, [
                'unit' => 'minutes/day', 'source_id' => 'S59', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.labrador_retriever.exercise_minutes_adult', 'decision' => self::CONFIRMED_R10,
                'quote' => 'Labrador retrievers generally need at least 90 minutes of exercise daily as adults.',
                'notes' => 'Guide Dogs UK lower bound (S59) → 9,000 steps. Sources disagree: RKC "More than 2 hours per day" (S50), Woodgreen 60–90 min (S60).',
            ]],
            // David 2026-10-09: 75 % of 90 = 67.5 min ≈ 6,750 steps. Minutes are whole
            // numbers (StageParamKey::validate, StageRules, API exercise_minutes int) —
            // 68 is data.json's value; 68 × 100 = 6,800 steps (open question to David).
            'senior_minutes' => [68, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.labrador_retriever.exercise_minutes_senior', 'decision' => self::CONFIRMED_R10,
                'notes' => 'Game value (no literature number): 75 % of the adult 90 minutes = 67.5, stored as whole minutes (68 → 6,800 steps; 67.5 → 6,750 needs fractional minutes). Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[24.9, 36.3], [
                'unit' => 'kg', 'source_id' => 'S52', 'confidence' => 'high', 'verified' => true,
                'ref' => 'labrador_retriever.adult_weight.akc',
                'quote' => 'Approximate weight of dogs and bitches in working condition: dogs 65 to 80 pounds; bitches 55 to 70 pounds.',
                'notes' => 'AKC standard, bitches 24.9–31.8 kg and dogs 29.5–36.3 kg → overall range.',
            ]],
            'growth_end' => [[15, 18], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'labrador_retriever.growth.adult_weight_reached',
                'quote' => 'Large (59–99 pounds): 15–18 months',
                'notes' => 'Size-class value (Large), not breed-specific; lighter bitches straddle Medium (12–15 months).',
            ]],
            'coren_rank' => [7, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'labrador_retriever.trainability.coren_rank',
                'quote' => '| 7 | Labrador Retriever |',
                'notes' => 'Coren\'s list as reproduced on Wikipedia (S35); "brightest" tier like the Border Collie.',
            ]],
            'learning_multiplier' => [1.8, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35,S42', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.labrador_retriever.learning_multiplier', 'decision' => self::CONFIRMED_R10,
                'notes' => 'Game value (no literature factor): same Coren "brightest" tier as the Border Collie (S35, 2.0); 1.8 keeps the Border Collie visibly fastest. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the Border Collie: no individual variation row (data.json notes
            // S42 would allow ±20 %, but David decided only the multiplier).
            'individual_variation' => null,
            'lifespan' => [13.1, [
                'unit' => 'years', 'source_id' => 'S54', 'confidence' => 'high', 'verified' => true,
                'ref' => 'labrador_retriever.lifespan.median_uk',
                'quote' => 'Labrador Retriever (orange, x̃= 13.1)',
                'notes' => 'McMillan et al. 2024 (S54); VetCompass 2018 gives 12.0 y (S55).',
            ]],
        ];
    }

    /**
     * Dog rows (rows()) followed by the cat rows (catRows()) — what run() seeds.
     *
     * @return list<array<string, mixed>>
     */
    public static function allRows(): array
    {
        return array_merge(self::rows(), self::catRows());
    }

    /**
     * Cat life-stage data (M5-R06-03): every value from
     * docs/research/cat-data/data.json (`ref` = self::CAT_REF + JSON path).
     * `verified` = true when the entry has a source (C-id) or a recorded David
     * decision (CAT_SPEC Q1–Q10 13:47, the M5-R06 plan answers, or the play
     * answers of 20:40 — play_min_gap_minutes, which R06-03 seeded as an
     * unverified proposal; 2026_10_26_130000 flips the existing rows).
     * Only values data.json gives are seeded: no exercise / step keys (cats
     * have no steps), no senior sleep hours (no entry), no grooming row for
     * the domestic cat (no grooming routine, CAT_SPEC Q8).
     *
     * @return list<array<string, mixed>>
     */
    public static function catRows(): array
    {
        $rows = [];

        foreach ([BreedType::DomesticCat, BreedType::MaineCoon] as $breed) {
            $mc = $breed === BreedType::MaineCoon;
            $slug = $breed->slug();
            $add = function (string $stage, int $from, StageParamKey $key, mixed $value, array $meta) use (&$rows, $slug): void {
                $rows[] = array_merge([
                    'breed_slug' => $slug,
                    'stage' => $stage,
                    'age_from_months' => $from,
                    'key' => $key->value,
                    'value' => $value,
                    'unit' => null,
                    'source_id' => null,
                    'confidence' => 'low',
                    'verified' => false,
                    'quote' => null,
                    'notes' => null,
                    'ref' => null,
                    'decision' => null,
                ], $meta, ['ref' => isset($meta['ref']) ? self::CAT_REF.$meta['ref'] : null]);
            };

            // ── Stage boundaries (AAHA/AAFP 2021 feline life stages, C1) ──
            $add('puppy', 0, StageParamKey::StartsAtMonths, 0, [
                'unit' => 'months', 'source_id' => 'C1', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general.life_stages.kitten',
                'quote' => 'kitten, from birth up to 1 year old',
                'notes' => 'Kitten (CAT_SPEC §2); stored as stage puppy.',
            ]);
            foreach ([
                ['young', 12, 'young_adult', 'young adult, 1-6 years old', 'Young cat; stored as stage young.'],
                ['adult', 84, 'mature_adult', 'mature adult, 7-10 years old', 'Mature cat; stored as stage adult.'],
                ['senior', 120, 'senior', 'senior, 10 years old and up', 'Senior cat. The guideline ranges overlap at 10 years; unlike dogs the boundary is NOT 0.75 × lifespan.'],
            ] as [$stage, $month, $ref, $quote, $note]) {
                $add($stage, 0, StageParamKey::StartsAtMonths, $month, [
                    'unit' => 'months', 'source_id' => 'C1', 'confidence' => 'high', 'verified' => true,
                    'ref' => 'general.life_stages.'.$ref, 'decision' => self::CONFIRMED_CAT,
                    'quote' => $quote, 'notes' => $note,
                ]);
            }

            // ── Age at arrival (CAT_SPEC Q4) ─────────────────────────────────
            $add('puppy', 0, StageParamKey::ArrivalAgeMonths, $mc ? 3 : 2, $mc ? [
                'unit' => 'months', 'source_id' => 'C13', 'confidence' => 'high', 'verified' => true,
                'ref' => 'maine_coon.arrival_age_kitten', 'decision' => self::CONFIRMED_CAT,
                'quote' => 'Pedigree kittens are usually rehomed over 12-13 weeks old',
            ] : [
                'unit' => 'months', 'source_id' => 'C13', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general.arrival_age.kitten_min', 'decision' => self::CONFIRMED_CAT,
                'quote' => 'Kittens shouldn\'t be rehomed until they\'re at least 8 weeks old',
            ]);
            foreach (['young' => 12, 'adult' => 84, 'senior' => 120] as $stage => $age) {
                $add($stage, 0, StageParamKey::ArrivalAgeMonths, $age, [
                    'unit' => 'months', 'verified' => true,
                    'ref' => 'general.arrival_age.'.$stage, 'decision' => self::CONFIRMED_CAT,
                    'notes' => 'Game value (no literature number): first month of the stage, as for dogs.',
                ]);
            }

            // ── Meals per day (counts sourced, C2 / C3) ──────────────────────
            $add('puppy', 0, StageParamKey::MealsPerDay, 4, [
                'unit' => 'meals/day', 'source_id' => 'C3', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general.meals_per_day.kitten_6_12_weeks',
                'quote' => '6-12 weeks old - 4 meals per day',
            ]);
            $add('puppy', 3, StageParamKey::MealsPerDay, 3, [
                'unit' => 'meals/day', 'source_id' => 'C2,C3', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general.meals_per_day.kitten_3_6_months',
                'quote' => 'Until they are six months old, kittens will usually do best when fed three meals a day / 3-6 months old - 2-3 meals per day',
            ]);
            $add('puppy', 6, StageParamKey::MealsPerDay, 2, [
                'unit' => 'meals/day', 'source_id' => 'C2,C3', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general.meals_per_day.kitten_6_12_months',
                'quote' => 'twice daily feeding is generally best / 6-9 months old - 2 meals per day',
                'notes' => 'Two meals: no feed_windows row — the breed_configs windows 06–10 / 17–21 apply (the data.json windows of this band).',
            ]);
            foreach (['young', 'adult'] as $stage) {
                $add($stage, 0, StageParamKey::MealsPerDay, 2, [
                    'unit' => 'meals/day', 'source_id' => 'C2', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'general.meals_per_day.adult', 'decision' => self::CONFIRMED_CAT_PLAN,
                    'quote' => 'feeding once or twice a day is appropriate in most cases',
                    'notes' => 'Game simplification: the upper end; guidelines also favour many small meals (C8, C9).',
                ]);
            }
            $add('senior', 0, StageParamKey::MealsPerDay, 2, [
                'unit' => 'meals/day', 'source_id' => 'C2', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general.meals_per_day.senior',
                'quote' => 'should maintain the same feeding regimen',
            ]);

            // ── Feed window clock times (CAT_SPEC §3, dog clock rule) ────────
            // The windows are listed in the notes of the meal entries of data.json.
            $windowsMeta = fn (string $ref): array => [
                'unit' => 'HH:MM family-local [start, end)', 'verified' => true,
                'ref' => 'general.meals_per_day.'.$ref, 'decision' => self::CONFIRMED_CAT,
                'notes' => 'Game clock times (no literature number; the meal COUNT is sourced, C3 / C2). CAT_SPEC §3 (approved by David 2026-10-08 13:47) lists these windows — the dog clock rule of 2026-10-05 (first window 07:00, last 19:00, equal spacing, 2 h each). A window entirely inside quiet hours is done by the parent.',
            ];
            $add('puppy', 0, StageParamKey::FeedWindows, self::PUPPY_4_MEAL_WINDOWS, $windowsMeta('kitten_6_12_weeks'));
            $add('puppy', 3, StageParamKey::FeedWindows, self::PUPPY_3_MEAL_WINDOWS, $windowsMeta('kitten_3_6_months'));

            // ── Sleep (videos / behaviour only, not a rule; C12) ─────────────
            $add('puppy', 0, StageParamKey::SleepHours, null, [
                'unit' => 'hours/day', 'source_id' => 'C12', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general.sleep.kitten', 'quote' => 'up to 20 hours',
                'notes' => 'Source gives only a maximum ("up to 20 h"), no [min, max] range → null.',
            ]);
            foreach (['young', 'adult'] as $stage) {
                $add($stage, 0, StageParamKey::SleepHours, [12, 16], [
                    'unit' => 'hours/day', 'source_id' => 'C12', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'general.sleep.adult', 'quote' => 'Cats sleep between 12–16 hours a day',
                ]);
            }

            // ── Play (replaces the walk; rules in M5-R06-04) ─────────────────
            $add('puppy', 0, StageParamKey::PlaySessionsPerDay, 3, [
                'unit' => 'wand play sessions/day', 'source_id' => 'C10', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general.play.game_sessions_kitten', 'decision' => self::CONFIRMED_CAT,
                'notes' => 'C10: 2–3 sessions of 10–15 min a day, kittens more. One ~60 s mini-game counts as one real session.',
            ]);
            foreach (['young', 'adult', 'senior'] as $stage) {
                $add($stage, 0, StageParamKey::PlaySessionsPerDay, 2, [
                    'unit' => 'wand play sessions/day', 'source_id' => 'C10', 'confidence' => 'low', 'verified' => true,
                    'ref' => 'general.play.game_sessions_adult', 'decision' => self::CONFIRMED_CAT,
                    'notes' => 'C10: 2–3 sessions of 10–15 min a day. One ~60 s mini-game counts as one real session.',
                ]);
            }
            $add('all', 0, StageParamKey::PlayMinGapMinutes, 120, [
                'unit' => 'minutes between two play sessions', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general.play.game_min_gap', 'decision' => self::CONFIRMED_CAT_PLAY,
                'notes' => 'Game value (no literature number), CAT_SPEC §5.2: at least 2 h between two successful wand sessions, measured from the last successful one, so the sessions spread over the day (C11: several short sessions through the day).',
            ]);
            $add('all', 0, StageParamKey::ScratchingAfterMissedPlay, true, [
                'unit' => 'bool: a missed play day → "scratched the sofa" the next day (max 1/day)', 'source_id' => 'C8,C22', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general.scratching.game_trigger', 'decision' => self::CONFIRMED_CAT,
                'notes' => 'No frequency source (boredom → unwanted behaviour, C8 / C22). Only after a missed play routine, never random for kittens; no illness from missed play (general.play.missed_play_illness).',
            ]);

            // ── Litter (replaces poop events; rules in M5-R06-05) ────────────
            $add('puppy', 0, StageParamKey::LitterUsesPerDay, 3, [
                'unit' => 'litter uses/day outside quiet hours', 'source_id' => 'C6', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general.litter.game_uses_per_day_kitten', 'decision' => self::CONFIRMED_CAT,
                'notes' => 'C6: kittens poo 1–6 (young) / 1–3 (older) times a day. Each use = one "scoop the litter" routine; a use is not a mess.',
            ]);
            foreach (['young', 'adult', 'senior'] as $stage) {
                $add($stage, 0, StageParamKey::LitterUsesPerDay, 2, [
                    'unit' => 'litter uses/day outside quiet hours', 'source_id' => 'C6', 'confidence' => 'low', 'verified' => true,
                    'ref' => 'general.litter.game_uses_per_day_adult', 'decision' => self::CONFIRMED_CAT,
                    'notes' => 'C6: adults pee 2–4 and poo 1–2 times a day. Each use = one "scoop the litter" routine; a use is not a mess.',
                ]);
            }
            $add('all', 0, StageParamKey::LitterScoopDeadlineHours, 4, [
                'unit' => 'hours outside quiet hours', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general.litter.game_scoop_deadline', 'decision' => self::CONFIRMED_CAT,
                'notes' => 'Game value (no literature number — sources give only the scooping frequency, C5–C7). On expiry: a mess next to the tray → the dog hygiene ladder. (While the weekly change is overdue the deadline is 2 h — general.litter.game_overdue_change_deadline, rule in M5-R06-05.)',
            ]);
            $add('all', 0, StageParamKey::LitterFullChangeDays, 7, [
                'unit' => 'days (one full change per program week)', 'source_id' => 'C5,C6,C7', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general.litter.full_change', 'decision' => self::CONFIRMED_CAT,
                'quote' => 'Dump everything, wash with a mild detergent and refill at least once a week',
            ]);

            // ── Breed level ─────────────────────────────────────────────────
            if ($mc) {
                $add('all', 0, StageParamKey::GroomingSessionsPerWeek, 3, [
                    'unit' => 'combing sessions/week (≥ 1 day apart)', 'source_id' => 'C17', 'confidence' => 'low', 'verified' => true,
                    'ref' => 'maine_coon.grooming.game_sessions_per_week', 'decision' => self::CONFIRMED_CAT,
                    'notes' => 'Sources disagree: TICA daily for thick coats, CFA a couple of times a week (C17), Vetstreet weekly (C18). Matted coat after 2 missed sessions (maine_coon.grooming.game_matted_after_missed, rule in M5-R06-05).',
                ]);
            }
            $add('all', 0, StageParamKey::LifespanYears, $mc ? 9.71 : 11.89, [
                'unit' => 'years (life expectancy at birth, UK)', 'source_id' => 'C14', 'confidence' => 'high', 'verified' => true,
                'ref' => ($mc ? 'maine_coon' : 'domestic_cat').'.lifespan.expectancy_at_birth',
                'quote' => $mc ? 'Maine Coon 9.71 y (8.42–11.00)' : 'crossbred 11.89 y (11.76–12.03)',
                'notes' => 'Background for parents and appearance only; the senior boundary comes from C1, not from the lifespan.',
            ]);
        }

        return $rows;
    }

    /**
     * Run the database seeds (insert-only).
     */
    public function run(): void
    {
        $now = now();
        $breeds = DB::table('breed_configs')->pluck('breed_slug')->all();
        $touched = self::touchedTuples();

        foreach (self::allRows() as $row) {
            if (! in_array($row['breed_slug'], $breeds, true)) {
                continue;
            }
            if (isset($touched[self::tupleKey($row['breed_slug'], $row['stage'], (int) $row['age_from_months'], $row['key'])])) {
                continue; // an admin edited / re-keyed / deleted it: never re-insert
            }

            $key = StageParamKey::from($row['key']);
            if (($error = $key->validate($row['value'])) !== null) {
                throw new InvalidArgumentException("BreedStageParamsSeeder: {$row['breed_slug']}.{$row['stage']}.{$row['key']}: {$error}");
            }

            DB::table('breed_stage_params')->insertOrIgnore(array_merge([
                'breed_slug' => $row['breed_slug'],
                'stage' => $row['stage'],
                'age_from_months' => $row['age_from_months'],
                'key' => $row['key'],
            ], self::columns($row), [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        // QA m2: raw inserts bypass the model hooks that forget the rules
        // cache — clear it for every breed so new rows apply right away.
        foreach ($breeds as $slug) {
            LifeStageService::forgetBreed($slug);
        }
    }

    /**
     * The stored columns of one seeder row except the tuple (value as JSON,
     * the decision folded into `notes`). Shared with the data migration that
     * applied David's 2026-10-05 decisions to existing rows.
     *
     * @param  array<string, mixed>  $row
     * @return array{value: string|null, unit: string|null, source_id: string|null, confidence: string, verified: bool, quote: string|null, notes: string|null, data_ref: string|null}
     */
    public static function columns(array $row): array
    {
        $notes = trim(implode(' ', array_filter([
            $row['decision'] !== null ? "Decision: {$row['decision']}." : null,
            $row['notes'],
        ])));

        return [
            'value' => $row['value'] === null ? null : json_encode($row['value']),
            'unit' => $row['unit'],
            'source_id' => $row['source_id'],
            'confidence' => $row['confidence'],
            'verified' => (bool) $row['verified'],
            'quote' => $row['quote'],
            'notes' => $notes !== '' ? $notes : null,
            'data_ref' => $row['ref'],
        ];
    }

    /**
     * Every (breed, stage, from month, key) an admin touched, from the audit
     * table: the tuple of each change row, and for updates also the tuple
     * before the change (`old` holds the original stage / from / key when
     * they were changed).
     *
     * @return array<string, true>
     */
    public static function touchedTuples(): array
    {
        $touched = [];
        foreach (DB::table('breed_stage_param_changes')->get(['breed_slug', 'stage', 'age_from_months', 'key', 'action', 'old']) as $change) {
            $touched[self::tupleKey($change->breed_slug, $change->stage, (int) $change->age_from_months, $change->key)] = true;

            $old = is_string($change->old) ? json_decode($change->old, true) : null;
            if ($change->action === 'updated' && is_array($old)) {
                $touched[self::tupleKey(
                    (string) ($old['breed_slug'] ?? $change->breed_slug),
                    (string) ($old['stage'] ?? $change->stage),
                    (int) ($old['age_from_months'] ?? $change->age_from_months),
                    (string) ($old['key'] ?? $change->key),
                )] = true;
            }
        }

        return $touched;
    }

    private static function tupleKey(string $breed, string $stage, int $from, string $key): string
    {
        return $breed.'|'.$stage.'|'.$from.'|'.$key;
    }
}
