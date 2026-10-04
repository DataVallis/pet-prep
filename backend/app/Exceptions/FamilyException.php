<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A family rule refused the request (M2-01). `reason` is a stable
 * machine-readable code returned to the app.
 */
class FamilyException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
