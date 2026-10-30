<?php

namespace App\Services\Media;

use App\Models\Pet;
use App\Models\PetMedia;
use Illuminate\Support\Collection;

/**
 * The `media` object of a pet in every API response and in PetUpdated (M4-05).
 * Typed so Scramble documents it (mobile/src/api/schema.ts).
 */
final class PetMediaPayload
{
    /**
     * @param  array<string, string>  $videos  state => signed URL, only stored videos
     * @param  list<string>  $states  entitled video states, idle first
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $referenceImageUrl,
        public readonly array $videos,
        public readonly ?string $currentVideoUrl,
        public readonly array $states,
        public readonly ?string $expiresAt,
        /** @var array<string, string> M5-T08: state => signed URL of its stored key image (never idle) */
        public readonly array $keyframes = [],
        /** @var array<string, string> M5-T08: transition (idle_to_sleeping, …) => signed URL */
        public readonly array $transitions = [],
        /** @var list<string> M5-T08: states whose stored video is a loop made from its key image */
        public readonly array $loops = [],
        /** M5-T08: the pet gets key images / loops / transitions (MediaEntitlementService::usesStateMedia) */
        public readonly bool $stateMedia = false,
        /** @var array<string, string> M5-T09 / T10: pose (sit, come, place, door, empty, crouch, catch) => signed URL */
        public readonly array $poses = [],
        /** @var array<string, string> M5-T09 / T10: phase clip (school_sit_attention, ball_running, …) => signed URL */
        public readonly array $phases = [],
        /** @var list<string> M5-T09 / T10: phase clip groups of the pet's set (school, ball, wand) */
        public readonly array $phaseGroups = [],
        /** @var array<string, float> David 2026-10-23: phase clip => length of its STORED file in seconds */
        public readonly array $phaseSeconds = [],
        /** @var array<string, array{exit_side: string}> Games phase 1: phase clip => facts measured from it (ball_running => exit_side) */
        public readonly array $phaseMeta = [],
    ) {}

    /**
     * The payload for a pet (resolves PetMediaService from the container).
     *
     * @param  Collection<int, PetMedia>|null  $slots  the pet's media rows when already loaded (M5-T06: one read for media + preparation)
     */
    public static function for(Pet $pet, ?Collection $slots = null): self
    {
        return app(PetMediaService::class)->mediaFor($pet, $slots);
    }

    /**
     * @return array{status: string, reference_image_url: string|null, videos: object, current_video_url: string|null, states: list<string>, expires_at: string|null, keyframes: object, transitions: object, loops: list<string>, state_media: bool, poses: object, phases: object, phase_seconds: object, phase_groups: list<string>, phase_meta: object}
     */
    public function toArray(): array
    {
        return [
            /**
             * pending: reference image not stored yet · failed: no image (budget, fal error) ·
             * disabled: AI media off · partial: image stored, some videos missing · ready: all stored.
             *
             * @var 'disabled'|'pending'|'failed'|'partial'|'ready'
             */
            'status' => $this->status,
            // Signed URL of our stored reference image (null until stored).
            'reference_image_url' => $this->referenceImageUrl,
            /**
             * Signed URL per stored state video, keyed by pet state (idle, sleeping, …).
             * Frugal clips (2026-10-30): a 3 s sleeping / low_energy / sick loop is served as its
             * seamless server-made ping-pong (~6 s) once made — loop it as usual.
             * Always a JSON object ({} when empty).
             *
             * @var array<string, string>
             */
            'videos' => (object) $this->videos,
            // Video for the current pet_state, falling back to idle; null if none is stored.
            'current_video_url' => $this->currentVideoUrl,
            /**
             * Video states this pet is entitled to (basic: idle + sleeping; full: all six +
             * M5-R02 behaviour videos accident (non-legacy puppy) / chewing (non-legacy dog);
             * M5-R06-07: a cat's full set has scratching (non-legacy cat), never accident / chewing).
             *
             * @var list<'idle'|'sleeping'|'low_energy'|'hungry'|'sick'|'playing'|'accident'|'chewing'|'scratching'>
             */
            'states' => $this->states,
            // When the URLs above stop working (ISO 8601); fetch the state again before.
            'expires_at' => $this->expiresAt,
            /**
             * M5-T08: signed URL of the stored KEY IMAGE per state (the pose the state's
             * loop starts in). Never `idle` — the reference image is the idle pose.
             * Shown while a state's clip is missing and in picture mode (M5-T11). Always an object.
             *
             * @var array<string, string>
             */
            'keyframes' => (object) $this->keyframes,
            /**
             * M5-T08: signed URL per stored TRANSITION clip, keyed `<from>_to_<to>`
             * (idle_to_sleeping, sleeping_to_idle). Played once on that state change,
             * then the target state's loop. Frugal clips (2026-10-30): sleeping_to_idle may be
             * idle_to_sleeping reversed on the server (missing until made). Always an object.
             *
             * @var array<string, string>
             */
            'transitions' => (object) $this->transitions,
            /**
             * M5-T08: states whose stored video is a loop made from its key image (loop the
             * native way; since 2026-10-23 start frame only, the seam may differ slightly).
             *
             * @var list<string>
             */
            'loops' => $this->loops,
            /**
             * M5-T08: this pet's media follow the state rules — a missing clip shows the state's
             * key image (or the still reference image), never a clip of another state (§0 rule 1).
             * false = the older set (substitute clips sick → sleeping → idle).
             */
            'state_media' => $this->stateMedia,
            /**
             * M5-T09 / T10: signed URL of each stored POSE image a school / game phase clip
             * starts or ends in (sit, come, place, door, empty = the room without the dog;
             * cat: crouch, catch). The standing pose is the reference image. Shown while a
             * phase clip is missing and in picture mode. Always an object.
             *
             * @var array<string, string>
             */
            'poses' => (object) $this->poses,
            /**
             * M5-T09 / T10: signed URL of each stored PHASE clip (3 s; school performing 5 s since 2026-10-23 — `phase_seconds`), played once and held on
             * its last frame — school `school_<command>_<attention|performing|success>`, dog
             * ball `ball_<waiting|running|fetching>`, cat wand `wand_<stalking|pouncing|catching>`.
             * School smooth playback (David 2026-10-28): plus clips DERIVED on the server with ffmpeg
             * (0 $ AI cost) once made — `school_<command>_attention_loop` / `school_<command>_success_loop`
             * (ping-pong, seamless loop: play looped) and `school_<command>_release` (performing reversed,
             * pose → standing, ≤ 2.5 s, played once). Missing until the server made them (fallbacks:
             * SCHOOL_SMOOTH_PLAYBACK.md §C). Games phase 1 (GAMES_SPEC §8.2, 2026-10-30):
             * `ball_waiting_loop` — `ball_waiting` ping-pong at full size (seamless loop: play looped;
             * missing = loop `ball_waiting`). Always an object.
             *
             * @var array<string, string>
             */
            'phases' => (object) $this->phases,
            /**
             * David 2026-10-23: length in seconds of each stored phase clip in `phases` — school
             * PERFORMING clips are 5 s since then, every other phase clip (and performing clips
             * made before) 3 s. The app times the school from it (the performing clip ends at
             * the obey moment). Missing key (older server) = 3. Also the real length of every derived
             * school clip (`*_attention_loop`, `*_success_loop`, `*_release`) and of `ball_waiting_loop`.
             * Always an object.
             *
             * @var array<string, float>
             */
            'phase_seconds' => (object) $this->phaseSeconds,
            /**
             * M5-T09 / T10: the phase clip groups this pet's set has (switch-independent, like
             * `state_media`). For a listed group the app shows the phase clip — or, while it is
             * missing, the phase's pose image — never a state clip instead (§0 rule 1).
             *
             * @var list<'school'|'ball'|'wand'>
             */
            'phase_groups' => $this->phaseGroups,
            /**
             * Games phase 1 (GAMES_SPEC §8.4, 2026-10-30): facts the server measured (ffmpeg, 0 $)
             * from a stored phase clip. `ball_running.exit_side` = the side the dog leaves the
             * frame on (`left` | `right`; `center` = unclear → the ball flies into the distance);
             * a superadmin may have corrected it. Missing key = not measured yet → treat as
             * `center`. Always an object.
             *
             * @var array{ball_running?: array{exit_side: 'left'|'right'|'center'}}
             */
            'phase_meta' => (object) $this->phaseMeta,
        ];
    }
}
