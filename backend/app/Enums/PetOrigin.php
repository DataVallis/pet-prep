<?php

namespace App\Enums;

/**
 * Where the dog came from (M5-R01, REALISM_SPEC §1, David 2026-10-05):
 * bought from a breeder or adopted from a shelter. Chosen by the parent at
 * pet creation; free for the mutt in every combination.
 * Mirrored in the pets_origin_check constraint.
 */
enum PetOrigin: string
{
    case Bought = 'bought';
    case Adopted = 'adopted';

    /**
     * Visual cue for the AI reference image. Adopted dogs are shown neutral and
     * healthy — no "sad shelter dog" clichés (David 2026-10-05).
     *
     * Cats (M5-R06-07): "adopted" also covers a cat given by acquaintances
     * (CAT_SPEC §1, David 2026-10-08), so the cat cue names no shelter.
     */
    public function promptCue(Species $species = Species::Dog): ?string
    {
        if ($species === Species::Cat) {
            return match ($this) {
                self::Bought => null,
                self::Adopted => 'A recently adopted cat, healthy, clean and well cared for, '
                    .'with a calm, neutral, relaxed expression in its new home',
            };
        }

        return match ($this) {
            self::Bought => null,
            self::Adopted => 'A recently adopted dog from an animal shelter, healthy, clean and well cared for, '
                .'with a calm, neutral, relaxed expression in its new home',
        };
    }
}
