<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use RuntimeException;

/**
 * A fal.ai call that was refused before sending (budget, disabled) or that
 * failed. `reason` is stored on the ledger / lab result / pet.
 */
class AiCallException extends RuntimeException
{
    public function __construct(public readonly AiCallFailure $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason->label());
    }

    public function retryable(): bool
    {
        return $this->reason->retryable();
    }
}
