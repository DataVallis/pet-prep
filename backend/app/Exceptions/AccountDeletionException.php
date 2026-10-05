<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An account deletion / data export was refused (M2-08). `reason` is a stable
 * machine-readable code returned to the app: `invalid_password` (422),
 * `superadmin_protected` (403), `child_not_found` (404), `not_a_parent` (403),
 * `export_too_large` (413).
 */
class AccountDeletionException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
