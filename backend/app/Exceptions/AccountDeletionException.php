<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An account deletion / data export was refused (M2-08). `reason` is a stable
 * machine-readable code returned to the app: `invalid_password` (422),
 * `superadmin_protected` (403), `child_not_found` (404), `not_a_parent` (403),
 * `export_too_large` (413), `too_many_attempts` (429, wrong passwords), `conflict` (409),
 * `paid_challenge_ack_required` (422 + `pets: [{pet_id, breed_type}]`, M3-11 P5).
 */
class AccountDeletionException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
        /** Seconds until a throttled request may be retried (429 only). */
        public readonly ?int $retryAfter = null,
        /** Extra body fields (M3-11 P5: `pets` of `paid_challenge_ack_required`). */
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /**
     * The JSON error body `{message, reason, …extra}` (+ `Retry-After` on 429).
     */
    public function toResponse(): JsonResponse
    {
        $headers = $this->retryAfter !== null ? ['Retry-After' => (string) max(1, $this->retryAfter)] : [];

        return response()->json(['message' => $this->getMessage(), 'reason' => $this->reason] + $this->extra, $this->status, $headers);
    }
}
