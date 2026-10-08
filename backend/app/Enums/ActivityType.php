<?php

namespace App\Enums;

enum ActivityType: string
{
    case FedPet = 'fed_pet';
    case WateredPet = 'watered_pet';
    case WalkedPet = 'walked_pet';
    case CleanedPoop = 'cleaned_poop';
    case IgnoredWarning = 'ignored_warning';
    case SignedContract = 'signed_contract';
    // M5-R01: a meal whose feed window lies entirely in quiet hours (school /
    // sleep) is done by the parent — system row, actor null, never a child
    // routine (David 2026-10-05, PRODUCT_SPEC §5).
    case ParentFedPet = 'parent_fed_pet';
    // M5-R02 (David 2026-10-06): child took the puppy out ("Pelji ven") —
    // restarts the bladder clock; not a scored routine itself.
    case TookOutPet = 'took_out_pet';
    // M5-R02: child tidied up a chewed item and gave a toy ("Pospravi in daj
    // igračo") — resolves the chewing routine like cleaned_poop a mess.
    case ResolvedChewing = 'resolved_chewing';
    // M5-R02 system rows (actor null, parent timeline): the puppy had an
    // accident / the dog chewed something. Never a child's action.
    case PetAccident = 'pet_accident';
    case PetChewed = 'pet_chewed';
    // M5-R03 (David 2026-10-06): child completed a training session (the
    // day's training routine); value = correctly timed praises.
    case TrainedPet = 'trained_pet';
    // M5-R05 (David 2026-10-07/08): a child finished a ball game / a cuddle.
    // Parent timeline only — never a routine, score or stat (value = plays
    // merged into this row, PlayService::TIMELINE merge window).
    case PlayedWithPet = 'played_with_pet';
    case CuddledPet = 'cuddled_pet';

    /**
     * Rows that describe something that happened to the dog, not a care
     * action (parent timeline `is_positive` = false for these).
     */
    public function isNegativeEvent(): bool
    {
        return in_array($this, [self::IgnoredWarning, self::PetAccident, self::PetChewed], true);
    }
}
