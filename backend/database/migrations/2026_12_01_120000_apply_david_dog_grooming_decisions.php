<?php

use App\Services\LifeStageService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * M5-R13 — David's dog grooming decisions of 2026-10-12 for rows that are
 * already in production. BreedStageParamsSeeder is insert-only, so the rows
 * seeded by PR #195 keep their old values; this migration applies the three
 * decisions ONCE (same pattern as 2026_10_26_130000_apply_david_cat_play_gap_decision):
 *
 *  1. German Shepherd, Australian Shepherd, West Highland White Terrier,
 *     Bernese Mountain Dog, Siberian Husky: 2 per week stays — confirmed
 *     (`verified` false → true, decision "potrdil David 2026-10-12: 2× na teden");
 *  2. Cavalier King Charles Spaniel: 7 → 4 per week (source S108 and its quote
 *     stay; 4 is David's choice, not the source's number);
 *  3. German Shorthaired Pointer: 2 per week stays — confirmed (decision text).
 *
 * Rows: the 7 tuples in TARGETS (stage `all`, from 0, key
 * `grooming_sessions_per_week`), each with the columns as seeded before
 * (`old`, frozen from the seeder of origin/main 8c51e4df) and after (`new`,
 * frozen from BreedStageParamsSeeder::dogGrooming() — DavidRoutineDecisions20261012Test
 * checks that `new` equals the seeder today).
 *
 * Safety (expand-safe, never deletes data):
 *  - a tuple with a `breed_stage_param_changes` row of anyone but this
 *    migration (an admin edit in Filament, also the original tuple of a
 *    re-key) is skipped — admin edits win;
 *  - a row that is neither the old nor the new seeded row (changed outside
 *    Filament) is skipped with a warning; a row already equal to `new` writes
 *    nothing (idempotent); a missing row is left to the seeder (it inserts `new`);
 *  - each change writes one audit row (action `updated`, user_id null, actor
 *    ACTOR, old / new of the changed fields) — which also makes the seeder skip
 *    the tuple for good.
 *
 * down(): restores `old` on every row that still equals `new` and whose
 * tuple has no audit row other than this migration's own (so an admin edit
 * made after the deploy is never undone); it writes one audit row per
 * restore (actor ROLLBACK_ACTOR) and deletes nothing.
 *
 * Runs outside the migrator's transaction (own DB::transaction) so the rules
 * cache is forgotten only after the commit.
 */
