<?php

use App\Services\LifeStageService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * M5-R01b — David's answers to the M5-R01 open questions (2026-10-05),
 * applied ONCE to the `breed_stage_params` rows PR #37 already seeded in
 * production (BreedStageParamsSeeder is insert-only and never updates them).
 *
 * Rows: the 28 tuples in TARGETS, frozen from BreedStageParamsSeeder::rows()
 * (`decision = 'potrdil David 2026-10-05'`) on 2026-10-05 so a later seeder
 * change can never alter what this migration writes — feed windows of 4 / 3
 * puppy meals (1 h → 2 h: 07–09, 11–13, 15–17, 19–21 / 07–09, 13–15, 19–21),
 * stage boundaries, arrival ages, puppy / young minutes per age month, mutt
 * adult and senior exercise minutes, senior meals. Each becomes
 * `verified = true` with the decision in `notes` (source ids unchanged — the
 * decision is not literature). DavidStageDecisionsTest checks that TARGETS
 * equals the seeder today.
 *
 * Safety:
 *  - a tuple an admin already touched (any `breed_stage_param_changes` row,
 *    also the original tuple of a re-key) is skipped — Filament edits win;
 *  - a row whose value is neither the PR #37 seeded value nor the confirmed
 *    value (changed outside Filament) is skipped with a warning;
 *  - a row already equal to the target writes nothing (idempotent); a
 *    changed row gets one audit row (action `updated`, user_id null,
 *    actor "system: David decision 2026-10-05", old / new of changed fields).
 *    That audit row also makes the seeder skip the tuple for good.
 *
 * Runs outside the migrator's transaction (own DB::transaction) so the rules
 * cache is forgotten only after the commit. The `actor` column comes from
 * 2026_10_12_110000. down() is a no-op: the data change is one-way (an admin
 * can edit values in Filament) and the audit rows must survive a rollback.
 */
