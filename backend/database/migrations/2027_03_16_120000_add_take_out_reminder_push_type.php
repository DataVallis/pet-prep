<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M5-R02 morning rule (David 2026-11-08 »ja, daj 30 min«): push type
 * `take_out_reminder` — »Dobro jutro! Kuža mora po noči čim prej ven.« to the
 * caretaker children when the night's quiet stretch ends (PushType,
 * NotificationService::takeOutReminder). Mirrors the enum in
 * push_notifications_type_check; no data changes.
 */
return new class extends Migration
{
    private const PUSH_TYPE = 'take_out_reminder';

    public function up(): void
    {
        $types = $this->allowedPushTypes();
        if (! in_array(self::PUSH_TYPE, $types, true)) {
            $this->replacePushTypes([...$types, self::PUSH_TYPE]);
        }
    }

    public function down(): void
    {
        DB::table('push_notifications')->where('type', self::PUSH_TYPE)->delete();
        $this->replacePushTypes(array_values(array_diff($this->allowedPushTypes(), [self::PUSH_TYPE])));
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
