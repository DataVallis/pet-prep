<?php

namespace App\Services\Media;

use RuntimeException;

/**
 * A fal result could not be stored (M4-05).
 *
 * `permanent` = retrying cannot help (untrusted host, too large, wrong
 * content type, 4xx); otherwise the queue retries (network error, 5xx).
 */
class MediaDownloadException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = true)
    {
        parent::__construct($message);
    }
}