return new class extends Migration
{
    public const ACTOR = 'system: David decision 2026-10-12 (dog grooming)';

    public const ROLLBACK_ACTOR = 'system: rollback of David decision 2026-10-12 (dog grooming)';

    public $withinTransaction = false;

    /** Columns compared / written (the tuple itself never changes). */
    private const FIELDS = ['value', 'unit', 'source_id', 'confidence', 'verified', 'quote', 'notes', 'data_ref'];

    private const UNIT = 'brushing sessions/week (≥ 1 day apart)';

    private const MORE_THAN_ONCE = 'Grooming: More than once a week';

    private const OLD_PROPOSAL_NOTES = 'Decision: predlog Claude 12. 10. 2026 (»večkrat na teden« brez števila → 2, M5-R13) — čaka Davida. The sources say "more than once a week" without a number → Claude\'s proposal 2 (open for David). Matted coat when ≥ 1 of 2 is missed (ceil(2 / 2)).';

    private const NEW_TWICE_NOTES = 'Decision: potrdil David 2026-10-12: 2× na teden. The sources say "more than once a week" without a number; 2 per week is David\'s decision (2026-10-12). Matted coat when ≥ ceil(2 / 2) of the week\'s slots are missed.';

    private const CAVALIER_QUOTE = 'Cavalier King Charles Spaniels have fairly high maintenance coats and you may find they need to be brushed daily';

    private const GSP_QUOTE = "Shorthaired pointers will need grooming once or twice a week, with Wirehaired also needing 'stripping' occasionally.";

    /**
     * [breed_slug, stage, age_from_months, key, old columns, new columns] —
     * `value` is the decoded value (JSON-encoded on write).
     *
     * @return list<array{0: string, 1: string, 2: int, 3: string, 4: array<string, mixed>, 5: array<string, mixed>}>
     */
    public static function targets(): array
    {
        $twice = fn (string $slug, string $breed, string $source): array => [
            $slug, 'all', 0, 'grooming_sessions_per_week',
            ['value' => 2, 'unit' => self::UNIT, 'source_id' => $source, 'confidence' => 'low', 'verified' => false, 'quote' => self::MORE_THAN_ONCE, 'notes' => self::OLD_PROPOSAL_NOTES, 'data_ref' => "proposed_game_parameters.{$breed}.grooming"],
            ['value' => 2, 'unit' => self::UNIT, 'source_id' => $source, 'confidence' => 'low', 'verified' => true, 'quote' => self::MORE_THAN_ONCE, 'notes' => self::NEW_TWICE_NOTES, 'data_ref' => "proposed_game_parameters.{$breed}.grooming"],
        ];

        return [
            $twice('german-shepherd-dog', 'german_shepherd', 'S97'),
            $twice('australian-shepherd', 'australian_shepherd', 'S133'),
            $twice('west-highland-white-terrier', 'west_highland_white_terrier', 'S144'),
            $twice('bernese-mountain-dog', 'bernese_mountain_dog', 'S152'),
            $twice('siberian-husky', 'siberian_husky', 'S160'),
            [
                'cavalier-king-charles-spaniel', 'all', 0, 'grooming_sessions_per_week',
                ['value' => 7, 'unit' => self::UNIT, 'source_id' => 'S108', 'confidence' => 'medium', 'verified' => true, 'quote' => self::CAVALIER_QUOTE,
                    'notes' => 'Decision: potrdil David 12. 10. 2026 (pravilo runbooka, M5-R13). Runbook grooming rule (once a week → 1, every day → 7, A–B → B). Matted coat when ≥ ceil(7 / 2) of the week\'s slots are missed.',
                    'data_ref' => 'proposed_game_parameters.cavalier_king_charles_spaniel.grooming'],
                ['value' => 4, 'unit' => self::UNIT, 'source_id' => 'S108', 'confidence' => 'medium', 'verified' => true, 'quote' => self::CAVALIER_QUOTE,
                    'notes' => 'Decision: potrdil David 2026-10-12: 4× na teden (PDSA S108 \'may find they need to be brushed daily\' je previden zapis). The source (hedged "may find they need to be brushed daily") stays the basis; 4 per week is David\'s choice (2026-10-12), not the source\'s number. Matted coat when ≥ ceil(4 / 2) of the week\'s slots are missed.',
                    'data_ref' => 'proposed_game_parameters.cavalier_king_charles_spaniel.grooming'],
            ],
            [
                'german-shorthaired-pointer', 'all', 0, 'grooming_sessions_per_week',
                ['value' => 2, 'unit' => self::UNIT, 'source_id' => 'S188', 'confidence' => 'medium', 'verified' => true, 'quote' => self::GSP_QUOTE,
                    'notes' => 'Decision: potrdil David 12. 10. 2026 (pravilo runbooka, M5-R13). Runbook grooming rule (once a week → 1, every day → 7, A–B → B). Matted coat when ≥ ceil(2 / 2) of the week\'s slots are missed.',
                    'data_ref' => 'proposed_game_parameters.german_shorthaired_pointer.grooming'],
                ['value' => 2, 'unit' => self::UNIT, 'source_id' => 'S188', 'confidence' => 'medium', 'verified' => true, 'quote' => self::GSP_QUOTE,
                    'notes' => 'Decision: potrdil David 2026-10-12. Runbook grooming rule (A–B → B: "once or twice a week" → 2), confirmed by David (2026-10-12). Matted coat when ≥ ceil(2 / 2) of the week\'s slots are missed.',
                    'data_ref' => 'proposed_game_parameters.german_shorthaired_pointer.grooming'],
            ],
        ];
    }

    public function up(): void
    {
        $this->apply(from: 4, to: 5, actor: self::ACTOR);
    }

    public function down(): void
    {
        // Only rows this migration changed and nobody edited afterwards.
        $this->apply(from: 5, to: 4, actor: self::ROLLBACK_ACTOR, quiet: true);
    }

    /**
     * A tuple with an audit row of anyone but this migration (an admin edit,
     * another data migration) is never touched.
     *
     * @param  list<string>  $actors
     */
    private function skip(array $actors): bool
    {
        return array_diff($actors, [self::ACTOR, self::ROLLBACK_ACTOR]) !== [];
    }

    /**
     * Move each target row from columns [$from] to columns [$to] when it still
     * holds [$from] exactly; [$to] already = nothing to do.
     */
    private function apply(int $from, int $to, string $actor, bool $quiet = false): void
    {
        $breeds = DB::transaction(function () use ($from, $to, $actor, $quiet): array {
            $audits = $this->auditActors();
            $now = now();
            $changed = [];

            foreach (self::targets() as $target) {
                [$breed, $stage, $fromMonth, $key] = $target;
                $tuple = "{$breed}|{$stage}|{$fromMonth}|{$key}";
                if ($this->skip($audits[$tuple] ?? [])) {
                    continue; // an admin edited / re-keyed / deleted it — never overwrite
                }

                $current = DB::table('breed_stage_params')
                    ->where('breed_slug', $breed)->where('stage', $stage)
                    ->where('age_from_months', $fromMonth)->where('key', $key)
                    ->lockForUpdate()->first();
                if ($current === null) {
                    continue; // not seeded yet: the seeder inserts the current row
                }

                $row = $this->decoded($current);
                if ($this->same($row, $target[$to])) {
                    continue; // already applied
                }
                if (! $this->same($row, $target[$from])) {
                    if (! $quiet) {
                        Log::warning('David 2026-10-12 dog grooming: breed_stage_params row changed outside Filament, decision not applied', ['tuple' => $tuple]);
                    }

                    continue;
                }

                $old = [];
                $new = [];
                foreach (self::FIELDS as $field) {
                    if ($row[$field] !== $target[$to][$field]) {
                        $old[$field] = $row[$field];
                        $new[$field] = $target[$to][$field];
                    }
                }
                $write = $new;
                if (array_key_exists('value', $write)) {
                    $write['value'] = json_encode($write['value']);
                }

                DB::table('breed_stage_params')->where('id', $current->id)->update($write + ['updated_at' => $now]);
                DB::table('breed_stage_param_changes')->insert([
                    'breed_stage_param_id' => $current->id,
                    'breed_slug' => $breed,
                    'stage' => $stage,
                    'age_from_months' => $fromMonth,
                    'key' => $key,
                    'action' => 'updated',
                    'user_id' => null,
                    'actor' => $actor,
                    'old' => json_encode($old),
                    'new' => json_encode($new),
                    'created_at' => $now,
                ]);
                $changed[$breed] = true;
            }

            return array_keys($changed);
        });

        // After the commit: a reader can never re-cache the previous rows.
        foreach ($breeds as $slug) {
            LifeStageService::forgetBreed($slug);
        }
    }

    /** @return array<string, mixed> */
    private function decoded(object $current): array
    {
        $row = [];
        foreach (self::FIELDS as $field) {
            $row[$field] = $current->{$field};
        }
        $row['value'] = $row['value'] === null ? null : json_decode((string) $row['value'], true);
        $row['verified'] = (bool) $row['verified'];

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $columns
     */
    private function same(array $row, array $columns): bool
    {
        foreach (self::FIELDS as $field) {
            if ($row[$field] !== $columns[$field]) {
                return false;
            }
        }

        return true;
    }

    /**
     * The audit actors of every (breed, stage, from, key) with a change row,
     * incl. the original tuple of a re-key (as BreedStageParamsSeeder::touchedTuples).
     *
     * @return array<string, list<string>>
     */
    private function auditActors(): array
    {
        $actors = [];
        foreach (DB::table('breed_stage_param_changes')->get(['breed_slug', 'stage', 'age_from_months', 'key', 'action', 'old', 'actor']) as $change) {
            $label = (string) ($change->actor ?? 'admin');
            $actors["{$change->breed_slug}|{$change->stage}|".(int) $change->age_from_months."|{$change->key}"][] = $label;

            $old = is_string($change->old) ? json_decode($change->old, true) : null;
            if ($change->action === 'updated' && is_array($old)) {
                $actors[($old['breed_slug'] ?? $change->breed_slug).'|'.($old['stage'] ?? $change->stage).'|'
                    .(int) ($old['age_from_months'] ?? $change->age_from_months).'|'.($old['key'] ?? $change->key)][] = $label;
            }
        }

        return $actors;
    }
};
