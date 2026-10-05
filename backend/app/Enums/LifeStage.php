<?php

namespace App\Enums;

/**
 * Life stage of a pet (M5-R01, REALISM_SPEC §2, David 2026-10-05).
 *
 * Boundaries are per breed and come from sourced data
 * (`breed_stage_params`, key `starts_at_months`): AAHA life stages (S11)
 * and the breed's median lifespan (S15). The parent picks the stage at
 * arrival; the stage then follows the dog's age (1 real week = 1 month).
 * Mirrored in DB CHECK constraints (pets.life_stage, breed_stage_params.stage,
 * pet_media.life_stage, pet_media_history.life_stage).
 */
enum LifeStage: string
{
    case Puppy = 'puppy';
    case Young = 'young';
    case Adult = 'adult';
    case Senior = 'senior';

    /**
     * Stages in age order.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Puppy, self::Young, self::Adult, self::Senior];
    }

    /**
     * Visual cue for the AI reference image (PetAppearancePrompt). Pet-only
     * description; never a name or other personal data.
     */
    public function promptCue(): string
    {
        return match ($this) {
            self::Puppy => 'a young puppy with clear puppy proportions: a rounded head that is large for the body, '
                .'a short muzzle, big paws, short legs and a soft, fluffy puppy coat',
            self::Young => 'an adolescent young dog, almost adult-sized but still slightly lanky and leggy, '
                .'with the adult coat coming in',
            self::Adult => 'a fully grown adult dog in its prime, in good healthy condition',
            self::Senior => 'a healthy senior dog with a greying muzzle and some grey around the eyes, '
                .'a calm, relaxed posture and a slightly softer body',
        };
    }
}
