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
 * the mutt / Border Collie rows stay byte-identical (LabradorBreedTest /
 * GoldenRetrieverBreedTest / FrenchBulldogBreedTest / GermanShepherdBreedTest /
 * CavalierKingCharlesSpanielBreedTest / BeagleBreedTest / StandardPoodleBreedTest /
 * DachshundBreedTest / AustralianShepherdBreedTest / HavaneseBreedTest /
 * WestHighlandWhiteTerrierBreedTest cross-check the Labrador / Golden / French Bulldog /
 * German Shepherd / Cavalier / Beagle / Standard Poodle / Dachshund / Australian Shepherd /
 * Havanese / West Highland White Terrier rows against data.json).
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
     * exercise 90 min, senior 68 min (75 % of 90 rounded up, 6,800 steps),
     * stages 9 / 36 / 118, arrival 2 / 9 / 36 / 118, learning multiplier 1.8;
     * hunger / thirst / poops / water the same as the Border Collie.
     * M5-R10-02 Golden Retriever (same day, ~22:40, data.json
     * proposed_game_parameters.golden_retriever.*.decision): exercise 120 min,
     * senior 90 min, stages 9 / 36 / 119, arrival 2 / 9 / 36 / 119, learning
     * multiplier 1.9; care rates the Border Collie's.
     */
    public const CONFIRMED_R10 = 'potrdil David 2026-10-09';

    /**
     * M5-R10-03 French Bulldog (David 2026-10-10 ~06:20, data.json
     * proposed_game_parameters.french_bulldog.*.decision): exercise 60 min,
     * senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 88,
     * arrival 2 / 9 / 36 / 88, learning multiplier 0.7; care rates the
     * Border Collie's.
     */
    public const CONFIRMED_R10_FRENCH_BULLDOG = 'potrdil David 2026-10-10';

    /**
     * M5-R10-04 German Shepherd Dog (unattended run 2026-10-10, data.json
     * proposed_game_parameters.german_shepherd.*.decision): every value follows
     * a standing rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David
     * confirmed on 2026-10-10 — exercise 120 min, senior 90 min, puppy 10 min ×
     * age capped at 120, stages 9 / 36 / 93, arrival 2 / 9 / 36 / 93, learning
     * multiplier 1.9; care rates the Border Collie's.
     */
    public const CONFIRMED_R10_GERMAN_SHEPHERD = 'potrdil David 2026-10-10 (pravilo runbooka)';

    /**
     * M5-R10-05 Cavalier King Charles Spaniel (unattended run 2026-10-10, data.json
     * proposed_game_parameters.cavalier_king_charles_spaniel.*.decision): every value
     * follows a standing rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David
     * confirmed on 2026-10-10 — exercise 60 min, senior 45 min, puppy 10 min × age
     * capped at 60, stages 9 / 36 / 90, arrival 2 / 9 / 36 / 90, learning multiplier
     * 1.0; care rates the Border Collie's. The suffix keeps the value distinct from
     * CONFIRMED_R10_GERMAN_SHEPHERD, so each breed's decision rows stay identifiable.
     */
    public const CONFIRMED_R10_CAVALIER = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-05)';

    /**
     * M5-R10-06 Beagle (unattended run 2026-10-10, data.json
     * proposed_game_parameters.beagle.*.decision): every value follows a standing rule
     * of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 60 min, senior 45 min, puppy 10 min × age capped at 60, stages
     * 9 / 36 / 102, arrival 2 / 9 / 36 / 102, learning multiplier 0.5; care rates the
     * Border Collie's. The suffix keeps each breed's decision rows identifiable.
     */
    public const CONFIRMED_R10_BEAGLE = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-06)';

    /**
     * M5-R10-07 Standard Poodle (unattended run 2026-10-10, data.json
     * proposed_game_parameters.standard_poodle.*.decision): every value follows a standing
     * rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 60 min, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 108,
     * arrival 2 / 9 / 36 / 108, learning multiplier 2.0; care rates the Border Collie's.
     */
    public const CONFIRMED_R10_STANDARD_POODLE = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-07)';

    /**
     * M5-R10-08 Dachshund, standard size (unattended run 2026-10-10, data.json
     * proposed_game_parameters.dachshund.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 60 min, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 108,
     * arrival 2 / 9 / 36 / 108, learning multiplier 1.0; care rates the Border Collie's.
     */
    public const CONFIRMED_R10_DACHSHUND = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-08)';

    /**
     * M5-R10-09 Australian Shepherd (unattended run 2026-10-10, data.json
     * proposed_game_parameters.australian_shepherd.*.decision): every value follows a standing rule
     * of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 120 min, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 90,
     * arrival 2 / 9 / 36 / 90, learning multiplier 1.0; care rates the Border Collie's.
     */
    public const CONFIRMED_R10_AUSTRALIAN_SHEPHERD = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-09)';

    /**
     * M5-R10-10 Havanese (unattended run 2026-10-10, data.json
     * proposed_game_parameters.havanese.*.decision): every value follows a standing rule
     * of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 30 min, senior 23 min, puppy 10 min × age capped at 30, stages 9 / 36 / 108,
     * arrival 2 / 9 / 36 / 108, learning multiplier 1.0 (unranked); care rates the Border Collie's.
     */
    public const CONFIRMED_R10_HAVANESE = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-10)';

    /**
     * M5-R10-11 West Highland White Terrier (unattended run 2026-10-10, data.json
     * proposed_game_parameters.west_highland_white_terrier.*.decision): every value follows a
     * standing rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on
     * 2026-10-10 — exercise 60 min, senior 45 min, puppy 10 min × age capped at 60, stages
     * 9 / 36 / 121, arrival 2 / 9 / 36 / 121, learning multiplier 1.0 (Coren 47, Average);
     * care rates the Border Collie's.
     */
    public const CONFIRMED_R10_WEST_HIGHLAND_WHITE_TERRIER = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-11)';

    /** Dog breeds of rows(), in seeding order (M5-R10: one profile per breed, dogProfile()). */
    public const DOG_BREEDS = [BreedType::Mutt, BreedType::BorderCollie, BreedType::LabradorRetriever, BreedType::GoldenRetriever, BreedType::FrenchBulldog, BreedType::GermanShepherd, BreedType::CavalierKingCharlesSpaniel, BreedType::Beagle, BreedType::StandardPoodle, BreedType::Dachshund, BreedType::AustralianShepherd, BreedType::Havanese, BreedType::WestHighlandWhiteTerrier];

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

            // M5-R10-02 (docs/research/dog-data/data.json golden_retriever, S63–S75;
            // David's decisions 2026-10-09 in proposed_game_parameters.golden_retriever).
            BreedType::GoldenRetriever => self::goldenProfile(),

            // M5-R10-03 (docs/research/dog-data/data.json french_bulldog, S76–S94;
            // David's decisions 2026-10-10 in proposed_game_parameters.french_bulldog).
            BreedType::FrenchBulldog => self::frenchBulldogProfile(),

            // M5-R10-04 (docs/research/dog-data/data.json german_shepherd, S95–S102;
            // runbook rules in proposed_game_parameters.german_shepherd).
            BreedType::GermanShepherd => self::germanShepherdProfile(),

            // M5-R10-05 (docs/research/dog-data/data.json cavalier_king_charles_spaniel,
            // S103–S110; runbook rules in proposed_game_parameters.cavalier_king_charles_spaniel).
            BreedType::CavalierKingCharlesSpaniel => self::cavalierProfile(),

            // M5-R10-06 (docs/research/dog-data/data.json beagle, S111–S117; runbook
            // rules in proposed_game_parameters.beagle).
            BreedType::Beagle => self::beagleProfile(),

            // M5-R10-07 (docs/research/dog-data/data.json standard_poodle, S118–S123; runbook
            // rules in proposed_game_parameters.standard_poodle).
            BreedType::StandardPoodle => self::standardPoodleProfile(),

            // M5-R10-08 (docs/research/dog-data/data.json dachshund, S124–S130; runbook
            // rules in proposed_game_parameters.dachshund).
            BreedType::Dachshund => self::dachshundProfile(),

            // M5-R10-09 (docs/research/dog-data/data.json australian_shepherd, S131–S135; runbook
            // rules in proposed_game_parameters.australian_shepherd).
            BreedType::AustralianShepherd => self::australianShepherdProfile(),

            // M5-R10-10 (docs/research/dog-data/data.json havanese, S136–S141; runbook
            // rules in proposed_game_parameters.havanese).
            BreedType::Havanese => self::havaneseProfile(),

            // M5-R10-11 (docs/research/dog-data/data.json west_highland_white_terrier,
            // S142–S149; runbook rules in proposed_game_parameters.west_highland_white_terrier).
            BreedType::WestHighlandWhiteTerrier => self::westHighlandWhiteTerrierProfile(),

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
            // David 2026-10-09: 75 % of 90 = 67.5 min, stored as whole minutes
            // (StageParamKey::validate, StageRules, API exercise_minutes int) →
            // 68 min = 6,800 steps (confirmed by David 2026-10-09 ~21:00).
            'senior_minutes' => [68, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.labrador_retriever.exercise_minutes_senior', 'decision' => self::CONFIRMED_R10,
                'notes' => 'Game value (no literature number): 75 % of the adult 90 minutes = 67.5, rounded to whole minutes → 68 min = 6,800 steps (David confirmed 68, 2026-10-09). Sources only say "frequent short walks instead of one long one" (S14).',
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
     * Golden Retriever (M5-R10-02). Stage boundaries, arrival ages, exercise
     * minutes and the learning multiplier are David's decisions of 2026-10-09
     * (CONFIRMED_R10); weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function goldenProfile(): array
    {
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S8,S15', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.golden_retriever.stage_boundaries_months', 'decision' => self::CONFIRMED_R10,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of RAPID growth (~6–9 months, S11), not the end of growth — a large dog still grows until 15–18 months (S10, up to 24 months S8), which ends inside the young stage. Same 9 as the other dogs (alternative 12 not chosen).')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [119, $boundary('Derived game boundary: last 25 % of lifespan (S11) × 13.2 y (Dogs Trust summary of McMillan 2024, S15) = 9.9 y = 118.8 → 119 months. Not chosen: 112 months (VetCompass poster median 12.48 y, S71) and 118 (= Labrador / Border Collie).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 119],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.golden_retriever.arrival_age_months', 'decision' => self::CONFIRMED_R10,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (15–18 months for a large dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months (9 months → 90 min, from 12 months the adult 120).',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S68,S65', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.golden_retriever.exercise_minutes_adult', 'decision' => self::CONFIRMED_R10,
                'quote' => 'Your Golden Retriever will need a minimum of two hours of good exercise per day.',
                'notes' => 'PDSA "a minimum of two hours" (S68) and RKC "Exercise: More than 2 hours per day" (S65) → lower bound 120 min = 12,000 steps. Woodgreen 60–90 min (S69) not chosen.',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.golden_retriever.exercise_minutes_senior', 'decision' => self::CONFIRMED_R10,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[24.9, 34.0], [
                'unit' => 'kg', 'source_id' => 'S67', 'confidence' => 'high', 'verified' => true,
                'ref' => 'golden_retriever.adult_weight.akc',
                'quote' => 'Weight for dogs 65 to 75 pounds; bitches 55 to 65 pounds.',
                'notes' => 'AKC standard, bitches 24.9–29.5 kg and dogs 29.5–34.0 kg → overall range (PDSA S68 / Woodgreen S69: 25–34 kg).',
            ]],
            'growth_end' => [[15, 18], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'golden_retriever.growth.adult_weight_reached',
                'quote' => 'Large (59–99 pounds): 15–18 months',
                'notes' => 'Size-class value (Large), not breed-specific; lighter bitches straddle Medium (12–15 months).',
            ]],
            'coren_rank' => [4, [
                'unit' => 'rank', 'source_id' => 'S34', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'golden_retriever.trainability.coren_rank',
                'quote' => 'Golden Retriever',
                'notes' => 'Coren\'s top-10 list (S34, list entry no. 4); Wikipedia (S35) "| 4 | Golden Retriever |". "Brightest" tier like the Border Collie and the Labrador.',
            ]],
            'learning_multiplier' => [1.9, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S34,S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.golden_retriever.learning_multiplier', 'decision' => self::CONFIRMED_R10,
                'notes' => 'Game value (no literature factor): Coren rank 4 between the Border Collie (rank 1 → 2.0) and the Labrador (rank 7 → 1.8), linear by rank → 1.9. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the Border Collie and the Labrador: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [13.2, [
                'unit' => 'years', 'source_id' => 'S15', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'golden_retriever.lifespan.median_uk',
                'quote' => 'Golden Retriever (13.2 years)',
                'notes' => 'Dogs Trust summary of McMillan et al. 2024 (S15); VetCompass poster 2012 gives a median of 12.48 y (S71).',
            ]],
        ];
    }

    /**
     * French Bulldog (M5-R10-03). Stage boundaries, arrival ages, exercise
     * minutes and the learning multiplier are David's decisions of 2026-10-10
     * (CONFIRMED_R10_FRENCH_BULLDOG); weight, growth, Coren rank and lifespan
     * are sourced.
     *
     * @return array<string, mixed>
     */
    private static function frenchBulldogProfile(): array
    {
        $decided = self::CONFIRMED_R10_FRENCH_BULLDOG;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S54,S15', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.french_bulldog.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small / medium dog finishes growing at 9–15 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [88, $boundary('Derived game boundary: last 25 % of lifespan (S11) × median 9.8 y (McMillan 2024, S54; Dogs Trust summary S15) = 7.35 y = 88.2 → 88 months. Not chosen: 90 (0.75 × RKC "over 10 years", S78) and 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 88],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.french_bulldog.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–15 months for a small / medium dog, S10); capped at the adult 60 minutes, which 10 × age already reaches at 6 months, so the whole young stage walks the adult minutes.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S78,S81', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.french_bulldog.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S78) = PDSA "up to an hour" (S81) → 60 min = 6,000 steps (also Woodgreen\'s lower bound of 60–90 min, S82; 90 not chosen given the breathing / heat advice).',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.french_bulldog.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[8.0, 14.0], [
                'unit' => 'kg', 'source_id' => 'S76', 'confidence' => 'high', 'verified' => true,
                'ref' => 'french_bulldog.adult_weight.fci',
                'quote' => 'Males: 9–14 kg. Females: 8–13 kg.',
                'notes' => 'FCI standard, females 8–13 kg and males 9–14 kg → overall range (Woodgreen S82: 8–14 kg; RKC ideal 11 / 12.5 kg, S79).',
            ]],
            'growth_end' => [[9, 15], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'low', 'verified' => true,
                'ref' => 'french_bulldog.growth.adult_weight_reached',
                'quote' => 'Medium (24–59 pounds): 12–15 months',
                'notes' => 'Size-class values, not breed-specific: the FCI weights straddle Small (9–12 months) and Medium (12–15 months) of S10.',
            ]],
            'coren_rank' => [58, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'french_bulldog.trainability.coren_rank',
                'quote' => '| 58 | French Bulldog |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), tier "Fair" (ranks 55–69). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [0.7, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35,S81,S82', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.french_bulldog.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 58 (S35) is in the "Fair" tier, one tier below "Average" (mixed breed 1.0); ~0.3 per tier step → 0.7. PDSA (S81) "easy to train … strong-willed" and Woodgreen (S82) "Moderately easy" were the counterweight (alternative 0.8, not chosen). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [9.8, [
                'unit' => 'years', 'source_id' => 'S54', 'confidence' => 'high', 'verified' => true,
                'ref' => 'french_bulldog.lifespan.median_uk',
                'quote' => 'French Bulldog (red, x̃= 9.8)',
                'notes' => 'McMillan et al. 2024 (S54); Dogs Trust summary (S15). Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * German Shepherd Dog (M5-R10-04). Stage boundaries, arrival ages, exercise
     * minutes and the learning multiplier follow the runbook's standing rules
     * (CONFIRMED_R10_GERMAN_SHEPHERD); weight, growth, Coren rank and lifespan
     * are sourced.
     *
     * @return array<string, mixed>
     */
    private static function germanShepherdProfile(): array
    {
        $decided = self::CONFIRMED_R10_GERMAN_SHEPHERD;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S101', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.german_shepherd.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a large dog finishes growing at 15–18 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [93, $boundary('Derived game boundary: last 25 % of lifespan (S11) × VetCompass median 10.3 y (O\'Neill 2017, S101) = 7.725 y = 92.7 → 93 months. Not chosen: 90 (0.75 × RKC "over 10 years", S97) and 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 93],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.german_shepherd.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (15–18 months for a large dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S97,S100', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.german_shepherd.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: More than 2 hours per day',
                'notes' => 'RKC "More than 2 hours per day" (S97) = PDSA "a minimum of two hours" (S100) → 120 min = 12,000 steps (runbook rule: "more than N" → N).',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.german_shepherd.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[22.0, 40.0], [
                'unit' => 'kg', 'source_id' => 'S95', 'confidence' => 'high', 'verified' => true,
                'ref' => 'german_shepherd.adult_weight.fci',
                'quote' => 'Males: Weight: 30 kg to 40 kg; Females: Weight: 22 kg to 32 kg',
                'notes' => 'FCI standard, females 22–32 kg and males 30–40 kg → overall range (PDSA average 35–43 kg, S100; VetCompass medians ♂ 40.1 / ♀ 34.8 kg, S101).',
            ]],
            'growth_end' => [[15, 18], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'german_shepherd.growth.adult_weight_reached',
                'quote' => 'Large (59–99 pounds): 15–18 months',
                'notes' => 'Size-class value, not breed-specific (males are Large; females straddle Medium / Large — same as the Labrador).',
            ]],
            'coren_rank' => [3, [
                'unit' => 'rank', 'source_id' => 'S34', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'german_shepherd.trainability.coren_rank',
                'quote' => 'German Shepherd Dog',
                'notes' => 'Coren\'s own top-10 list (S34): 3rd, tier "Brightest" (ranks 1–10, S35).',
            ]],
            'learning_multiplier' => [1.9, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S34,S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.german_shepherd.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Brightest tier rule max(1.8, round(2.0 − (rank − 1) / 30, 1)) with rank 3 → 1.9 (= Golden Retriever, rank 4). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [10.3, [
                'unit' => 'years', 'source_id' => 'S101', 'confidence' => 'high', 'verified' => true,
                'ref' => 'german_shepherd.lifespan.median_uk',
                'quote' => 'The median longevity of GSDs overall was 10.3 years',
                'notes' => 'O\'Neill et al. 2017, VetCompass (S101); RVC news S102. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Cavalier King Charles Spaniel (M5-R10-05). Stage boundaries, arrival ages,
     * exercise minutes and the learning multiplier follow the runbook's standing
     * rules (CONFIRMED_R10_CAVALIER); weight, growth, Coren rank and lifespan are
     * sourced.
     *
     * @return array<string, mixed>
     */
    private static function cavalierProfile(): array
    {
        $decided = self::CONFIRMED_R10_CAVALIER;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S71', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.cavalier_king_charles_spaniel.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small dog finishes growing at 9–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [90, $boundary('Derived game boundary: last 25 % of lifespan (S11) × VetCompass median 9.99 y (O\'Neill et al. SVEPM 2012 poster, S71) = 7.49 y = 89.9 → 90 months. Not chosen: 108 (0.75 × RKC "over 12 years", S105) and 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 90],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.cavalier_king_charles_spaniel.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–12 months for a small dog, S10); capped at the adult 60 minutes, which 10 × age already reaches at 6 months, so the whole young stage walks the adult minutes.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S105,S108', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.cavalier_king_charles_spaniel.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S105) = PDSA "at least one hour" (S108) → 60 min = 6,000 steps (runbook rule: "up to N" / "at least N" → N).',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.cavalier_king_charles_spaniel.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[5.4, 8.0], [
                'unit' => 'kg', 'source_id' => 'S103', 'confidence' => 'high', 'verified' => true,
                'ref' => 'cavalier_king_charles_spaniel.adult_weight.fci',
                'quote' => 'WEIGHT: 5,4 - 8 kg.',
                'notes' => 'FCI standard, one range for both sexes (RKC standard 5.4–8.2 kg, S106; PDSA average 5.4–8.2 kg, S108).',
            ]],
            'growth_end' => [[9, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'cavalier_king_charles_spaniel.growth.adult_weight_reached',
                'quote' => 'Small (12–24 pounds): 9–12 months',
                'notes' => 'Size-class value, not breed-specific (5.4–8.2 kg = 12–18 lb, the Small class of S10).',
            ]],
            'coren_rank' => [44, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'cavalier_king_charles_spaniel.trainability.coren_rank',
                'quote' => '44 | Cavalier King Charles Spaniel',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), tier "Average" (ranks 40–54). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.cavalier_king_charles_spaniel.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 44 (S35) is in the "Average" tier → 1.0, the same speed as the mixed breed (PDSA S108: "fairly easy to train"). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [9.99, [
                'unit' => 'years', 'source_id' => 'S71', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'cavalier_king_charles_spaniel.lifespan.median_uk',
                'quote' => 'Cavalier King Charles Spaniel 121 9.99 8.14-12.39',
                'notes' => 'O\'Neill et al. VetCompass poster (SVEPM 2012, S71): median longevity of 121 deaths. McMillan 2024 (S54) gives no reachable Cavalier value. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Beagle (M5-R10-06). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_BEAGLE);
     * weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function beagleProfile(): array
    {
        $decided = self::CONFIRMED_R10_BEAGLE;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S116', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.beagle.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small dog finishes growing at 9–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [102, $boundary('Derived game boundary: last 25 % of lifespan (S11) × VetCompass median age at death 11.28 y (O\'Neill et al. 2025, S116) = 8.46 y = 101.5 → 102 months. Not chosen: 105 (0.75 × 11.70 y, the paper\'s Conclusions), 108 (0.75 × RKC "over 12 years", S113) and 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 102],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.beagle.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–12 months for a small dog, S10); capped at the adult 60 minutes, which 10 × age already reaches at 6 months, so the whole young stage walks the adult minutes.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S113', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.beagle.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S113) → 60 min = 6,000 steps (runbook rule: "up to N" → N; sources conflict → RKC). PDSA says "at least an hour and a half" in its text but "1 hour" in its key facts (S115) — recorded as the alternative.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.beagle.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[9.0, 11.0], [
                'unit' => 'kg', 'source_id' => 'S115', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'beagle.adult_weight.pdsa',
                'quote' => 'Average weight: 9-11 kg',
                'notes' => 'PDSA key facts, one range for both sexes. The FCI (S111) and RKC (S114) standards give a height only (33–40 cm). Measured UK pet Beagles are heavier (S116 — research only; obesity is common).',
            ]],
            'growth_end' => [[9, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'beagle.growth.adult_weight_reached',
                'quote' => 'Small (12–24 pounds): 9–12 months',
                'notes' => 'Size-class value, not breed-specific (9–11 kg = 20–24 lb, the Small class of S10).',
            ]],
            'coren_rank' => [72, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'beagle.trainability.coren_rank',
                'quote' => '72 | Mastiff / Beagle',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 72 tied with the Mastiff, tier "Lowest" (ranks 70–79). Coren\'s own article (S34) was not reachable in this run.',
            ]],
            'learning_multiplier' => [0.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.beagle.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 72 (S35) is in the "Lowest" tier → 0.5, half the mixed breed\'s speed (PDSA S115: "mischievous characters", start reward-based training early). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [11.28, [
                'unit' => 'years', 'source_id' => 'S116', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'beagle.lifespan.median_uk',
                'quote' => 'The median age at death was 11.28 years (IQR 9.32–13.08) for 322 deaths recorded during the study period.',
                'notes' => 'O\'Neill et al. 2025 (S116), VetCompass UK 2019 (the Conclusions say 11.70 y). McMillan 2024 (S54) gives no reachable Beagle value. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Standard Poodle (M5-R10-07). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_STANDARD_POODLE);
     * weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function standardPoodleProfile(): array
    {
        $decided = self::CONFIRMED_R10_STANDARD_POODLE;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S120', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.standard_poodle.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a large dog finishes growing at 15–18 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [108, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lifespan lower bound "Over 12 years" (S120; PDSA 12–14 y, S122) = 9 y = 108 months. McMillan 2024 has no Standard Poodle value (the Dogs Trust row "Poodle" pools all varieties, S123) and no VetCompass Standard Poodle median was found. Not chosen: 126 (0.75 × 14.0 y pooled, S123) and 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 108],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.standard_poodle.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (15–18 months for a large dog, S10); capped at the adult 60 minutes, which 10 × age already reaches at 6 months, so the whole young stage walks the adult minutes.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S120', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.standard_poodle.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S120) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA "around an hour of exercise daily" (S122) agrees.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.standard_poodle.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[21.0, 35.0], [
                'unit' => 'kg', 'source_id' => 'S122', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'standard_poodle.adult_weight.pdsa',
                'quote' => 'Male: 30kg-35kg. Female: 21kg-32kg',
                'notes' => 'PDSA key facts (males 30–35 kg, females 21–32 kg; the page is "Poodle", size Large). The FCI (S118) and RKC (S121) standards give a height only (FCI 45–60 cm, RKC over 38 cm).',
            ]],
            'growth_end' => [[15, 18], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'standard_poodle.growth.adult_weight_reached',
                'quote' => 'Large (59–99 pounds): 15–18 months',
                'notes' => 'Size-class value, not breed-specific (21–35 kg = 46–77 lb; males in the Large class of S10, females straddle Medium 12–15 months).',
            ]],
            'coren_rank' => [2, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'standard_poodle.trainability.coren_rank',
                'quote' => '| 2 | Poodle |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 2 (the breed "Poodle", varieties not separated), tier "Brightest" (ranks 1–10). Coren\'s own article (S34) was not reachable in this run.',
            ]],
            'learning_multiplier' => [2.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.standard_poodle.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 2 (S35) in the "Brightest" tier → max(1.8, round(2.0 − 1/30, 1)) = 2.0, twice the mixed breed\'s speed (PDSA S122: "very obedient and respond well to training"). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [12.0, [
                'unit' => 'years', 'source_id' => 'S120', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'standard_poodle.lifespan.rkc',
                'quote' => 'Lifespan: Over 12 years',
                'notes' => 'RKC breed page lower bound (S120; PDSA 12–14 y, S122). No variety-specific McMillan 2024 or VetCompass median (the pooled "Poodle" 14.0 y, S123, is not used). Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Dachshund, standard size (M5-R10-08). Stage boundaries, arrival ages, exercise minutes
     * and the learning multiplier follow the runbook's standing rules (CONFIRMED_R10_DACHSHUND);
     * weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function dachshundProfile(): array
    {
        $decided = self::CONFIRMED_R10_DACHSHUND;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S125', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.dachshund.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small dog finishes growing at 9–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [108, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lifespan lower bound "Over 12 years" (S125; PDSA "Over 12 years", S127) = 9 y = 108 months. McMillan 2024 names only the Miniature Dachshund (14.0 y, S130) and no standard-size VetCompass median was found. Not chosen: 126 (0.75 × 14.0 y miniature, S130) and 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 108],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.dachshund.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–12 months for a small dog, S10); capped at the adult 60 minutes, which 10 × age already reaches at 6 months, so the whole young stage walks the adult minutes. PDSA asks to take exercise easy while a Dachshund is growing (S127).',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S125', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.dachshund.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S125) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA "a minimum of an hour exercise every day" for the standard size (S127) agrees.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.dachshund.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[9.0, 12.0], [
                'unit' => 'kg', 'source_id' => 'S126', 'confidence' => 'high', 'verified' => true,
                'ref' => 'dachshund.adult_weight.rkc',
                'quote' => 'Ideal weight: 9-12 kgs (20-26 lbs).',
                'notes' => 'RKC breed standard, Dachshund (Smooth Haired), one range for both sexes; PDSA "Standard 9-12kg" (S127) agrees. Miniatures (under 5 kg, S125) are not this breed entry.',
            ]],
            'growth_end' => [[9, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'dachshund.growth.adult_weight_reached',
                'quote' => 'Small (12–24 pounds): 9–12 months',
                'notes' => 'Size-class value, not breed-specific (9–12 kg = 19.8–26.5 lb; midpoint ≈ 23 lb in the Small class of S10, the upper end touches Medium 12–15 months).',
            ]],
            'coren_rank' => [49, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'dachshund.trainability.coren_rank',
                'quote' => '| 49 | Dachshund |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 49, tier "Average" (ranks 40–54). Coren\'s own article (S34) lists only the top / bottom 10.',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.dachshund.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 49 (S35) in the "Average" tier → 1.0, the mixed breed\'s speed (PDSA S127: "can be wilful when it comes to training"). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [12.0, [
                'unit' => 'years', 'source_id' => 'S125', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'dachshund.lifespan.rkc',
                'quote' => 'Lifespan: Over 12 years',
                'notes' => 'RKC breed page lower bound (S125; PDSA "Over 12 years", S127). No standard-size McMillan 2024 or VetCompass median (the Miniature Dachshund 14.0 y, S130, is not used). Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Australian Shepherd (M5-R10-09). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_AUSTRALIAN_SHEPHERD);
     * weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function australianShepherdProfile(): array
    {
        $decided = self::CONFIRMED_R10_AUSTRALIAN_SHEPHERD;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S133', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.australian_shepherd.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a medium dog finishes growing at 12–15 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [90, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lifespan lower bound "Over 10 years" (S133; PDSA "Over 10 years", S134) = 7.5 y = 90 months. McMillan 2024 has no Australian Shepherd value in its text (S135) and no VetCompass median was found. Not chosen: 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 90],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.australian_shepherd.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (12–15 months for a medium dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S133', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.australian_shepherd.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: More than 2 hours per day',
                'notes' => 'RKC "More than 2 hours per day" (S133) → 120 min = 12,000 steps (runbook rule: "more than N" → N). PDSA "a minimum of two hours exercise every day" (S134) agrees.',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.australian_shepherd.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[18.0, 29.0], [
                'unit' => 'kg', 'source_id' => 'S134', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'australian_shepherd.adult_weight.pdsa',
                'quote' => '18-29 kg',
                'notes' => 'PDSA key facts (S134), one range for both sexes. The FCI standard (S131) gives height only (males 51–58 cm, females 46–53 cm).',
            ]],
            'growth_end' => [[12, 15], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'australian_shepherd.growth.adult_weight_reached',
                'quote' => 'Medium (24–59 pounds): 12–15 months',
                'notes' => 'Size-class value, not breed-specific (18–29 kg = 39.7–63.9 lb, mostly the Medium class of S10; RKC / PDSA size "Medium").',
            ]],
            'coren_rank' => [42, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'australian_shepherd.trainability.coren_rank',
                'quote' => '| 42 | Kuvasz / Australian Shepherd |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 42 (tied with the Kuvasz), tier "Average" (ranks 40–54). Coren\'s own article (S34) lists only the top / bottom 10.',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.australian_shepherd.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 42 (S35) in the "Average" tier → 1.0, the mixed breed\'s speed (PDSA S134: "clever dogs who need positive, reward-based training from a young age"). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [10.0, [
                'unit' => 'years', 'source_id' => 'S133', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'australian_shepherd.lifespan.rkc',
                'quote' => 'Lifespan: Over 10 years',
                'notes' => 'RKC breed page lower bound (S133; PDSA "Over 10 years", S134). No McMillan 2024 (S135) or VetCompass median found. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Havanese (M5-R10-10). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_HAVANESE);
     * weight, growth and lifespan are sourced; the breed has no Coren rank.
     *
     * @return array<string, mixed>
     */
    private static function havaneseProfile(): array
    {
        $decided = self::CONFIRMED_R10_HAVANESE;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S138', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.havanese.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a toy dog finishes growing at 8–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [108, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lifespan lower bound "Over 12 years" (S138; PDSA "Over 12 years", S140) = 9 y = 108 months. No McMillan 2024 or VetCompass Havanese median was reachable. Not chosen: 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 108],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.havanese.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (8–12 months for a toy dog, S10); capped at the adult 30 minutes, which 10 × age reaches at 3 months.',
            'adult_minutes' => [30, [
                'unit' => 'minutes/day', 'source_id' => 'S138', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.havanese.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 30 minutes per day',
                'notes' => 'RKC "Up to 30 minutes per day" (S138) → 30 min = 3,000 steps (runbook rule: "up to N" → N). PDSA "around 30 minutes of exercise per day" (S140) agrees.',
            ]],
            'senior_minutes' => [23, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.havanese.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 30 minutes = 22.5, rounded half up = 23 min = 2,300 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[3.0, 6.0], [
                'unit' => 'kg', 'source_id' => 'S140', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'havanese.adult_weight.pdsa',
                'quote' => '3-6 kg',
                'notes' => 'PDSA key facts (S140), one range for both sexes. The FCI (S136), RKC (S139) and AKC (S141) standards give height only (FCI 23–27 cm).',
            ]],
            'growth_end' => [[8, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'havanese.growth.adult_weight_reached',
                'quote' => 'Toy (5–12 pounds): 8–12 months',
                'notes' => 'Size-class value, not breed-specific (3–6 kg = 6.6–13.2 lb, the Toy class of S10; RKC / PDSA size "Small").',
            ]],
            'coren_rank' => [null, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'havanese.trainability.coren_rank',
                'notes' => 'Not ranked: the Havanese is not in the Wikipedia table of Coren\'s ranking (S35, 79 ranks, checked 2026-10-10).',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.havanese.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): not ranked by Coren (S35) → 1.0, the mixed breed\'s speed (PDSA S140: "They are easy to train"). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [12.0, [
                'unit' => 'years', 'source_id' => 'S138', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'havanese.lifespan.rkc',
                'quote' => 'Lifespan: Over 12 years',
                'notes' => 'RKC breed page lower bound (S138; PDSA "Over 12 years", S140). No McMillan 2024 or VetCompass median reachable. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * West Highland White Terrier (M5-R10-11). Stage boundaries, arrival ages, exercise
     * minutes and the learning multiplier follow the runbook's standing rules
     * (CONFIRMED_R10_WEST_HIGHLAND_WHITE_TERRIER); weight, growth, Coren rank and lifespan
     * are sourced.
     *
     * @return array<string, mixed>
     */
    private static function westHighlandWhiteTerrierProfile(): array
    {
        $decided = self::CONFIRMED_R10_WEST_HIGHLAND_WHITE_TERRIER;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S148', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.west_highland_white_terrier.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small dog finishes growing at 9–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [121, $boundary('Derived game boundary: last 25 % of lifespan (S11) × VetCompass median longevity 13.4 y (O\'Neill et al. 2019, S148) = 10.05 y = 120.6 → 121 months. No McMillan 2024 value was reachable. Not chosen: 108 (0.75 × RKC "Over 12 years", S144) and 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 121],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.west_highland_white_terrier.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–12 months for a small dog, S10); capped at the adult 60 minutes, which 10 × age reaches at 6 months.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S144', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.west_highland_white_terrier.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S144) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA "Your Westie will need an hour exercise every day." (S147) agrees.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.west_highland_white_terrier.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[6.0, 9.0], [
                'unit' => 'kg', 'source_id' => 'S147', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'west_highland_white_terrier.adult_weight.pdsa',
                'quote' => '6-9 kg',
                'notes' => 'PDSA key facts (S147), one range for both sexes. The FCI (S142) and RKC (S145) standards give height only (approximately 28 cm).',
            ]],
            'growth_end' => [[9, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'west_highland_white_terrier.growth.adult_weight_reached',
                'quote' => 'Small (12–24 pounds): 9–12 months',
                'notes' => 'Size-class value, not breed-specific (6–9 kg = 13.2–19.8 lb, the Small class of S10; RKC / PDSA size "Small").',
            ]],
            'coren_rank' => [47, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'west_highland_white_terrier.trainability.coren_rank',
                'quote' => '| 47 | West Highland White Terrier |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 47, tier "Average" (ranks 40–54). Coren\'s own article (S34) lists only the top / bottom 10.',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.west_highland_white_terrier.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 47, "Average" tier (S35) → 1.0, the mixed breed\'s speed (PDSA S147: "super eager to please"). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [13.4, [
                'unit' => 'years', 'source_id' => 'S148', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'west_highland_white_terrier.lifespan.median_uk',
                'quote' => 'The median longevity overall was 13.4 years (IQR 11.0–15.0, range 3.2–19.6).',
                'notes' => 'O\'Neill et al. 2019 (S148), VetCompass UK 2016, 164 deaths; RVC summary S149. McMillan 2024 (S54) gives no reachable value. Background for the senior boundary only — never shown as a statistic in the app.',
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
