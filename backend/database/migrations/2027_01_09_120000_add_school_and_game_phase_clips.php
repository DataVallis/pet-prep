<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M5-T09 / M5-T10 (APP_REFINEMENT_SPEC §3 / §10, David 2026-10-20 / 21):
 * school clips per command and phase, game clips per phase.
 *
 *  - pet_media.kind gets `pose` (an image: an edit of the stage's reference
 *    image in the pose a phase clip starts / ends in; state = PetPose value)
 *    and `phase` (a 3 s clip played once; state = PetPhaseClip value).
 *  - The unique slot indexes stay (pet, kind, state) / (look, kind, state, stage).
 *
 * Additive: no existing row changes, nothing is generated here (only pets that
 * use state media get them, from their next pipeline run — never automatically
 * for existing pets). down() deletes only the new rows (files stay on the disk).
 */
return new class extends Migration
{
    private const STATES = "'idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing', 'accident', 'chewing', 'scratching'";

    private const KEYFRAME_STATES = "'sleeping', 'low_energy', 'hungry', 'sick', 'playing', 'accident', 'chewing', 'scratching'";

    private const TRANSITIONS = "'idle_to_sleeping', 'sleeping_to_idle'";

    private const POSES = "'sit', 'come', 'place', 'door', 'empty', 'crouch', 'catch'";

    private const PHASES = "'school_sit_attention', 'school_sit_performing', 'school_sit_success', "
        ."'school_come_attention', 'school_come_performing', 'school_come_success', "
        ."'school_place_attention', 'school_place_performing', 'school_place_success', "
        ."'school_potty_attention', 'school_potty_performing', 'school_potty_success', "
        ."'ball_waiting', 'ball_running', 'ball_fetching', 'wand_stalking', 'wand_pouncing', 'wand_catching'";

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_kind_check');
        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_kind_check CHECK (kind IN ('image', 'video', 'keyframe', 'transition', 'pose', 'phase'))");
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_state_check');
        DB::statement('ALTER TABLE pet_media ADD CONSTRAINT pet_media_state_check CHECK ('.self::stateCheck(true).')');
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        DB::table('pet_media')->whereIn('kind', ['pose', 'phase'])->whereNotNull('look_media_id')->update(['look_media_id' => null]);
        DB::table('pet_media')->whereIn('kind', ['pose', 'phase'])->delete();

        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_state_check');
        DB::statement('ALTER TABLE pet_media ADD CONSTRAINT pet_media_state_check CHECK ('.self::stateCheck(false).')');
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_kind_check');
        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_kind_check CHECK (kind IN ('image', 'video', 'keyframe', 'transition'))");
    }

    private static function stateCheck(bool $phases): string
    {
        return "(kind = 'image' AND state IS NULL)"
            ." OR (kind = 'video' AND state IN (".self::STATES.'))'
            ." OR (kind = 'keyframe' AND state IN (".self::KEYFRAME_STATES.'))'
            ." OR (kind = 'transition' AND state IN (".self::TRANSITIONS.'))'
            .($phases ? " OR (kind = 'pose' AND state IN (".self::POSES."))  OR (kind = 'phase' AND state IN (".self::PHASES.'))' : '');
    }
};
