<?php

use App\Services\LifeStageService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * M5-R06-04 — David confirmed the cat play gap on 2026-10-08 (~20:40):
 * at least 120 minutes between two SUCCESSFUL wand play sessions, measured
 * from the last successful one (CAT_SPEC §5.2). R06-03 seeded the row for
 * both cat breeds as an UNSOURCED proposal (verified = false); the seeder is
 * insert-only and never updates it, so this migration applies the decision
 * ONCE to the existing rows — the same pattern as
 * 2026_10_16_120000_apply_david_training_effect_decisions.
 *
 * Rows: the 2 tuples in TARGETS (domestic-cat, maine-coon; stage `all`),
 * frozen from BreedStageParamsSeeder::catRows() on 2026-10-08 so a later
 * seeder change can never alter what this migration writes. Each becomes
 * `verified = true` with "Decision: potrdil David 2026-10-08 20:40." in
 * `notes` (value unchanged, no literature source — a game value).
 * WandPlayTest checks that TARGETS equals the seeder today.
 *
 * Safety:
 *  - a tuple an admin already touched (any `breed_stage_param_changes` row,
 *    also the original tuple of a re-key) is skipped — Filament edits win;
 *  - a row whose value is not 120 (changed outside Filament) is skipped
 *    with a warning;
 *  - a row already equal to the target writes nothing (idempotent); a
 *    changed row gets one audit row (action `updated`, user_id null,
 *    actor "system: David decision 2026-10-08", old / new of changed fields),
 *    which also makes the seeder skip the tuple for good.
 *
 * Runs outside the migrator's transaction (own DB::transaction) so the rules
 * cache is forgotten only after the commit. down() is a no-op: one-way data
 * change; the audit rows must survive a rollback.
 */
return new class extends Migration
{
    public const ACTOR = 'system: David decision 2026-10-08';

    public $withinTransaction = false;

    /** Columns compared / written (the tuple itself never changes). */
    private const FIELDS = ['value', 'unit', 'source_id', 'confidence', 'verified', 'quote', 'notes', 'data_ref'];

    private const GAP = ['value' => 120, 'unit' => 'minutes between two play sessions', 'source_id' => null, 'confidence' => 'low', 'verified' => true, 'quote' => null, 'notes' => 'Decision: potrdil David 2026-10-08 20:40. Game value (no literature number), CAT_SPEC §5.2: at least 2 h between two successful wand sessions, measured from the last successful one, so the sessions spread over the day (C11: several short sessions through the day).', 'data_ref' => 'cat-data:general.play.game_min_gap'];

    /**
     * [breed_slug, stage, age_from_months, key, columns] — `columns.value` is
     * the decoded value (JSON-encoded on write).
     */
    public const TARGETS = [
        ['domestic-cat', 'all', 0, 'play_min_gap_minutes', self::GAP],
        ['maine-coon', 'all', 0, 'play_min_gap_minutes', self::GAP],
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

                // The number did not change (R06-03 seeded 120 as a proposal); compare numerically.
                $currentValue = $current->value === null ? null : json_decode((string) $current->value, true);
                if (! (is_int($currentValue) || is_float($currentValue)) || (float) $currentValue !== (float) $target['value']) {
                    Log::warning('M5-R06-04: breed_stage_params row changed outside Filament, David decision not applied', [
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
