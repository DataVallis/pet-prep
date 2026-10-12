<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A certificate request was refused (M2-01d). `reason`: `not_eligible` (409 —
 * the challenge ended with a report, no certificate to generate).
 */
class CertificateException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'reason' => $this->reason], $this->status);
    }
}
