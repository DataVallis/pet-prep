<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hotfix 2026-10-23: /admin answered 500 since the M5-T15 deploy.
 *
 * Filament's bell (DatabaseNotifications) filters `data->format = 'filament'`,
 * which PostgreSQL compiles to `"data"->>'format'`. M5-T15 created
 * `notifications.data` as `text` (Laravel's stub), and `text ->> unknown` has
 * no operator, so every /admin page load failed. `jsonb` makes the JSON
 * operators work. Existing rows were written by Laravel's database channel
 * (json_encode), so `USING data::jsonb` converts them without loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
    }
};
