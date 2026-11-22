<?php

namespace App\Services\Validation;

use App\Enums\ValidationSignalsMode;
use App\Models\Family;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\SpeciesAvailability;

/**
 * The admin switch »Validation signals« / »Validacijski signali« (David 2026-11-22: test in
 * production behind a switch, default off; `everyone` only after the privacy policy is updated).
 * The ONE place that decides whether a family may record the VALIDATION_PLAN §4 signals:
 * the acquisition answer, parent dashboard opens and the Phase-2 fake door. Like
 * SpeciesAvailability for cats: mode off | test_families (parents picked by e-mail in
 * /admin → Funkcije) | everyone, cached AppSettingsService::CACHE_TTL_SECONDS (switching off stops
 * new writes within ≈ 60 s). Stored data stay in every mode, and a consent withdrawal on the
 * Phase-2 screen always works.
 */
class ValidationSignals
{
    public function __construct(private readonly AppSettingsService $settings) {}

    /** May this parent's family record the signals? A child (or nobody) never may. */
    public function enabledFor(User|Family|null $subject): bool
    {
        if ($subject instanceof User) {
            if (! $subject->isParent()) {
                return false;
            }
            $subject = SpeciesAvailability::familyOfUser($subject);
        }

        return $this->settings->validationSignalsEnabledForFamily($subject);
    }

    /**
     * May the sign-up screen ask »Kje ste izvedeli za PetPrep?« / may a NEW account store the
     * answer? Only in mode `everyone` — a brand-new account is never on the test list.
     */
    public function signupEnabled(): bool
    {
        return $this->settings->validationSignalsMode() === ValidationSignalsMode::Everyone;
    }
}
