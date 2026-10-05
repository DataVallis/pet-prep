<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * M2-10a / PR #25: functional index on lower(email) for case-insensitive
 * e-mail lookups (`User::findByEmail` / `User::emailTaken` query
 * `lower(email) = ?`).
 *
 * UNIQUE (`users_email_lower_unique`) when the existing rows allow it — then
 * the database itself refuses two accounts whose e-mails differ only in case.
 * If legacy rows already collide, a plain index (`users_email_lower_index`)
 * is created instead and the colliding user ids are logged; clean them up
 * (with David — production data) and re-run this migration to get the unique
 * index. NULL e-mails (PIN-only children) never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::select(
            'SELECT lower(email) AS email_lower, array_agg(id ORDER BY id) AS ids
               FROM users
              WHERE email IS NOT NULL
              GROUP BY lower(email)
             HAVING count(*) > 1'
        );

        if ($duplicates === []) {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique ON users (lower(email))');

            return;
        }

        $ids = array_map(fn (object $row): string => (string) $row->ids, $duplicates);
        Log::warning('users: e-mails differing only in case — created a NON-unique lower(email) index. Colliding user ids: '.implode(' ', $ids));
        DB::statement('CREATE INDEX IF NOT EXISTS users_email_lower_index ON users (lower(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
        DB::statement('DROP INDEX IF EXISTS users_email_lower_index');
    }
};
