<?php

use App\Services\LifeStageService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * School smooth playback follow-up (David 2026-10-28): 7 minutes of school mini-game per
 * dog and family-local day instead of 5. A session is ~60 s now (6 cues) and a start
 * reserves the pet's longest possible session (58–70 s), so 5 min gave only ~4–6
 * sessions; 7 min gives ~6 again (DECISIONS 2026-10-28).
 *
 * Every `breed_stage_params` row with key `training_minutes_per_day` whose value is
 * STILL 5 (every breed, every stage — BreedStageParamsSeeder is insert-only and never
 * updates existing rows) becomes 7 with the seeder's new notes. Any other value (an admin
 * chose it in Filament) is left untouched. Each changed row gets one audit row in
 * `breed_stage_param_changes` (action `updated`, user_id null, actor ACTOR, old / new of
 * the changed columns) — the pattern of the earlier decision migrations; the audit row also
 * makes the seeder skip the tuple for good. Idempotent (a row already at 7 is not a 5).
 *
 * Runs outside the migrator's transaction (own DB::transaction) so the rules cache is
 * forgotten only after the commit. down() is a no-op: one-way data change; the audit rows
 * must survive a rollback.
 */
return new class extends Migration
{
    public const ACTOR = 'system: David decision 2026-10-28';

    public const KEY = 'training_minutes_per_day';

    public const FROM = 5;

    public const TO = 7;

    /** Frozen copy of BreedStageParamsSeeder's notes for the 7-minute row (2026-10-28). */
    public const NOTES = 'Decision: potrdil David 2026-10-28 (7 min; 5 min potrdil David 2026-10-06). Game value (no literature number): S36 / S37 say 5–10 min sessions and ≤ 15 min a day for puppies; 7 min = the dog\'s daily budget (≈ 6 sessions of ~60 s, 6 cues each — school smooth playback 2026-10-28), same for every stage, split equally between the children who care for the dog (M5-R03b).';

    public $withinTransaction = false;

    public function up(): void
    {
        $breeds = DB::transaction(function (): array {
            $now = now();
            $changed = [];

            $rows = DB::table('breed_stage_params')->where('key', self::KEY)->orderBy('id')->lockForUpdate()->get();
            foreach ($rows as $row) {
                $value = $row->value === null ? null : json_decode((string) $row->value, true);
                if (! (is_int($value) || is_float($value)) || (float) $value !== (float) self::FROM) {
                    continue; // another value (an admin's choice) or already 7 — untouched
                }

                $old = ['value' => $value, 'notes' => $row->notes];
                $new = ['value' => self::TO, 'notes' => self::NOTES];

                DB::table('breed_stage_params')->where('id', $row->id)->update([
                    'value' => json_encode(self::TO),
                    'notes' => self::NOTES,
                    'updated_at' => $now,
                ]);

                DB::table('breed_stage_param_changes')->insert([
                    'breed_stage_param_id' => $row->id,
                    'breed_slug' => $row->breed_slug,
                    'stage' => $row->stage,
                    'age_from_months' => $row->age_from_months,
                    'key' => self::KEY,
                    'action' => 'updated',
                    'user_id' => null,
                    'actor' => self::ACTOR,
                    'old' => json_encode($old),
                    'new' => json_encode($new),
                    'created_at' => $now,
                ]);

                $changed[$row->breed_slug] = true;
            }

            return array_keys($changed);
        });

        // After the commit: a reader can never re-cache the 5-minute rows.
        foreach ($breeds as $slug) {
            LifeStageService::forgetBreed($slug);
        }
    }

    public function down(): void
    {
        // One-way data change (the audit rows stay).
    }
};
