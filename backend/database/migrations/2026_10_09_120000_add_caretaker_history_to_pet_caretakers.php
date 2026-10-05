<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Caretaker history (M2-08, PR #29 review): a deleted child's caretaker row
 * on a SHARED pet stays as a tombstone (user_id null, ended_at set,
 * started_at = when that child started caring), so the remaining children's
 * past fair-share Care Score does not change.
 *
 *  - started_at / ended_at: nullable, additive, no backfill (null started_at
 *    = derive the start from the contract / birth as before; null ended_at =
 *    still caring).
 *  - user_id nullable, FK ON DELETE SET NULL instead of CASCADE (a raw user
 *    delete also leaves a tombstone instead of rewriting history).
 *  - "one active pet per child" ignores ended rows.
 *
 * down(): tombstone rows (user_id null) are DELETED — they cannot satisfy the
 * old NOT NULL column. Rolling back after a child deletion therefore changes
 * the remaining children's past scores again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_caretakers', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('requires_contract');
            $table->timestamp('ended_at')->nullable()->after('started_at');
        });

        Schema::table('pet_caretakers', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        DB::statement('ALTER TABLE pet_caretakers ALTER COLUMN user_id DROP NOT NULL');
        Schema::table('pet_caretakers', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS pet_caretakers_one_active_pet_per_child;
            CREATE UNIQUE INDEX pet_caretakers_one_active_pet_per_child
                ON pet_caretakers (user_id) WHERE pet_is_active AND ended_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::table('pet_caretakers')->whereNull('user_id')->delete();

        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS pet_caretakers_one_active_pet_per_child;
            CREATE UNIQUE INDEX pet_caretakers_one_active_pet_per_child
                ON pet_caretakers (user_id) WHERE pet_is_active;
        SQL);

        Schema::table('pet_caretakers', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        DB::statement('ALTER TABLE pet_caretakers ALTER COLUMN user_id SET NOT NULL');
        Schema::table('pet_caretakers', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->dropColumn(['started_at', 'ended_at']);
        });
    }
};
