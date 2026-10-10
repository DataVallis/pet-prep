<?php

namespace App\Enums;

/**
 * Who may create a NEW cat (M5-R06-09, David 2026-10-10). Set in
 * /admin → Funkcije (app_settings `cats_availability`); env
 * PETPREP_CATS_ENABLED=true overrides it with `everyone`.
 * Existing cats keep working in every mode.
 */
enum CatsAvailability: string
{
    case Off = 'off';
    case TestFamilies = 'test_families';
    case Everyone = 'everyone';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Izklopljeno',
            self::TestFamilies => 'Samo testne družine',
            self::Everyone => 'Vsi',
        };
    }
}
