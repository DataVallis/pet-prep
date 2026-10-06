<?php

namespace Database\Seeders;

use App\Enums\BreedType;
use App\Enums\StageParamKey;
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

    /** David's M5-R03 decisions (training, 2026-10-06): Border Collie 2×, mixed breed baseline ±20 %. */
    public const CONFIRMED_R03 = 'potrdil David 2026-10-06';

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

        foreach ([BreedType::Mutt, BreedType::BorderCollie] as $breed) {
            $bc = $breed === BreedType::BorderCollie;
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
            $add('young', 0, StageParamKey::StartsAtMonths, 9, [
                'unit' => 'months', 'source_id' => 'S11', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.life_stages.young_adult', 'decision' => self::CONFIRMED,
                'quote' => 'From cessation of rapid growth to completion of physical and social maturation',
                'notes' => 'Game boundary inside the sourced range (no exact month in the literature). AAHA: puppy ends ~6–9 months; 9 = upper end for medium dogs (still 72 % of adult weight at 6 months, S9 logistic proposal).',
            ]);
            $add('adult', 0, StageParamKey::StartsAtMonths, 36, [
                'unit' => 'months', 'source_id' => 'S11', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.life_stages.mature_adult', 'decision' => self::CONFIRMED,
                'quote' => 'From completion of physical and social maturation until the last 25% of estimated lifespan',
                'notes' => 'Game boundary inside the sourced range (no exact month in the literature). Maturation completes at 3–4 years (S11); 36 months = lower end.',
            ]);
            $add('senior', 0, StageParamKey::StartsAtMonths, $bc ? 118 : 108, [
                'unit' => 'months', 'source_id' => 'S11,S15', 'confidence' => 'medium', 'verified' => true,
                'ref' => $bc ? 'border_collie.lifespan.senior_from' : 'medium_mixed_breed.lifespan.senior_from',
                'decision' => self::CONFIRMED,
                'notes' => $bc
                    ? 'Derived game boundary: last 25 % of lifespan (S11) × median 13.1 y (S15) = 9.8 y = 118 months. Dogs Trust rule of thumb: > 7 y (S14).'
                    : 'Derived game boundary: last 25 % of lifespan (S11) × median 12.0 y for crossbreeds (S15) = 9.0 y = 108 months. Dogs Trust rule of thumb: > 7 y (S14).',
            ]);

            // ── Age at arrival (parent's choice → pets.arrival_age_months) ──
            $add('puppy', 0, StageParamKey::ArrivalAgeMonths, 2, [
                'unit' => 'months', 'source_id' => 'S36', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.training.start_age', 'decision' => self::CONFIRMED,
                'quote' => 'Puppies can begin very simple training starting as soon as they come home, usually around 8 weeks old.',
                'notes' => 'Game value backed by S36 ("home at ~8 weeks").',
            ]);
            foreach (['young' => 9, 'adult' => 36, 'senior' => $bc ? 118 : 108] as $stage => $age) {
                $add($stage, 0, StageParamKey::ArrivalAgeMonths, $age, [
                    'unit' => 'months', 'verified' => true,
                    'ref' => 'proposed_game_parameters.arrival_age_months', 'decision' => self::CONFIRMED,
                    'notes' => 'Game value (no literature number): representative age = first month of the stage, so the dog stays in this stage for the 12-week challenge.',
                ]);
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
                'notes' => 'Game rule; applies "until full-grown" (12–15 months, S10); capped at the adult minutes, so from ~12 months it equals the adult value.',
            ]);
            foreach (['young', 'adult'] as $stage) {
                $add($stage, 0, StageParamKey::ExerciseMinutesPerDay, $bc ? 120 : 60, $bc ? [
                    'unit' => 'minutes/day', 'source_id' => 'S5', 'confidence' => 'high', 'verified' => true,
                    'ref' => 'border_collie.exercise.adult',
                    'quote' => 'Exercise: More than 2 hours per day',
                    'notes' => '"More than 2 hours" → 120 minutes (lower bound).',
                ] : [
                    'unit' => 'minutes/day', 'verified' => true,
                    'ref' => 'medium_mixed_breed.exercise.adult_game_target', 'decision' => self::CONFIRMED,
                    'notes' => 'Game value (no literature number): 60 min inside the sourced 30–120 min adult range (S24) → ≈ 6,000 steps.',
                ]);
            }
            $add('senior', 0, StageParamKey::ExerciseMinutesPerDay, $bc ? 90 : 45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.senior_exercise_minutes', 'decision' => self::CONFIRMED,
                'notes' => 'Game value (no literature number): 75 % of the adult minutes. Sources only say "frequent short walks instead of one long one" (S14) and that energy needs fall with age (S13).',
            ]);
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
            $add('all', 0, StageParamKey::AdultWeightKg, $bc ? [13.6, 24.9] : [15, 30], $bc ? [
                'unit' => 'kg', 'source_id' => 'S4', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'border_collie.adult_weight.akc', 'quote' => 'weigh between 30 and 55 pounds',
            ] : [
                'unit' => 'kg', 'source_id' => 'S8', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'medium_mixed_breed.assumed_adult_weight', 'decision' => self::DECISION,
                'quote' => 'Category IV: 15 to <30 kg',
                'notes' => 'David 2026-10-05: the game\'s mutt is a medium mixed breed = Salt size category IV (S8).',
            ]);
            $add('all', 0, StageParamKey::GrowthEndMonths, [12, 15], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => $bc ? 'border_collie.growth.adult_weight_reached' : 'medium_mixed_breed.growth.adult_weight_reached',
                'quote' => 'Medium (24–59 pounds): 12–15 months',
            ]);
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
            $add('all', 0, StageParamKey::CorenRank, $bc ? 1 : null, $bc ? [
                'unit' => 'rank', 'source_id' => 'S34', 'confidence' => 'high', 'verified' => true,
                'ref' => 'border_collie.trainability.coren_rank',
                'quote' => '190 of the 199 judges ranked the Border Collie in the top 10',
            ] : [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'medium_mixed_breed.trainability.coren',
                'quote' => 'Non-AKC/CKC recognized breeds like Jack Russell Terriers were excluded from rankings',
                'notes' => 'Mixed breeds are not ranked; the game models them as average + individual randomness (proposal, M5 training).',
            ]);
            // ── Training (M5-R03, David 2026-10-06) ─────────────────────────
            $add('all', 0, StageParamKey::TrainingLearningMultiplier, $bc ? 2.0 : 1.0, $bc ? [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S34,S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'border_collie.trainability.learning_multiplier', 'decision' => self::CONFIRMED_R03,
                'notes' => 'Game value (no literature factor): Coren rank #1 (S34), "brightest" tier < 5 repetitions vs 25–40 for average dogs (S35, secondary source). Multiplies the progress per correctly timed praise.',
            ] : [
                'unit' => '× baseline learning speed', 'source_id' => 'S35,S42', 'confidence' => 'low', 'verified' => true,
                'ref' => 'medium_mixed_breed.trainability.learning_multiplier', 'decision' => self::CONFIRMED_R03,
                'notes' => 'Game baseline: mixed breeds have no Coren rank (S35); breed explains ~9 % of individual behaviour (S42).',
            ]);
            if (! $bc) {
                // No row for the Border Collie = no individual variation.
                $add('all', 0, StageParamKey::TrainingIndividualVariation, 0.2, [
                    'unit' => '± share of the learning speed, drawn once per dog', 'source_id' => 'S42', 'confidence' => 'low', 'verified' => true,
                    'ref' => 'medium_mixed_breed.trainability.individual_variation', 'decision' => self::CONFIRMED_R03,
                    'notes' => 'Game value: uniform factor in [0.8, 1.2], seeded per pet and stored (pets.training_learning_factor). S42: individuals vary much more than breeds.',
                ]);
            }
            $add('all', 0, StageParamKey::TrainingMinutesPerDay, 5, [
                'unit' => 'minutes of mini-game per dog and family-local day', 'source_id' => 'S36,S37', 'confidence' => 'low', 'verified' => false,
                'ref' => 'proposed_game_parameters.training_minigame_minutes',
                'notes' => 'UNSOURCED proposal (Claude, M5-R03, waiting for David): S36 / S37 say 5–10 min sessions and ≤ 15 min a day for puppies; 5 min = the daily budget (≈ 6 sessions of 50 s), same for every stage.',
            ]);
            $add('all', 0, StageParamKey::TrainingProgressPerSuccess, 1.0, [
                'unit' => 'percentage points per correctly timed praise (baseline)', 'confidence' => 'low', 'verified' => false,
                'ref' => 'proposed_game_parameters.training_progress_per_success',
                'notes' => 'UNSOURCED proposal (Claude, M5-R03, waiting for David): game balance — about 16 good sessions per command for a mixed breed.',
            ]);
            $add('all', 0, StageParamKey::TrainingDecayPerMissedDay, 2.0, [
                'unit' => 'percentage points per missed training day, every command', 'confidence' => 'low', 'verified' => false,
                'ref' => 'proposed_game_parameters.training_decay_per_missed_day',
                'notes' => 'UNSOURCED proposal (Claude, M5-R03, waiting for David): no source gives a forgetting rate.',
            ]);
            $add('all', 0, StageParamKey::PottyTrainingAccidentReduction, 0.75, [
                'unit' => 'share of due puppy accidents avoided at 100 % potty training', 'source_id' => 'S47,S31', 'confidence' => 'low', 'verified' => false,
                'ref' => 'proposed_game_parameters.potty_training_accident_reduction',
                'notes' => 'UNSOURCED proposal (Claude, M5-R03, waiting for David): a house-trained puppy learns to ask to go out (S47); the size of the effect is ours. Bladder hold (S30 / S31) unchanged.',
            ]);
            $add('all', 0, StageParamKey::PlaceTrainingChewingReduction, 0.5, [
                'unit' => 'share of the teething chewing chance removed at 100 % place training', 'source_id' => 'S33', 'confidence' => 'low', 'verified' => false,
                'ref' => 'proposed_game_parameters.place_training_chewing_reduction',
                'notes' => 'UNSOURCED proposal (Claude, M5-R03, waiting for David): guidance teaches a puppy to chew its toys (S33); the size of the effect is ours. Chewing after a missed walk stays certain.',
            ]);

            $add('all', 0, StageParamKey::LifespanYears, $bc ? 13.1 : 12.0, [
                'unit' => 'years', 'source_id' => 'S15', 'confidence' => $bc ? 'high' : 'medium', 'verified' => true,
                'ref' => $bc ? 'border_collie.lifespan.median_uk' : 'medium_mixed_breed.lifespan.median_uk_crossbreeds',
                'quote' => $bc ? 'Border Collie (13.1 years)' : 'This was slightly shorter for crossbred dogs at 12.0 years.',
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

        foreach (self::rows() as $row) {
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
