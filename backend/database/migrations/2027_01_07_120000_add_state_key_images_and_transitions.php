<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-T08 (APP_REFINEMENT_SPEC §7, David 2026-10-20 / 21): media per state —
 * a KEY IMAGE per state (Nano Banana Pro Edit of the stage's reference
 * image), state videos that start AND end in that key image (seamless
 * loops, Kling 3.0 Pro `end_image_url`) and TRANSITION clips between two
 * states (start = key image A, end = key image B).
 *
 *  - pet_media.kind gets `keyframe` (an image; state = PetStateEnum value,
 *    never `idle` — the idle key image IS the reference image) and
 *    `transition` (a video; state = PetTransition value, e.g. idle_to_sleeping).
 *    The unique slot indexes stay (pet, kind, state) / (look, kind, state, stage).
 *  - pet_media.recipe: how a video was made — null = from the reference image
 *    (before M5-T08), `pose` = from its key image without an end frame,
 *    `loop` = start AND end frame = its key image.
 *  - pets.state_media_version: 1 = the media set from before M5-T08 (every
 *    existing pet — no automatic backfill, David 2026-10-21), 2 = key images,
 *    loops and transitions (new profiled pets; a new purchase or the admin
 *    "Generate missing" upgrades a pet). MediaEntitlementService reads it.
 *
 * Additive: no existing row changes (existing pets get version 1 through the
 * column default). Nothing is generated here. down() deletes only the new rows (their files stay on disk).
 */
return new class extends Migration
{
    private const STATES = "'idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing', 'accident', 'chewing', 'scratching'";

    private const KEYFRAME_STATES = "'sleeping', 'low_energy', 'hungry', 'sick', 'playing', 'accident', 'chewing', 'scratching'";

    private const TRANSITIONS = "'idle_to_sleeping', 'sleeping_to_idle'";

    public function up(): void
    {
        // Never queue behind a long-running transaction on pets / pet_media.
        DB::statement("SET LOCAL lock_timeout = '5s'");

        // Every existing pet (and any raw insert) stays on 1; PairingService sets 2 for
        // new profiled pets, a purchase (ChallengeService::markPaid) and the admin
        // "Generate missing" upgrade a pet explicitly.
        Schema::table('pets', function (Blueprint $table) {
            $table->unsignedSmallInteger('state_media_version')->default(1)->after('pet_look_id');
        });
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_state_media_version_check CHECK (state_media_version IN (1, 2))');

        Schema::table('pet_media', function (Blueprint $table) {
            $table->string('recipe')->nullable()->after('source_generation');
        });

        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_recipe_check CHECK (recipe IS NULL OR recipe IN ('pose', 'loop'))");
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_kind_check');
        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_kind_check CHECK (kind IN ('image', 'video', 'keyframe', 'transition'))");
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_state_check');
        DB::statement('ALTER TABLE pet_media ADD CONSTRAINT pet_media_state_check CHECK ('
            ."(kind = 'image' AND state IS NULL)"
            ." OR (kind = 'video' AND state IN (".self::STATES.'))'
            ." OR (kind = 'keyframe' AND state IN (".self::KEYFRAME_STATES.'))'
            ." OR (kind = 'transition' AND state IN (".self::TRANSITIONS.')))');
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        // Rows only: stored files stay on the disk. Ledger rows keep their cost (FK nulls).
        DB::table('pet_media')->whereIn('kind', ['keyframe', 'transition'])->whereNotNull('look_media_id')->update(['look_media_id' => null]);
        DB::table('pet_media')->whereIn('kind', ['keyframe', 'transition'])->delete();

        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_state_check');
        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_state_check CHECK ((kind = 'image' AND state IS NULL) OR (kind = 'video' AND state IN (".self::STATES.')))');
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_kind_check');
        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_kind_check CHECK (kind IN ('image', 'video'))");
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_recipe_check');

        Schema::table('pet_media', function (Blueprint $table) {
            $table->dropColumn('recipe');
        });

        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_state_media_version_check');
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('state_media_version');
        });
    }
};
