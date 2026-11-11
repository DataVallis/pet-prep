<?php

namespace App\Services\Auth;

use Carbon\CarbonImmutable;

/** A website sign-in token (M6-S01b): plain text + its fixed expiry. */
final readonly class WebSession
{
    public function __construct(
        public string $token,
        public CarbonImmutable $expiresAt,
    ) {}
}
