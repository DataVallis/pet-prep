<?php

namespace App\Enums;

/**
 * Who gets the validation signals (VALIDATION_PLAN §4.1 / §4.4 — acquisition answer, dashboard
 * opens, Phase-2 fake door), David 2026-11-22: tested in production behind an admin switch,
 * default off; `everyone` only after the privacy policy is updated. Set in /admin → Feature
 * switches (app_settings `validation_signals`), same pattern as CatsAvailability.
 * Data already stored stays in every mode; a consent withdrawal always works.
 */
enum ValidationSignalsMode: string
{
    case Off = 'off';
    case TestFamilies = 'test_families';
    case Everyone = 'everyone';

    public function label(): string
    {
        return __('admin.features.modes.'.$this->value);
    }
}
