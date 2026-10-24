<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R14 / R15 / R18 / R19 / R20 follow-up (David 2026-10-24, answers (d) + (e)).
 *
 *  - pets.care_tasks_since: for an EXISTING dog switched on later
 *    (`pets:care-tasks`), the family-local midnight from which its
 *    care tasks, parent reminders and heat warning apply — never before (no
 *    slots / missed routines for earlier days). Null = from the birth (a dog
 *    created with `care_tasks_enabled`).
 *  - users.client_features / client_features_at: the UI features the user's
 *    app build declared on its latest authenticated request (header
 *    `X-PetPrep-Features`, ClientFeature values). An existing dog is switched on
 *    only once every caretaker child's app AND a parent's app declare
 *    `care_tasks`, so an old build never shows a child a slot it cannot do.
 *    Technical data about the app build only (no personal data).
 *  - pets.care_reminder_check_at: when `pets:care-tasks` next looks at the
 *    dog's groomer / bath reminder push (CareReminderPushService — the end of
 *    the current reminder period, at most 12 h ahead; null = at the next run),
 *    so a run only reads the dogs whose period may just have started.
 *  - push_notifications type `care_reminder`: the parent's groomer / bath
 *    reminder push at the start of a period.
 *
 * Additive; existing rows unchanged (every pet: since = null, every user: null).
 * Rollback (QA PR #377, M1): a dog switched on later (`care_tasks_since` set)
 * is switched OFF again before the column goes, otherwise it would count as
 * "since birth" — retroactive slots and missed routines for the days before.
 * The CHECK is widened from its CURRENT definition, so a parallel migration
 * that adds another value is never undone.
 */
return new class extends Migration
{
    private const PUSH_TYPE = 'care_reminder';

    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->timestamp('care_tasks_since')->nullable();
            $table->timestamp('care_reminder_check_at')->nullable()->index();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->json('client_features')->nullable();
            $table->timestamp('client_features_at')->nullable();
        });

        $values = $this->allowed();
        if (! in_array(self::PUSH_TYPE, $values, true)) {
            $this->replace([...$values, self::PUSH_TYPE]);
        }
    }

    public function down(): void
    {
        DB::table('push_notifications')->where('type', self::PUSH_TYPE)->delete();
        $this->replace(array_values(array_diff($this->allowed(), [self::PUSH_TYPE])));

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['client_features', 'client_features_at']);
        });
        // Never "since birth" after a rollback: switched-on existing dogs go back to off.
        DB::table('pets')->whereNotNull('care_tasks_since')->update(['care_tasks_enabled' => false]);
        Schema::table('pets', function (Blueprint $table) {
            $table->dropIndex(['care_reminder_check_at']);
            $table->dropColumn(['care_tasks_since', 'care_reminder_check_at']);
        });
    }

    /** @return list<string> */
    private function allowed(): array
    {
        $row = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'push_notifications_type_check'");
        if ($row === null) {
            throw new RuntimeException('Constraint push_notifications_type_check not found.');
        }
        preg_match_all("/'((?:[^']|'')*)'/", (string) $row->def, $matches);

        return array_values(array_unique(array_map(fn (string $v) => str_replace("''", "'", $v), $matches[1])));
    }

    /** @param  list<string>  $values */
    private function replace(array $values): void
    {
        $list = implode(', ', array_map(fn (string $v) => "'".str_replace("'", "''", $v)."'", $values));
        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT IF EXISTS push_notifications_type_check');
        DB::statement("ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ({$list}))");
    }
};
