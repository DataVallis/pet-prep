<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R14 / R15 / R18 / R19 / R20 breed care needs (David 2026-10-24).
 *
 *  - pets.care_tasks_enabled: set once at creation for a profiled DOG when both
 *    the parent's generate-pin and the child's pin-login declared
 *    `features: ["care_tasks"]` (ClientFeature) — an older app has no care
 *    mini-games / reminders, so its dog never shows slots it could not do.
 *    Every existing pet: false (never backfilled).
 *  - breed_stage_params keys `*_frequency` ([times, period_days]) and
 *    `no_walk_in_heat` (rows from the insert-only seeder, only breeds whose
 *    source states a frequency).
 *  - pet_care_sessions kinds and pet_daily_routines types `tooth_brushing`,
 *    `fold_cleaning`, `ear_cleaning`, `nail_trim` (the child's mini-games).
 *  - activities_log types `brushed_teeth`, `cleaned_folds`, `cleaned_ears`,
 *    `trimmed_nails` (child) and `parent_groomer_visit`, `parent_bathed_pet`
 *    (the parent confirmed a reminder).
 *
 * Every CHECK is widened from its CURRENT definition (pg_constraint), so a
 * parallel migration that adds another value is never undone. Additive;
 * down() removes the rows, the values and the column.
 */
return new class extends Migration
{
    private const STAGE_KEYS = [
        'tooth_brushing_frequency', 'fold_cleaning_frequency', 'ear_cleaning_frequency',
        'nail_trim_frequency', 'groomer_visit_frequency', 'bath_frequency', 'no_walk_in_heat',
    ];

    private const CHILD_TASKS = ['tooth_brushing', 'fold_cleaning', 'ear_cleaning', 'nail_trim'];

    private const ACTIVITIES = [
        'brushed_teeth', 'cleaned_folds', 'cleaned_ears', 'trimmed_nails', 'parent_groomer_visit', 'parent_bathed_pet',
    ];

    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->boolean('care_tasks_enabled')->default(false);
        });

        $this->widen('breed_stage_params', 'breed_stage_params_key_check', 'key', self::STAGE_KEYS);
        $this->widen('pet_care_sessions', 'pet_care_sessions_kind_check', 'kind', self::CHILD_TASKS);
        $this->widen('pet_daily_routines', 'pet_daily_routines_type_check', 'routine_type', self::CHILD_TASKS);
        $this->widen('activities_log', 'activities_log_activity_type_check', 'activity_type', self::ACTIVITIES);
    }

    public function down(): void
    {
        DB::table('activities_log')->whereIn('activity_type', self::ACTIVITIES)->delete();
        DB::table('pet_daily_routines')->whereIn('routine_type', self::CHILD_TASKS)->delete();
        DB::table('pet_care_sessions')->whereIn('kind', self::CHILD_TASKS)->delete();
        DB::table('breed_stage_params')->whereIn('key', self::STAGE_KEYS)->delete();
        $this->narrow('activities_log', 'activities_log_activity_type_check', 'activity_type', self::ACTIVITIES);
        $this->narrow('pet_daily_routines', 'pet_daily_routines_type_check', 'routine_type', self::CHILD_TASKS);
        $this->narrow('pet_care_sessions', 'pet_care_sessions_kind_check', 'kind', self::CHILD_TASKS);
        $this->narrow('breed_stage_params', 'breed_stage_params_key_check', 'key', self::STAGE_KEYS);

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('care_tasks_enabled');
        });
    }

    /**
     * The values the CHECK constraint allows today (PostgreSQL prints an IN
     * list as `= ANY (ARRAY['a'::text, …])` / `::character varying`).
     *
     * @return list<string>
     */
    private function allowed(string $constraint): array
    {
        $row = DB::selectOne('SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?', [$constraint]);
        if ($row === null) {
            throw new RuntimeException("Constraint {$constraint} not found.");
        }
        preg_match_all("/'((?:[^']|'')*)'/", (string) $row->def, $matches);

        return array_values(array_unique(array_map(fn (string $v) => str_replace("''", "'", $v), $matches[1])));
    }

    /**
     * @param  list<string>  $values
     */
    private function replace(string $table, string $constraint, string $column, array $values): void
    {
        $list = implode(', ', array_map(fn (string $v) => "'".str_replace("'", "''", $v)."'", $values));
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$column} IN ({$list}))");
    }

    /**
     * @param  list<string>  $add
     */
    private function widen(string $table, string $constraint, string $column, array $add): void
    {
        $values = $this->allowed($constraint);
        $missing = array_values(array_diff($add, $values));
        if ($missing !== []) {
            $this->replace($table, $constraint, $column, [...$values, ...$missing]);
        }
    }

    /**
     * @param  list<string>  $remove
     */
    private function narrow(string $table, string $constraint, string $column, array $remove): void
    {
        $this->replace($table, $constraint, $column, array_values(array_diff($this->allowed($constraint), $remove)));
    }
};
