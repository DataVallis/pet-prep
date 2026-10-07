<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A billing rule refused the request (M3-11). `reason` is a stable
 * machine-readable code returned to the app: `no_credit` (409),
 * `free_plan` / `already_paid` / `pet_not_active` (422).
 */
class ChallengeException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
