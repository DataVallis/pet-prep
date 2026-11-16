<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 12 h / 24 h clock (David 2026-11-16: »da se lahko nastavi 24-urni ali pa
 * 12-urni format, ker gremo globalno«; default = follow the phone, manual
 * override in the app settings).
 *
 *  - `users.time_format`: the person's choice — 'auto' (follow the phone,
 *    default) | '24h' | '12h'. Read by the app and by push copy (ClockText).
 *  - `device_push_tokens.hour12`: what »auto« means on that phone (the
 *    phone's own 12 / 24 h setting, sent by the app with POST /api/devices).
 *    Null = an app that never told us (old builds) → 24 h, exactly as before.
 *
 * Both additive; `time_format` has a constant default (no table rewrite in
 * PostgreSQL ≥ 11), `hour12` is nullable. No backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('time_format', 4)->default('auto');
        });
        // App\Enums\TimeFormat. NOT VALID + VALIDATE (QA #1259): the ADD takes only a brief
        // lock; the scan of existing rows runs under SHARE UPDATE EXCLUSIVE (writes go on).
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_time_format_check CHECK (time_format IN ('auto', '24h', '12h')) NOT VALID");
        DB::statement('ALTER TABLE users VALIDATE CONSTRAINT users_time_format_check');

        Schema::table('device_push_tokens', function (Blueprint $table) {
            $table->boolean('hour12')->nullable();
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_time_format_check');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('time_format');
        });

        Schema::table('device_push_tokens', function (Blueprint $table) {
            $table->dropColumn('hour12');
        });
    }
};
