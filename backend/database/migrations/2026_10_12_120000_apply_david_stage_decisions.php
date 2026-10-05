<?php

use App\Services\LifeStageService;
use Database\Seeders\BreedStageParamsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R01b — David's answers to the M5-R01 open questions (2026-10-05),
 * applied ONCE to the `breed_stage_params` rows PR #37 already seeded in
 * production (BreedStageParamsSeeder is insert-only and never updates them).
 *
 * Rows: every seeder row marked `decision = BreedStageParamsSeeder::CONFIRMED`
 * ("potrdil David 2026-10-05") — feed windows of 4 / 3 puppy meals (1 h →
 * 2 h: 07–09, 11–13, 15–17, 19–21 / 07–09, 13–15, 19–21), stage boundaries,
 * arrival ages, puppy / young minutes per age month, mutt adult and senior
 * exercise minutes, senior meals. Each becomes `verified = true` with the
 * decision in `notes` (source ids unchanged — the decision is not literature).
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
 * Adds `breed_stage_param_changes.actor` (nullable, label of a non-user
 * writer; Filament shows it when there is no user). down() drops the column
 * only — the data change is one-way (an admin can edit values in Filament).
 */
return new class extends Migration
{
    public const ACTOR = 'system: David decision 2026-10-05';

    /** Feed windows seeded by PR #37 (1 h each), keyed by "stage|from". */
    private const PR37_WINDOWS = [
        'puppy|0' => [['07:00', '08:00'], ['11:00', '12:00'], ['15:00', '16:00'], ['19:00', '20:00']],
        'puppy|3' => [['07:00', '08:00'], ['13:00', '14:00'], ['19:00', '20:00']],
    ];

    /** Columns compared / written (the tuple itself never changes). */
    private const FIELDS = ['value', 'unit', 'source_id', 'confidence', 'verified', 'quote', 'notes', 'data_ref'];

    public function up(): void
    {
        if (! Schema::hasColumn('breed_stage_param_changes', 'actor')) {
            Schema::table('breed_stage_param_changes', function (Blueprint $table) {
                $table->string('actor')->nullable();
            });
        }

        $targets = array_values(array_filter(
            BreedStageParamsSeeder::rows(),
            fn (array $row): bool => $row['decision'] === BreedStageParamsSeeder::CONFIRMED,
        ));

        $breeds = DB::transaction(function () use ($targets): array {
            $touched = BreedStageParamsSeeder::touchedTuples();
            $now = now();
            $changedBreeds = [];

            foreach ($targets as $row) {
                $tuple = "{$row['breed_slug']}|{$row['stage']}|{$row['age_from_months']}|{$row['key']}";
                if (isset($touched[$tuple])) {
                    continue; // an admin edited / re-keyed / deleted it — never overwrite
                }

                $current = DB::table('breed_stage_params')
                    ->where('breed_slug', $row['breed_slug'])
                    ->where('stage', $row['stage'])
                    ->where('age_from_months', $row['age_from_months'])
                    ->where('key', $row['key'])
                    ->lockForUpdate()
                    ->first();
                if ($current === null) {
                    continue; // not seeded yet: the seeder inserts the confirmed row
                }

                $currentValue = $current->value === null ? null : json_decode((string) $current->value, true);
                $seededValue = $row['key'] === 'feed_windows'
                    ? (self::PR37_WINDOWS["{$row['stage']}|{$row['age_from_months']}"] ?? $row['value'])
                    : $row['value'];
                if ($currentValue !== $seededValue && $currentValue !== $row['value']) {
                    Log::warning('M5-R01b: breed_stage_params row changed outside Filament, David decision not applied', [
                        'tuple' => $tuple, 'value' => $currentValue,
                    ]);

                    continue;
                }

                $target = BreedStageParamsSeeder::columns($row);
                $old = [];
                $new = [];
                foreach (self::FIELDS as $field) {
                    $before = $field === 'value' ? $currentValue : $current->{$field};
                    $after = $field === 'value' ? $row['value'] : $target[$field];
                    if ($field === 'verified') {
                        $before = (bool) $before;
                    }
                    if ($before !== $after) {
                        $old[$field] = $before;
                        $new[$field] = $after;
                    }
                }
                if ($new === []) {
                    continue; // already applied
                }

                DB::table('breed_stage_params')->where('id', $current->id)->update(array_merge(
                    array_intersect_key($target, $new),
                    ['updated_at' => $now],
                ));

                DB::table('breed_stage_param_changes')->insert([
                    'breed_stage_param_id' => $current->id,
                    'breed_slug' => $row['breed_slug'],
                    'stage' => $row['stage'],
                    'age_from_months' => $row['age_from_months'],
                    'key' => $row['key'],
                    'action' => 'updated',
                    'user_id' => null,
                    'actor' => self::ACTOR,
                    'old' => json_encode($old),
                    'new' => json_encode($new),
                    'created_at' => $now,
                ]);

                $changedBreeds[$row['breed_slug']] = true;
            }

            return array_keys($changedBreeds);
        });

        foreach ($breeds as $slug) {
            LifeStageService::forgetBreed($slug);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('breed_stage_param_changes', 'actor')) {
            Schema::table('breed_stage_param_changes', function (Blueprint $table) {
                $table->dropColumn('actor');
            });
        }
    }
};