return new class extends Migration
{
    public const ACTOR = 'system: David decision 2026-10-05';

    public $withinTransaction = false;

    /** Feed windows seeded by PR #37 (1 h each), keyed by "stage|from". */
    private const PR37_WINDOWS = [
        'puppy|0' => [['07:00', '08:00'], ['11:00', '12:00'], ['15:00', '16:00'], ['19:00', '20:00']],
        'puppy|3' => [['07:00', '08:00'], ['13:00', '14:00'], ['19:00', '20:00']],
    ];

    /** Columns compared / written (the tuple itself never changes). */
    private const FIELDS = ['value', 'unit', 'source_id', 'confidence', 'verified', 'quote', 'notes', 'data_ref'];

    /**
     * [breed_slug, stage, age_from_months, key, columns] — `columns.value` is
     * the decoded value (JSON-encoded on write).
     */
    public const TARGETS = [
        ['mutt', 'young', 0, 'starts_at_months', ['value' => 9, 'unit' => 'months', 'source_id' => 'S11', 'confidence' => 'medium', 'verified' => true, 'quote' => 'From cessation of rapid growth to completion of physical and social maturation', 'notes' => 'Decision: potrdil David 2026-10-05. Game boundary inside the sourced range (no exact month in the literature). AAHA: puppy ends ~6–9 months; 9 = upper end for medium dogs (still 72 % of adult weight at 6 months, S9 logistic proposal).', 'data_ref' => 'general_by_size.life_stages.young_adult']],
        ['mutt', 'adult', 0, 'starts_at_months', ['value' => 36, 'unit' => 'months', 'source_id' => 'S11', 'confidence' => 'medium', 'verified' => true, 'quote' => 'From completion of physical and social maturation until the last 25% of estimated lifespan', 'notes' => 'Decision: potrdil David 2026-10-05. Game boundary inside the sourced range (no exact month in the literature). Maturation completes at 3–4 years (S11); 36 months = lower end.', 'data_ref' => 'general_by_size.life_stages.mature_adult']],
        ['mutt', 'senior', 0, 'starts_at_months', ['value' => 108, 'unit' => 'months', 'source_id' => 'S11,S15', 'confidence' => 'medium', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Derived game boundary: last 25 % of lifespan (S11) × median 12.0 y for crossbreeds (S15) = 9.0 y = 108 months. Dogs Trust rule of thumb: > 7 y (S14).', 'data_ref' => 'medium_mixed_breed.lifespan.senior_from']],
        ['mutt', 'puppy', 0, 'arrival_age_months', ['value' => 2, 'unit' => 'months', 'source_id' => 'S36', 'confidence' => 'medium', 'verified' => true, 'quote' => 'Puppies can begin very simple training starting as soon as they come home, usually around 8 weeks old.', 'notes' => 'Decision: potrdil David 2026-10-05. Game value backed by S36 ("home at ~8 weeks").', 'data_ref' => 'general_by_size.training.start_age']],
        ['mutt', 'young', 0, 'arrival_age_months', ['value' => 9, 'unit' => 'months', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Game value (no literature number): representative age = first month of the stage, so the dog stays in this stage for the 12-week challenge.', 'data_ref' => 'proposed_game_parameters.arrival_age_months']],
        ['mutt', 'adult', 0, 'arrival_age_months', ['value' => 36, 'unit' => 'months', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Game value (no literature number): representative age = first month of the stage, so the dog stays in this stage for the 12-week challenge.', 'data_ref' => 'proposed_game_parameters.arrival_age_months']],
        ['mutt', 'senior', 0, 'arrival_age_months', ['value' => 108, 'unit' => 'months', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Game value (no literature number): representative age = first month of the stage, so the dog stays in this stage for the 12-week challenge.', 'data_ref' => 'proposed_game_parameters.arrival_age_months']],
        ['mutt', 'senior', 0, 'meals_per_day', ['value' => 2, 'unit' => 'meals/day', 'source_id' => 'S14', 'confidence' => 'medium', 'verified' => true, 'quote' => 'feeding your dog smaller meals two or three times a day', 'notes' => 'Decision: potrdil David 2026-10-05. Game value inside the sourced 2–3: lower end, same as adult (David 2026-10-05: adults 2 meals).', 'data_ref' => 'general_by_size.feeding_meals_per_day.senior']],
        ['mutt', 'puppy', 0, 'feed_windows', ['value' => [['07:00', '09:00'], ['11:00', '13:00'], ['15:00', '17:00'], ['19:00', '21:00']], 'unit' => 'HH:MM family-local [start, end)', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Game clock times (no literature number; the meal COUNT is sourced, S18). First window 07:00, last 19:00, equal spacing, 2 h each. A window entirely inside quiet hours is done by the parent.', 'data_ref' => 'proposed_game_parameters.feed_window_times']],
        ['mutt', 'puppy', 3, 'feed_windows', ['value' => [['07:00', '09:00'], ['13:00', '15:00'], ['19:00', '21:00']], 'unit' => 'HH:MM family-local [start, end)', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Game clock times (no literature number; the meal COUNT is sourced, S18). First window 07:00, last 19:00, equal spacing, 2 h each. A window entirely inside quiet hours is done by the parent.', 'data_ref' => 'proposed_game_parameters.feed_window_times']],
        ['mutt', 'puppy', 0, 'exercise_minutes_per_age_month', ['value' => 10, 'unit' => 'minutes/day per month of age', 'source_id' => 'S24', 'confidence' => 'low', 'verified' => true, 'quote' => 'five minutes of exercise per month of age, twice a day, until the puppy is full-grown', 'notes' => 'Decision: potrdil David 2026-10-05. Game rule; the source rule is CONTESTED (S25 calls it a misconception), so confidence stays low. 5 min × 2 per day; capped at the adult minutes.', 'data_ref' => 'general_by_size.exercise.puppy_rule_of_thumb']],
        ['mutt', 'young', 0, 'exercise_minutes_per_age_month', ['value' => 10, 'unit' => 'minutes/day per month of age', 'source_id' => 'S24', 'confidence' => 'low', 'verified' => true, 'quote' => 'five minutes of exercise per month of age, twice a day, until the puppy is full-grown', 'notes' => 'Decision: potrdil David 2026-10-05. Game rule; applies "until full-grown" (12–15 months, S10); capped at the adult minutes, so from ~12 months it equals the adult value.', 'data_ref' => 'general_by_size.exercise.puppy_rule_of_thumb']],
        ['mutt', 'young', 0, 'exercise_minutes_per_day', ['value' => 60, 'unit' => 'minutes/day', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Game value (no literature number): 60 min inside the sourced 30–120 min adult range (S24) → ≈ 6,000 steps.', 'data_ref' => 'medium_mixed_breed.exercise.adult_game_target']],
        ['mutt', 'adult', 0, 'exercise_minutes_per_day', ['value' => 60, 'unit' => 'minutes/day', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Game value (no literature number): 60 min inside the sourced 30–120 min adult range (S24) → ≈ 6,000 steps.', 'data_ref' => 'medium_mixed_breed.exercise.adult_game_target']],
        ['mutt', 'senior', 0, 'exercise_minutes_per_day', ['value' => 45, 'unit' => 'minutes/day', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Game value (no literature number): 75 % of the adult minutes. Sources only say "frequent short walks instead of one long one" (S14) and that energy needs fall with age (S13).', 'data_ref' => 'proposed_game_parameters.senior_exercise_minutes']],
        ['border-collie', 'young', 0, 'starts_at_months', ['value' => 9, 'unit' => 'months', 'source_id' => 'S11', 'confidence' => 'medium', 'verified' => true, 'quote' => 'From cessation of rapid growth to completion of physical and social maturation', 'notes' => 'Decision: potrdil David 2026-10-05. Game boundary inside the sourced range (no exact month in the literature). AAHA: puppy ends ~6–9 months; 9 = upper end for medium dogs (still 72 % of adult weight at 6 months, S9 logistic proposal).', 'data_ref' => 'general_by_size.life_stages.young_adult']],
        ['border-collie', 'adult', 0, 'starts_at_months', ['value' => 36, 'unit' => 'months', 'source_id' => 'S11', 'confidence' => 'medium', 'verified' => true, 'quote' => 'From completion of physical and social maturation until the last 25% of estimated lifespan', 'notes' => 'Decision: potrdil David 2026-10-05. Game boundary inside the sourced range (no exact month in the literature). Maturation completes at 3–4 years (S11); 36 months = lower end.', 'data_ref' => 'general_by_size.life_stages.mature_adult']],
        ['border-collie', 'senior', 0, 'starts_at_months', ['value' => 118, 'unit' => 'months', 'source_id' => 'S11,S15', 'confidence' => 'medium', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Derived game boundary: last 25 % of lifespan (S11) × median 13.1 y (S15) = 9.8 y = 118 months. Dogs Trust rule of thumb: > 7 y (S14).', 'data_ref' => 'border_collie.lifespan.senior_from']],
        ['border-collie', 'puppy', 0, 'arrival_age_months', ['value' => 2, 'unit' => 'months', 'source_id' => 'S36', 'confidence' => 'medium', 'verified' => true, 'quote' => 'Puppies can begin very simple training starting as soon as they come home, usually around 8 weeks old.', 'notes' => 'Decision: potrdil David 2026-10-05. Game value backed by S36 ("home at ~8 weeks").', 'data_ref' => 'general_by_size.training.start_age']],
        ['border-collie', 'young', 0, 'arrival_age_months', ['value' => 9, 'unit' => 'months', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Game value (no literature number): representative age = first month of the stage, so the dog stays in this stage for the 12-week challenge.', 'data_ref' => 'proposed_game_parameters.arrival_age_months']],
        ['border-collie', 'adult', 0, 'arrival_age_months', ['value' => 36, 'unit' => 'months', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Game value (no literature number): representative age = first month of the stage, so the dog stays in this stage for the 12-week challenge.', 'data_ref' => 'proposed_game_parameters.arrival_age_months']],
        ['border-collie', 'senior', 0, 'arrival_age_months', ['value' => 118, 'unit' => 'months', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Game value (no literature number): representative age = first month of the stage, so the dog stays in this stage for the 12-week challenge.', 'data_ref' => 'proposed_game_parameters.arrival_age_months']],
        ['border-collie', 'senior', 0, 'meals_per_day', ['value' => 2, 'unit' => 'meals/day', 'source_id' => 'S14', 'confidence' => 'medium', 'verified' => true, 'quote' => 'feeding your dog smaller meals two or three times a day', 'notes' => 'Decision: potrdil David 2026-10-05. Game value inside the sourced 2–3: lower end, same as adult (David 2026-10-05: adults 2 meals).', 'data_ref' => 'general_by_size.feeding_meals_per_day.senior']],
        ['border-collie', 'puppy', 0, 'feed_windows', ['value' => [['07:00', '09:00'], ['11:00', '13:00'], ['15:00', '17:00'], ['19:00', '21:00']], 'unit' => 'HH:MM family-local [start, end)', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Game clock times (no literature number; the meal COUNT is sourced, S18). First window 07:00, last 19:00, equal spacing, 2 h each. A window entirely inside quiet hours is done by the parent.', 'data_ref' => 'proposed_game_parameters.feed_window_times']],
        ['border-collie', 'puppy', 3, 'feed_windows', ['value' => [['07:00', '09:00'], ['13:00', '15:00'], ['19:00', '21:00']], 'unit' => 'HH:MM family-local [start, end)', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Game clock times (no literature number; the meal COUNT is sourced, S18). First window 07:00, last 19:00, equal spacing, 2 h each. A window entirely inside quiet hours is done by the parent.', 'data_ref' => 'proposed_game_parameters.feed_window_times']],
        ['border-collie', 'puppy', 0, 'exercise_minutes_per_age_month', ['value' => 10, 'unit' => 'minutes/day per month of age', 'source_id' => 'S24', 'confidence' => 'low', 'verified' => true, 'quote' => 'five minutes of exercise per month of age, twice a day, until the puppy is full-grown', 'notes' => 'Decision: potrdil David 2026-10-05. Game rule; the source rule is CONTESTED (S25 calls it a misconception), so confidence stays low. 5 min × 2 per day; capped at the adult minutes.', 'data_ref' => 'general_by_size.exercise.puppy_rule_of_thumb']],
        ['border-collie', 'young', 0, 'exercise_minutes_per_age_month', ['value' => 10, 'unit' => 'minutes/day per month of age', 'source_id' => 'S24', 'confidence' => 'low', 'verified' => true, 'quote' => 'five minutes of exercise per month of age, twice a day, until the puppy is full-grown', 'notes' => 'Decision: potrdil David 2026-10-05. Game rule; applies "until full-grown" (12–15 months, S10); capped at the adult minutes, so from ~12 months it equals the adult value.', 'data_ref' => 'general_by_size.exercise.puppy_rule_of_thumb']],
        ['border-collie', 'senior', 0, 'exercise_minutes_per_day', ['value' => 90, 'unit' => 'minutes/day', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-05. Game value (no literature number): 75 % of the adult minutes. Sources only say "frequent short walks instead of one long one" (S14) and that energy needs fall with age (S13).', 'data_ref' => 'proposed_game_parameters.senior_exercise_minutes']],
    ];

    public function up(): void
    {
        $breeds = DB::transaction(function (): array {
            $touched = $this->touchedTuples();
            $now = now();
            $changedBreeds = [];

            foreach (self::TARGETS as [$breed, $stage, $from, $key, $target]) {
                $tuple = "{$breed}|{$stage}|{$from}|{$key}";
                if (isset($touched[$tuple])) {
                    continue; // an admin edited / re-keyed / deleted it — never overwrite
                }

                $current = DB::table('breed_stage_params')
                    ->where('breed_slug', $breed)
                    ->where('stage', $stage)
                    ->where('age_from_months', $from)
                    ->where('key', $key)
                    ->lockForUpdate()
                    ->first();
                if ($current === null) {
                    continue; // not seeded yet: the seeder inserts the confirmed row
                }

                $currentValue = $current->value === null ? null : json_decode((string) $current->value, true);
                $seededValue = $key === 'feed_windows' ? (self::PR37_WINDOWS["{$stage}|{$from}"] ?? $target['value']) : $target['value'];
                if ($currentValue !== $seededValue && $currentValue !== $target['value']) {
                    Log::warning('M5-R01b: breed_stage_params row changed outside Filament, David decision not applied', [
                        'tuple' => $tuple, 'value' => $currentValue,
                    ]);

                    continue;
                }

                $old = [];
                $new = [];
                foreach (self::FIELDS as $field) {
                    $before = $field === 'value' ? $currentValue : $current->{$field};
                    if ($field === 'verified') {
                        $before = (bool) $before;
                    }
                    if ($before !== $target[$field]) {
                        $old[$field] = $before;
                        $new[$field] = $target[$field];
                    }
                }
                if ($new === []) {
                    continue; // already applied
                }

                $write = $new;
                if (array_key_exists('value', $write)) {
                    $write['value'] = $write['value'] === null ? null : json_encode($write['value']);
                }
                DB::table('breed_stage_params')->where('id', $current->id)->update($write + ['updated_at' => $now]);

                DB::table('breed_stage_param_changes')->insert([
                    'breed_stage_param_id' => $current->id,
                    'breed_slug' => $breed,
                    'stage' => $stage,
                    'age_from_months' => $from,
                    'key' => $key,
                    'action' => 'updated',
                    'user_id' => null,
                    'actor' => self::ACTOR,
                    'old' => json_encode($old),
                    'new' => json_encode($new),
                    'created_at' => $now,
                ]);

                $changedBreeds[$breed] = true;
            }

            return array_keys($changedBreeds);
        });

        // After the commit: a reader can never re-cache the pre-decision rows.
        foreach ($breeds as $slug) {
            LifeStageService::forgetBreed($slug);
        }
    }

    public function down(): void
    {
        // One-way data change; audit rows (and their `actor`) are kept.
    }

    /**
     * Every (breed, stage, from, key) with an audit row, incl. the original
     * tuple of a re-key (frozen copy of BreedStageParamsSeeder::touchedTuples).
     *
     * @return array<string, true>
     */
    private function touchedTuples(): array
    {
        $touched = [];
        foreach (DB::table('breed_stage_param_changes')->get(['breed_slug', 'stage', 'age_from_months', 'key', 'action', 'old']) as $change) {
            $touched["{$change->breed_slug}|{$change->stage}|".(int) $change->age_from_months."|{$change->key}"] = true;

            $old = is_string($change->old) ? json_decode($change->old, true) : null;
            if ($change->action === 'updated' && is_array($old)) {
                $touched[($old['breed_slug'] ?? $change->breed_slug).'|'.($old['stage'] ?? $change->stage).'|'
                    .(int) ($old['age_from_months'] ?? $change->age_from_months).'|'.($old['key'] ?? $change->key)] = true;
            }
        }

        return $touched;
    }
};
