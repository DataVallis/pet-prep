<?php

namespace App\Services\Alerts;

use App\Enums\AdminAlertKind;

/**
 * One admin alert for an external channel (Discord, M5-T19). Carries only
 * operational facts (amounts, levels) — never a family or child; the only pet
 * reference is the internal pet id of `game_loop_pet_error` (David 2026-11-04).
 */
final readonly class AdminAlert
{
    /**
     * @param  array<string, string>  $fields  label => value (shown as embed fields)
     */
    public function __construct(
        public AdminAlertKind $kind,
        public string $title,
        public string $body,
        public array $fields = [],
    ) {}

    public function url(): string
    {
        return url($this->kind->adminPath());
    }
}
