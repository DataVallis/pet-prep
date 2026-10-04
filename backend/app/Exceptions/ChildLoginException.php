<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * PIN-only child login refused (M2-02). `reason` is a stable machine code:
 *  - invalid_pin (422): wrong, expired or already used — deliberately the
 *    same answer for all three (no enumeration);
 *  - pin_not_usable (422): the PIN matched, but the family changed since it
 *    was issued (e.g. the shared pet ended); the PIN is now revoked;
 *  - too_many_attempts (429): per-IP or global failed-attempt lockout.
 */
class ChildLoginException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function invalidPin(): self
    {
        return new self('invalid_pin', 'This code is not valid. Ask your parent for a new code.');
    }
}
