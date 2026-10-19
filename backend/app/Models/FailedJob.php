<?php

namespace App\Models;

use App\Services\SystemHealth\ErrorText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Read model of Laravel's `failed_jobs` table for /admin/system-health.
 * Retry / forget go through SystemHealthService (queue.failer), never by
 * writing this model.
 *
 * @property int $id
 * @property string $uuid
 * @property string $connection
 * @property string $queue
 * @property string $payload
 * @property string $exception
 * @property Carbon $failed_at
 */
class FailedJob extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['failed_at' => 'datetime'];
    }

    public function displayName(): string
    {
        $payload = json_decode($this->payload, true);

        return is_array($payload) ? (string) ($payload['displayName'] ?? '?') : '?';
    }

    /** First line of the stored exception (the stored text is never shown in full in lists). */
    public function exceptionHeadline(): string
    {
        return ErrorText::firstLine($this->exception);
    }
}
