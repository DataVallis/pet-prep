<?php

namespace App\Enums;

/**
 * M5-R17 puppy socialisation (David 2026-10-12): the kinds of "new
 * experience" (»nova izkušnja«) a child can pick for the puppy's weekly task
 * — a new person, another dog, a new sound, a new place. Stored as
 * `activities_log.value` of a `socialised_pet` row via code() (the column is
 * an integer); the apps word them (EN + SL).
 */
enum SocialisationExperience: string
{
    case Person = 'person';
    case Dog = 'dog';
    case Sound = 'sound';
    case Place = 'place';

    /** The integer stored in `activities_log.value` (1–4, never reused). */
    public function code(): int
    {
        return match ($this) {
            self::Person => 1,
            self::Dog => 2,
            self::Sound => 3,
            self::Place => 4,
        };
    }

    public static function fromCode(?int $code): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->code() === $code) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $e) => $e->value, self::cases());
    }
}
