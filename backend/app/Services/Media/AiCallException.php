<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use RuntimeException;

/**
 * A fal.ai call that was refused before sending (budget, disabled) or that
 * failed. `reason` is stored on the ledger / lab result / pet.
 *
 * `chargedUsd` > 0 means the request reached fal and the estimated cost was
 * kept (e.g. a timeout after sending — fal may still run and bill it);
 * `outcomeUnknown` marks exactly that case.
 */
class AiCallException extends RuntimeException
{
    public function __construct(
        public readonly AiCallFailure $reason,
        string $message = '',
        public readonly float $chargedUsd = 0.0,
        public readonly bool $outcomeUnknown = false,
    ) {
        parent::__construct($message !== '' ? $message : $reason->label());
    }

    public function retryable(): bool
    {
        return $this->reason->retryable();
    }
}
