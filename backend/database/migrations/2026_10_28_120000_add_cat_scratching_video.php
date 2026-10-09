<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M5-R06-07 — cat AI media (CAT_SPEC §8): the cat's behaviour video
 * `scratching` ("opraskala je kavč"). Video slots only — pets.pet_state
 * keeps the six classic states (pets_pet_state_check is unchanged), like
 * the dog's `accident` / `chewing` (M5-R02).
 *
 * Additive; down() removes scratching video slots and restores the check.
 * down() deletes only the rows — stored scratching files stay on the
 * pet_media disk (orphaned; removed with the pet's directory on delete).
 *
 * lock_timeout: the ALTERs take an ACCESS EXCLUSIVE lock on pet_media; give
 * up after 5 s instead of queueing behind a long transaction (the deploy
 * retries) — SET LOCAL lasts only for the migration's transaction.
 */
return new class extends Migration
{
    private const STATES_OLD = "'idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing', 'accident', 'chewing'";

    private const STATES_NEW = self::STATES_OLD.", 'scratching'";

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_state_check');
        DB::statement('ALTER TABLE pet_media ADD CONSTRAINT pet_media_state_check CHECK ((kind = \'image\' AND state IS NULL) OR (kind = \'video\' AND state IN ('.self::STATES_NEW.')))');
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        // Rows only: stored files stay on the disk (see the class comment).
        DB::table('pet_media')->where('state', 'scratching')->delete();
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_state_check');
        DB::statement('ALTER TABLE pet_media ADD CONSTRAINT pet_media_state_check CHECK ((kind = \'image\' AND state IS NULL) OR (kind = \'video\' AND state IN ('.self::STATES_OLD.')))');
    }
};
