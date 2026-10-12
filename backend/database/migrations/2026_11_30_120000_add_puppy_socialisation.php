<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R17 puppy socialisation (David 2026-10-12, "tedenska naloga"): while
 * the dog is a puppy the child gets one weekly "new experience" (a new
 * person, another dog, a new sound, a new place) — marked with one tap, shown
 * to the parent, never scored.
 *
 *  - pets.socialisation_enabled: set once at creation for a profiled DOG when
 *    both the parent's generate-pin and the child's pin-login declared
 *    `features: ["socialisation"]` (ClientFeature) — an older app has no
 *    "Nova izkušnja" card, so its puppy never shows weeks it could not do.
 *    Every existing pet: false.
 *  - breed_stage_params key `socialisation_tasks_per_week` (puppy rows come
 *    from the insert-only seeder).
 *  - activities_log type `socialised_pet` (value = the experience code).
 *
 * The two CHECK constraints are widened from their CURRENT definition (read
 * from pg_constraint), not from a hard-coded list, so a migration of a
 * parallel branch that also adds a value is never undone by this one.
 *
 * Additive (expand-only); down() removes the rows, the values and the column.
 */
return new class extends Migration
{
    private const STAGE_KEY = 'socialisation_tasks_per_week';

    private const ACTIVITY = 'socialised_pet';

    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->boolean('socialisation_enabled')->default(false);
        });

        $this->widen('breed_stage_params', 'breed_stage_params_key_check', 'key', self::STAGE_KEY);
        $this->widen('activities_log', 'activities_log_activity_type_check', 'activity_type', self::ACTIVITY);
    }

    public function down(): void
    {
        DB::table('activities_log')->where('activity_type', self::ACTIVITY)->delete();
        DB::table('breed_stage_params')->where('key', self::STAGE_KEY)->delete();
        $this->narrow('activities_log', 'activities_log_activity_type_check', 'activity_type', self::ACTIVITY);
        $this->narrow('breed_stage_params', 'breed_stage_params_key_check', 'key', self::STAGE_KEY);

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('socialisation_enabled');
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

    private function widen(string $table, string $constraint, string $column, string $value): void
    {
        $values = $this->allowed($constraint);
        if (! in_array($value, $values, true)) {
            $this->replace($table, $constraint, $column, [...$values, $value]);
        }
    }

    private function narrow(string $table, string $constraint, string $column, string $value): void
    {
        $this->replace($table, $constraint, $column, array_values(array_diff($this->allowed($constraint), [$value])));
    }
};
