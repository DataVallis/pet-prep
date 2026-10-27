<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A wishes rule refused the request (M6-W01 / W02, docs/engineering/WISHES_API.md).
 * `reason` is the stable machine-readable code returned to the app; `extra`
 * carries additional top-level keys (e.g. `retry_at`).
 */
class WishException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return array_merge(['message' => $this->getMessage(), 'reason' => $this->reason], $this->extra);
    }
}
