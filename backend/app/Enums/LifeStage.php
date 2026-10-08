<?php

namespace App\Enums;

/**
 * Life stage of a pet (M5-R01, REALISM_SPEC §2, David 2026-10-05).
 *
 * Boundaries are per breed and come from sourced data
 * (`breed_stage_params`, key `starts_at_months`): AAHA life stages (S11)
 * and the breed's median lifespan (S15). The parent picks the stage at
 * arrival; the stage then follows the dog's age (1 program week = 1 month;
 * payment-lock time does not count — M3-11b).
 * Mirrored in DB CHECK constraints (pets.life_stage, breed_stage_params.stage,
 * pet_media.life_stage, pet_media_history.life_stage).
 *
 * Cats (M5-R06-03, CAT_SPEC §2) reuse the same four values: puppy = kitten,
 * young = young cat, adult = mature cat, senior = senior cat. Names and AI
 * prompt cues depend on the species; the default (dog) output is unchanged.
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
     * Display name of the stage for a species (lang/{en,sl}/life_stages.php,
     * same wording as the apps). Null locale = the request locale.
     */
    public function label(Species $species = Species::Dog, ?string $locale = null): string
    {
        return (string) __("life_stages.{$species->value}.{$this->value}", [], $locale);
    }

    /**
     * Visual cue for the AI reference image (PetAppearancePrompt). Pet-only
     * description; never a name or other personal data. Cat cues are a draft
     * (CAT_SPEC §8 "faze v promptu", still (D)) — used once M5-R06-07 gives
     * cats media.
     */
    public function promptCue(Species $species = Species::Dog): string
    {
        if ($species === Species::Cat) {
            return match ($this) {
                self::Puppy => 'a young kitten with clear kitten proportions: large ears and a head that are large for the body, '
                    .'short legs and a soft, fluffy kitten coat',
                self::Young => 'a young adult cat, lean and agile, with its full adult coat',
                self::Adult => 'a fully grown adult cat in its prime, in good healthy condition',
                self::Senior => 'a healthy senior cat, slightly leaner, with a slightly less glossy coat '
                    .'and a calm, relaxed posture',
            };
        }

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
