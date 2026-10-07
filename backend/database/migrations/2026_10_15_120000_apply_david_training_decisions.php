<?php

use App\Services\LifeStageService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * M5-R03b — David confirmed the training numbers on 2026-10-06: 5 minutes of
 * mini-game per dog and day, +1 point per correctly timed praise, −2 points
 * per missed training day. Applied ONCE to the `breed_stage_params` rows
 * PR #53 already seeded in production as unverified proposals
 * (BreedStageParamsSeeder is insert-only and never updates them) — the same
 * pattern as 2026_10_12_120000_apply_david_stage_decisions.
 *
 * Rows: the 6 tuples in TARGETS (2 breeds × 3 keys, stage `all`), frozen from
 * BreedStageParamsSeeder::rows() on 2026-10-06 so a later seeder change can
 * never alter what this migration writes. Each becomes `verified = true`
 * with "Decision: potrdil David 2026-10-06." in `notes` (values unchanged;
 * the two effect proposals stay unverified — David has not confirmed them).
 * TrainingDecisionsTest checks that TARGETS equals the seeder today.
 *
 * Safety:
 *  - a tuple an admin already touched (any `breed_stage_param_changes` row,
 *    also the original tuple of a re-key) is skipped — Filament edits win;
 *  - a row whose value is not the seeded / confirmed value (changed outside
 *    Filament) is skipped with a warning;
 *  - a row already equal to the target writes nothing (idempotent); a
 *    changed row gets one audit row (action `updated`, user_id null,
 *    actor "system: David decision 2026-10-06", old / new of changed fields),
 *    which also makes the seeder skip the tuple for good.
 *
 * Runs outside the migrator's transaction (own DB::transaction) so the rules
 * cache is forgotten only after the commit. down() is a no-op: one-way data
 * change; the audit rows must survive a rollback.
 */
return new class extends Migration
{
    public const ACTOR = 'system: David decision 2026-10-06';

    public $withinTransaction = false;

    /** Columns compared / written (the tuple itself never changes). */
    private const FIELDS = ['value', 'unit', 'source_id', 'confidence', 'verified', 'quote', 'notes', 'data_ref'];

    /**
     * [breed_slug, stage, age_from_months, key, columns] — `columns.value` is
     * the decoded value (JSON-encoded on write).
     */
    public const TARGETS = [
        ['mutt', 'all', 0, 'training_minutes_per_day', ['value' => 5, 'unit' => 'minutes of mini-game per dog and family-local day', 'source_id' => 'S36,S37', 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-06. Game value (no literature number): S36 / S37 say 5–10 min sessions and ≤ 15 min a day for puppies; 5 min = the dog\'s daily budget (≈ 6 sessions of 50 s), same for every stage, split equally between the children who care for the dog (M5-R03b).', 'data_ref' => 'proposed_game_parameters.training_minigame_minutes']],
        ['mutt', 'all', 0, 'training_progress_per_success', ['value' => 1.0, 'unit' => 'percentage points per correctly timed praise (baseline)', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-06. Game value (no literature number): game balance — about 16 good sessions per command for a mixed breed.', 'data_ref' => 'proposed_game_parameters.training_progress_per_success']],
        ['mutt', 'all', 0, 'training_decay_per_missed_day', ['value' => 2.0, 'unit' => 'percentage points per missed training day, every command', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-06. Game value (no literature number): no source gives a forgetting rate.', 'data_ref' => 'proposed_game_parameters.training_decay_per_missed_day']],
        ['border-collie', 'all', 0, 'training_minutes_per_day', ['value' => 5, 'unit' => 'minutes of mini-game per dog and family-local day', 'source_id' => 'S36,S37', 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-06. Game value (no literature number): S36 / S37 say 5–10 min sessions and ≤ 15 min a day for puppies; 5 min = the dog\'s daily budget (≈ 6 sessions of 50 s), same for every stage, split equally between the children who care for the dog (M5-R03b).', 'data_ref' => 'proposed_game_parameters.training_minigame_minutes']],
        ['border-collie', 'all', 0, 'training_progress_per_success', ['value' => 1.0, 'unit' => 'percentage points per correctly timed praise (baseline)', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-06. Game value (no literature number): game balance — about 16 good sessions per command for a mixed breed.', 'data_ref' => 'proposed_game_parameters.training_progress_per_success']],
        ['border-collie', 'all', 0, 'training_decay_per_missed_day', ['value' => 2.0, 'unit' => 'percentage points per missed training day, every command', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-06. Game value (no literature number): no source gives a forgetting rate.', 'data_ref' => 'proposed_game_parameters.training_decay_per_missed_day']],
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

                // The numbers did not change (PR #53 seeded the confirmed values); jsonb
                // may hand 1.0 back as 1, so compare numerically.
                $currentValue = $current->value === null ? null : json_decode((string) $current->value, true);
                if (! (is_int($currentValue) || is_float($currentValue)) || (float) $currentValue !== (float) $target['value']) {
                    Log::warning('M5-R03b: breed_stage_params row changed outside Filament, David decision not applied', [
                        'tuple' => $tuple, 'value' => $currentValue,
                    ]);

                    continue;
                }

                $old = [];
                $new = [];
                foreach (self::FIELDS as $field) {
                    if ($field === 'value') {
                        continue; // same number (checked above)
                    }
                    $before = $current->{$field};
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

                DB::table('breed_stage_params')->where('id', $current->id)->update($new + ['updated_at' => $now]);

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
