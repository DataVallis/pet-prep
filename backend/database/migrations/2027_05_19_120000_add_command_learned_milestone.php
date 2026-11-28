<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * School milestone (David 2026-11-28): the first time a dog LEARNS a command
 * (PetTrainingSkill::isMastered — displayed progress 100, ≥ 99.5) is celebrated.
 *
 *  - pet_training_skills.learned_at (nullable): the moment the command was
 *    learned. Written once, only by TrainingService (finish / starting progress).
 *    Backfill of every row that is ALREADY learned (QA #1737 M1), in order:
 *      1. exact: the `finished_at` of the earliest completed session that
 *         crossed the threshold (progress_before < 99.5 ≤ before + gain);
 *      2. known on arrival (no session ever, `sessions_completed` = 0): the
 *         row's `created_at` (applyStartingProgress wrote it at arrival);
 *      3. fallback (no crossing session kept): `updated_at`, else
 *         `created_at` — a learned row is written only by a finish (the daily
 *         decay skips it), so at or after the real moment; never null.
 *    The backfill is plain SQL: no push, no timeline row, no broadcast.
 *  - activities_log type `command_learned` (the parent timeline row; actor =
 *    the child whose session crossed the threshold, value = TrainingCommand::code()).
 *  - push_notifications type `command_learned` (parents only, PushType::CommandLearned).
 *
 * CHECKs are widened from their CURRENT definition (pg_constraint), so a
 * parallel migration that adds another value is never undone. down() removes
 * the rows, the values and the column.
 */
return new class extends Migration
{
    private const TYPE = 'command_learned';

    /** PetTrainingSkill::isMasteredProgress — the displayed value (half up) is 100. */
    private const MASTERED_FROM = 99.5;

    public function up(): void
    {
        Schema::table('pet_training_skills', function (Blueprint $table) {
            $table->timestamp('learned_at')->nullable();
        });

        // 1. Exact: the session that crossed the threshold (the earliest, should there be more).
        DB::update(
            "UPDATE pet_training_skills s SET learned_at = x.finished_at
               FROM (SELECT DISTINCT ON (pet_id, command) pet_id, command, finished_at
                       FROM pet_training_sessions
                      WHERE status = 'completed' AND finished_at IS NOT NULL
                        AND progress_before < ? AND progress_before + progress_gain >= ?
                      ORDER BY pet_id, command, finished_at) x
              WHERE s.pet_id = x.pet_id AND s.command = x.command
                AND s.progress >= ? AND s.learned_at IS NULL",
            [self::MASTERED_FROM, self::MASTERED_FROM, self::MASTERED_FROM],
        );
        // 2. Known on arrival (never practised): the row was written at arrival.
        DB::table('pet_training_skills')
            ->where('progress', '>=', self::MASTERED_FROM)
            ->whereNull('learned_at')
            ->where('sessions_completed', 0)
            ->update(['learned_at' => DB::raw('COALESCE(created_at, updated_at, NOW())')]);
        // 3. Fallback: the last write (a learned row is written only by a finish).
        DB::table('pet_training_skills')
            ->where('progress', '>=', self::MASTERED_FROM)
            ->whereNull('learned_at')
            ->update(['learned_at' => DB::raw('COALESCE(updated_at, created_at, NOW())')]);

        $this->widen('activities_log', 'activities_log_activity_type_check', 'activity_type');
        $this->widen('push_notifications', 'push_notifications_type_check', 'type');
    }

    public function down(): void
    {
        DB::table('activities_log')->where('activity_type', self::TYPE)->delete();
        DB::table('push_notifications')->where('type', self::TYPE)->delete();
        $this->narrow('activities_log', 'activities_log_activity_type_check', 'activity_type');
        $this->narrow('push_notifications', 'push_notifications_type_check', 'type');

        Schema::table('pet_training_skills', function (Blueprint $table) {
            $table->dropColumn('learned_at');
        });
    }

    /**
     * The values the CHECK constraint allows today.
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
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$column} IN ({$list})) NOT VALID");
        DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
    }

    private function widen(string $table, string $constraint, string $column): void
    {
        $values = $this->allowed($constraint);
        if (! in_array(self::TYPE, $values, true)) {
            $this->replace($table, $constraint, $column, [...$values, self::TYPE]);
        }
    }

    private function narrow(string $table, string $constraint, string $column): void
    {
        $this->replace($table, $constraint, $column, array_values(array_diff($this->allowed($constraint), [self::TYPE])));
    }
};
