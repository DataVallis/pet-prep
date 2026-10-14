<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M2-11 — profile switching on one phone (David 2026-10-14).
 *
 * A family phone can hold a parent session AND a child session at the same
 * time; both must get their own pushes. One Expo token (= one app install) may
 * therefore have one row per USER: UNIQUE (user_id, expo_push_token) instead
 * of UNIQUE (expo_push_token). PushDeviceService still moves the install away
 * from anyone outside the registering user's family, and from rows without a
 * live Sanctum session, so a phone never gets a stranger's pushes. Each row
 * keeps its user's audience (parent / child texts unchanged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_push_tokens', function (Blueprint $table) {
            $table->dropUnique(['expo_push_token']);
            $table->unique(['user_id', 'expo_push_token']);
            $table->index('expo_push_token');
        });
    }

    /**
     * DESTRUCTIVE for shared phones: going back to one row per install DELETES
     * every other row of an install — only the most recently seen registration
     * stays (NULL timestamps count as the epoch, ties by id, so exactly one row
     * per token always remains).
     */
    public function down(): void
    {
        DB::statement("DELETE FROM device_push_tokens d USING device_push_tokens k
            WHERE d.expo_push_token = k.expo_push_token
              AND (COALESCE(d.last_seen_at, d.created_at, TIMESTAMP '1970-01-01'), d.id)
                < (COALESCE(k.last_seen_at, k.created_at, TIMESTAMP '1970-01-01'), k.id)");

        Schema::table('device_push_tokens', function (Blueprint $table) {
            $table->dropIndex(['expo_push_token']);
            $table->dropUnique(['user_id', 'expo_push_token']);
            $table->unique('expo_push_token');
        });
    }
};
