<?php

namespace App\Enums;

use InvalidArgumentException;

/**
 * A POSE image for the school and game phase clips (M5-T09 / M5-T10,
 * APP_REFINEMENT_SPEC §3 / §10): an EDIT of the stage's reference image
 * (Nano Banana Pro Edit — the same animal, room, light and framing) in the
 * pose a phase clip starts or ends in. The reference image itself is the
 * standing (idle) pose and has no row of its own.
 *
 * Stored as pet_media kind `pose` (state = this value; CHECK
 * pet_media_state_check). Which poses a pet gets: the start / end poses of
 * its phase clips (MediaEntitlementService::posesFor()).
 *
 * The prompts never name a ball, a feather or another toy: the app draws
 * the toy itself on top of the clip, so the text always matches what is seen.
 */
enum PetPose: string
{
    /** Dog: sitting (school "Sedi"). */
    case Sit = 'sit';

    /** Dog: standing close in front of the camera (school "Pridi"). */
    case Come = 'come';

    /** Dog: lying on its mat (school "Prostor"). */
    case Place = 'place';

    /** Dog: waiting at the door to go out (school "Lulat zunaj"). */
    case Door = 'door';

    /** Dog: the same room without the dog (the dog ran out of frame after the ball). */
    case Empty = 'empty';

    /** Cat: crouched, ready to pounce (wand game). */
    case Crouch = 'crouch';

    /** Cat: on its side, front paws clasped (wand catch). */
    case Catch = 'catch';

    public function species(): Species
    {
        return match ($this) {
            self::Crouch, self::Catch => Species::Cat,
            default => Species::Dog,
        };
    }

    /** The pose of the edit prompt (no person, no toy, no text). */
    public function prompt(): string
    {
        return match ($this) {
            self::Sit => 'sitting upright on the floor in the same spot, front paws together, looking up attentively at the camera',
            self::Come => 'standing close in front of the camera in the foreground of the room, looking up at the camera happily, tail raised',
            self::Place => 'lying down calmly on a small grey dog mat on the floor, head up, looking at the camera',
            self::Door => 'standing at a closed door at the side of the same room, nose close to the door, looking back over the shoulder at the camera',
            self::Empty => throw new InvalidArgumentException('The empty room has no pose prompt.'),
            self::Crouch => 'crouched low on the floor, hindquarters slightly raised, eyes fixed on something just above the frame, ready to pounce',
            self::Catch => 'lying on its side on the floor with both front paws clasped together close to its mouth, playful',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
