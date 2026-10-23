<?php

namespace App\Enums;

use InvalidArgumentException;

/**
 * A PHASE clip of the school or a mini-game (M5-T09 / M5-T10, APP_REFINEMENT_SPEC
 * §3 / §10, David 2026-10-20 / 21): one short clip (3 s; school performing 5 s since
 * David 2026-10-23 — media.state_media.clip_seconds)
 * per command and phase, played ONCE by the app at the right moment and held on
 * its last frame — never looped into another action.
 *
 * - School (dog, per TrainingCommand): attention (waits for the command) →
 *   performing (does it) → success (holds the position); a cue it ignores = the
 *   standing pose; potty = the dog goes to the door (David approved 2026-10-21).
 * - Ball game (dog): waiting → running (out of frame after the ball) → fetching (back).
 * - Wand game (cat): stalking (into the crouch) → pouncing (leaps, lands back in the
 *   crouch — repeatable) → catching (from the crouch, ends holding the catch).
 *   Ball and wand phases approved by David 2026-10-21 (ball airtime 6 s OK).
 *
 * Start / end frame = pose images: null = the stage's reference image (the
 * standing / idle pose), else a PetPose image (pet_media kind `pose`). The
 * ball / feather is drawn by the APP over the clip, so no prompt names a toy.
 *
 * Stored as pet_media kind `phase` (state = this value; CHECK pet_media_state_check).
 */
enum PetPhaseClip: string
{
    case SchoolSitAttention = 'school_sit_attention';
    case SchoolSitPerforming = 'school_sit_performing';
    case SchoolSitSuccess = 'school_sit_success';
    case SchoolComeAttention = 'school_come_attention';
    case SchoolComePerforming = 'school_come_performing';
    case SchoolComeSuccess = 'school_come_success';
    case SchoolPlaceAttention = 'school_place_attention';
    case SchoolPlacePerforming = 'school_place_performing';
    case SchoolPlaceSuccess = 'school_place_success';
    case SchoolPottyAttention = 'school_potty_attention';
    case SchoolPottyPerforming = 'school_potty_performing';
    case SchoolPottySuccess = 'school_potty_success';
    case BallWaiting = 'ball_waiting';
    case BallRunning = 'ball_running';
    case BallFetching = 'ball_fetching';
    case WandStalking = 'wand_stalking';
    case WandPouncing = 'wand_pouncing';
    case WandCatching = 'wand_catching';

    public const GROUP_SCHOOL = 'school';

    public const GROUP_BALL = 'ball';

    public const GROUP_WAND = 'wand';

    public const PHASE_ATTENTION = 'attention';

    public const PHASE_PERFORMING = 'performing';

    public const PHASE_SUCCESS = 'success';

    /** school | ball | wand */
    public function group(): string
    {
        return explode('_', $this->value, 2)[0];
    }

    /**
     * media.state_media.clip_seconds purpose: school | school_performing | game. David
     * 2026-10-23: the school PERFORMING clips (into sit / come / place / door) are 5 s.
     */
    public function purpose(): string
    {
        if ($this->group() !== self::GROUP_SCHOOL) {
            return 'game';
        }

        return $this->phase() === self::PHASE_PERFORMING ? 'school_performing' : 'school';
    }

    public function species(): Species
    {
        return $this->group() === self::GROUP_WAND ? Species::Cat : Species::Dog;
    }

    /** The school command of a school clip (null for game clips). */
    public function command(): ?TrainingCommand
    {
        return $this->group() === self::GROUP_SCHOOL ? TrainingCommand::from(explode('_', $this->value)[1]) : null;
    }

    /** The phase name (attention, performing, success, waiting, running, …). */
    public function phase(): string
    {
        $parts = explode('_', $this->value);

        return (string) end($parts);
    }

    /** The pose the command ends in (performing → end, success → start and end). */
    public static function commandPose(TrainingCommand $command): PetPose
    {
        return match ($command) {
            TrainingCommand::Sit => PetPose::Sit,
            TrainingCommand::Come => PetPose::Come,
            TrainingCommand::Place => PetPose::Place,
            TrainingCommand::Potty => PetPose::Door,
        };
    }

    /** Start frame: null = the reference image (idle pose). */
    public function startPose(): ?PetPose
    {
        $command = $this->command();

        return match (true) {
            $command !== null => $this->phase() === self::PHASE_SUCCESS ? self::commandPose($command) : null,
            $this === self::BallFetching => PetPose::Empty,
            // Both wand moves start from the crouch the stalking clip ends (and holds) in.
            $this === self::WandPouncing, $this === self::WandCatching => PetPose::Crouch,
            default => null,
        };
    }

