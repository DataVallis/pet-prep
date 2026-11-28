<?php

namespace App\Enums;

/**
 * The commands a child can train (M5-R03, David 2026-10-06; REALISM_SPEC §4).
 * AKC (S36) lists come, sit, down/stay, loose-leash walking as first
 * commands; v1 is David's choice. Mirrored by the
 * pet_training_skills_command_check / pet_training_sessions_command_check
 * constraints.
 */
enum TrainingCommand: string
{
    /** "Sedi". */
    case Sit = 'sit';

    /** "Pridi" (recall). */
    case Come = 'come';

    /** "Prostor" (go to your place / stay there). Reduces chewing (proposal). */
    case Place = 'place';

    /** "Lulat zunaj" (house training). Reduces puppy accidents (proposal). */
    case Potty = 'potty';

    /**
     * The integer stored in `activities_log.value` of a `command_learned` row (school
     * milestone, David 2026-11-28; the column is an integer). Never reuse a code.
     */
    public function code(): int
    {
        return match ($this) {
            self::Sit => 1,
            self::Come => 2,
            self::Place => 3,
            self::Potty => 4,
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
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
