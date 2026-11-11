<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M6-U02 — the day-7 parent reminder of a free pet (FREE_PET_UPGRADE_SPEC U5 / D1 / §5.7,
 * David 2026-11-10).
 *
 *  - `pets.upgrade_reminder_sent_at`: when the tick decided the one reminder of this free pet
 *    (written once, UpgradeReminderService::remind — one push to the parents, the dashboard
 *    card from then on).
 *  - `pets.upgrade_reminder_next_check_at`: when the tick looks at the pet again (7 days after
 *    the contract, a few minutes later while it is locked, or "never" once decided / not
 *    applicable) — so the minutely tick never recomputes every pet.
 *  - push type `upgrade_reminder` (read from the live constraint, so the list of earlier
 *    migrations is never repeated here).
 *
 * Additive, nothing backfilled: an existing free pet whose first 7 days are over simply gets
 * no reminder (its first check sees that the 7 days ended before now — see the service).
 */
return new class extends Migration
{
    private const TYPE = 'upgrade_reminder';

    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->timestamp('upgrade_reminder_sent_at')->nullable();
            $table->timestamp('upgrade_reminder_next_check_at')->nullable();
        });

        $types = $this->allowedPushTypes();
        if (! in_array(self::TYPE, $types, true)) {
            $this->replacePushTypes([...$types, self::TYPE]);
        }
    }

    public function down(): void
    {
        DB::table('push_notifications')->where('type', self::TYPE)->delete();
        $this->replacePushTypes(array_values(array_filter($this->allowedPushTypes(), fn (string $t) => $t !== self::TYPE)));

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['upgrade_reminder_sent_at', 'upgrade_reminder_next_check_at']);
        });
    }

    /** @return list<string> */
    private function allowedPushTypes(): array
    {
        $row = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'push_notifications_type_check'");
        if ($row === null) {
            throw new RuntimeException('Constraint push_notifications_type_check not found.');
        }
        preg_match_all("/'((?:[^']|'')*)'/", (string) $row->def, $matches);

        return array_values(array_unique(array_map(fn (string $v) => str_replace("''", "'", $v), $matches[1])));
    }

    /** @param  list<string>  $values */
    private function replacePushTypes(array $values): void
    {
        $list = implode(', ', array_map(fn (string $v) => "'".str_replace("'", "''", $v)."'", $values));
        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT IF EXISTS push_notifications_type_check');
        DB::statement("ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ({$list})) NOT VALID");
        DB::statement('ALTER TABLE push_notifications VALIDATE CONSTRAINT push_notifications_type_check');
    }
};