    /** End frame: null = the reference image (idle pose). */
    public function endPose(): ?PetPose
    {
        $command = $this->command();

        return match (true) {
            $command !== null => $this->phase() === self::PHASE_ATTENTION ? null : self::commandPose($command),
            $this === self::BallRunning => PetPose::Empty,
            // The pounce leaps and lands back in the crouch (the next pounce starts there).
            $this === self::WandStalking, $this === self::WandPouncing => PetPose::Crouch,
            $this === self::WandCatching => PetPose::Catch,
            default => null,
        };
    }

    /**
     * Start and end frame are the same picture (school attention ref → ref, school
     * success pose → pose, ball waiting ref → ref, wand pouncing crouch → crouch).
     * Kling 3.0 Pro given the same image as start AND end frame returns an almost
     * frozen clip (production 2026-10-23), so such a clip is submitted with its
     * start frame only (FalAiService::endFrameUrl) and a "clearly visible motion" prompt.
     */
    public function sharesFrames(): bool
    {
        return $this->startPose() === $this->endPose();
    }

    /** @return list<self> the clips whose start and end frame are the same picture */
    public static function sameFrameCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c) => $c->sharesFrames()));
    }

    /**
     * What the animal does in the clip (video prompt; no people, no toy, no text).
     * Same-frame clips (sharesFrames) describe clearly visible motion that stays in
     * the start pose — they get no end frame, so the motion text has to carry it.
     */
    public function motion(): string
    {
        $command = $this->command();

        if ($command !== null) {
            return match ($this->phase()) {
                self::PHASE_ATTENTION => 'stands in the same spot, sniffs the air and looks around the room, then looks up at the camera eagerly with the ears perked and the tail wagging, waiting, not sitting or lying down',
                // David 2026-10-23 (5 s): one natural, continuous movement into the end pose —
                // production media 123 stood still and then »melted« into the sit in the last frames.
                self::PHASE_PERFORMING => match ($command) {
                    TrainingCommand::Sit => 'hears the command and sits down in the same spot: it slowly lowers its hindquarters, the back legs fold first, the front legs stay straight, then it sits upright and looks up at the camera; one smooth natural movement that starts right away, the body and legs keep their shape the whole time',
                    TrainingCommand::Come => 'hears the command and walks toward the camera with natural steps, the legs moving one after the other, then stops close in front of it and looks up; one smooth natural movement that starts right away, the body and legs keep their shape the whole time',
                    TrainingCommand::Place => 'hears the command, walks to the dog mat with natural steps, steps onto it, then lowers itself front legs first and lies down on it; one smooth natural movement that starts right away, the body and legs keep their shape the whole time',
                    TrainingCommand::Potty => 'hears the command, turns and walks to the door with natural steps and stops there with the nose close to the door; one smooth natural movement that starts right away, the body and legs keep their shape the whole time',
                },
                default => match ($command) {
                    TrainingCommand::Sit => 'stays sitting proudly, looking up at the camera happily, the tail wagging clearly across the floor, the head tilting, not getting up',
                    TrainingCommand::Come => 'stays close in front of the camera, looking up happily, the tail wagging hard, the body wiggling with joy, not walking away',
                    TrainingCommand::Place => 'stays lying on the mat, happy and proud, the tail wagging clearly, the head lifting and turning, not getting up',
                    TrainingCommand::Potty => 'waits at the door, looking back at the camera happily, the tail wagging clearly, staying there',
                },
            };
        }

        return match ($this) {
            self::BallWaiting => 'stands alert in the same spot looking up, bouncing excitedly on the front paws, the tail wagging fast, ready to run, not leaving the spot',
            self::BallRunning => 'turns and sprints out of the frame to the side, as if chasing something thrown, until the room is empty',
            self::BallFetching => 'trots back into the frame from the side and stops in its spot, happy and panting',
            self::WandStalking => 'slowly lowers itself into a crouch, eyes locked on something just above the frame, the tail tip twitching',
            self::WandPouncing => 'leaps up from the crouch, swipes with both front paws at something just above it and lands back in the same low crouch',
            self::WandCatching => 'springs forward from the crouch, lands, rolls onto its side and clasps its front paws together as if it caught something',
            default => throw new InvalidArgumentException("No motion for {$this->value}."),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * @return list<self>
     */
    public static function ofGroup(string $group): array
    {
        return array_values(array_filter(self::cases(), fn (self $c) => $c->group() === $group));
    }
}
