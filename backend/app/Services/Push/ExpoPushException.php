<?php

namespace App\Services\Push;

use RuntimeException;
use Throwable;

/**
 * Expo Push Service call failed (M3-02). `retryable` = worth another try
 * (connection, 429, 5xx); otherwise the job fails at once.
 */
class ExpoPushException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
