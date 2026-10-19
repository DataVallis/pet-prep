<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One distinct production error (System health, David 2026-10-19). Written
 * only by App\Services\SystemHealth\ErrorRecorder (upsert by fingerprint);
 * resolved / pruned through SystemHealthService.
 *
 * @property int $id
 * @property string $fingerprint
 * @property string $level
 * @property string|null $exception_class
 * @property string $message
 * @property string|null $file
 * @property int|null $line
 * @property list<string>|null $trace
 * @property string $source
 * @property array<string, mixed>|null $context
 * @property int $occurrences
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 */
class SystemError extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trace' => 'array',
            'context' => 'array',
            'occurrences' => 'integer',
            'line' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @param  Builder<SystemError>  $query
     * @return Builder<SystemError>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /** One-line summary of where it happened (route / command / job). */
    public function contextSummary(): string
    {
        $c = $this->context ?? [];

        return match ($this->source) {
            'http' => trim(($c['method'] ?? '').' '.($c['route'] ?? '').(isset($c['status']) ? ' → '.$c['status'] : '')),
            'command' => (string) ($c['command'] ?? ''),
            'job' => (string) ($c['job'] ?? ''),
            default => (string) ($c['command'] ?? $c['job'] ?? ''),
        };
    }
}
