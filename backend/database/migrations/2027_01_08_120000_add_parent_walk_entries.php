<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-T12 (David 2026-10-21): in adapted mode a PARENT can log walk minutes for
 * the child ("starš ima opcijo vpisati minute sprehoda, otrok ne rabi nič") —
 * on iPhone pushes are recorded automatically only by an Apple Watch.
 *
 *  - `parent_walk_entries`: one row per logged walk (child, pet, family-local
 *    date, minutes, the steps they count for, the parent who logged it).
 *    `entry_id` is the app's id of the entry: (child, entry_id) is unique, so a
 *    retried request never counts twice.
 *  - `pet_daily_steps.manual_steps`: the part of `steps` that parents logged.
 *    `steps` stays the day's total (every reader is unchanged); a health sync
 *    compares its reading with `steps - manual_steps` only, so the day's value
 *    is max(health readings) + sum(parent entries) — never double counted.
 *    `manual_minutes` = the logged minutes (parent report).
 *  - activities_log type `parent_logged_walk` (actor = the parent, value = minutes).
 *
 * The activity CHECK is widened from its CURRENT definition (as in M5-R17).
 * Additive; down() removes rows, the value, the column and the table.
 */
return new class extends Migration
{
    private const ACTIVITY = 'parent_logged_walk';

    public function up(): void
    {
        Schema::create('parent_walk_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->foreignId('child_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('local_date');
            $table->unsignedSmallInteger('minutes');
            $table->unsignedInteger('steps');
            $table->string('entry_id', 64);
            $table->timestamps();

            $table->unique(['child_user_id', 'entry_id']);
            $table->index(['pet_id', 'local_date']);
        });

        Schema::table('pet_daily_steps', function (Blueprint $table) {
            $table->unsignedInteger('manual_steps')->default(0);
            $table->unsignedSmallInteger('manual_minutes')->default(0);
        });

        $this->widen(self::ACTIVITY);
    }

    public function down(): void
    {
        DB::table('activities_log')->where('activity_type', self::ACTIVITY)->delete();
        $this->replace(array_values(array_diff($this->allowed(), [self::ACTIVITY])));

        Schema::table('pet_daily_steps', function (Blueprint $table) {
            $table->dropColumn(['manual_steps', 'manual_minutes']);
        });

        Schema::dropIfExists('parent_walk_entries');
    }

    /** @return list<string> */
    private function allowed(): array
    {
        $row = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'activities_log_activity_type_check'");
        if ($row === null) {
            throw new RuntimeException('Constraint activities_log_activity_type_check not found.');
        }
        preg_match_all("/'((?:[^']|'')*)'/", (string) $row->def, $matches);

        return array_values(array_unique(array_map(fn (string $v) => str_replace("''", "'", $v), $matches[1])));
    }

    /** @param  list<string>  $values */
    private function replace(array $values): void
    {
        $list = implode(', ', array_map(fn (string $v) => "'".str_replace("'", "''", $v)."'", $values));
        DB::statement('ALTER TABLE activities_log DROP CONSTRAINT IF EXISTS activities_log_activity_type_check');
        DB::statement("ALTER TABLE activities_log ADD CONSTRAINT activities_log_activity_type_check CHECK (activity_type IN ({$list}))");
    }

    private function widen(string $value): void
    {
        $values = $this->allowed();
        if (! in_array($value, $values, true)) {
            $this->replace([...$values, $value]);
        }
    }
};
