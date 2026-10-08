<?php

namespace App\Models;

use App\Enums\CareSessionKind;
use App\Enums\CareSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One server-driven cat care session (M5-R06_PLAN T6, M5-R06-04):
 * `wand_play` today, `grooming` / `litter_change` in M5-R06-05. The server
 * generates the schedule at start and judges the finish (WandPlayService);
 * the client is only trusted for what it saw (the feather moves). At most
 * one `active` session per (pet, kind) — partial unique index.
 *
 * @property string $public_id UUID the app uses (never the row id)
 * @property int $pet_id
 * @property int|null $user_id The child (null after the profile was deleted)
 * @property CareSessionKind $kind
 * @property string $local_date Family-local date of the start (the day it counts for)
 * @property Carbon $started_at
 * @property Carbon $ends_at When the game has run its course
 * @property Carbon $expires_at Last moment a finish is accepted (TTL)
 * @property int $duration_ms
 * @property array<string, mixed> $schedule
 * @property CareSessionStatus $status
 * @property Carbon|null $finished_at Set for completed / failed
 * @property array<string, mixed>|null $result The server's verdict
 */
class PetCareSession extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id', 'pet_id', 'user_id', 'kind', 'local_date', 'started_at', 'ends_at', 'expires_at',
        'duration_ms', 'schedule', 'status', 'finished_at', 'result',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CareSessionKind::class,
            'status' => CareSessionStatus::class,
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'expires_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'schedule' => 'array',
            'result' => 'array',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The family-local date as Y-m-d (the column may come back with a time part). */
    public function localDateString(): string
    {
        return substr((string) $this->getRawOriginal('local_date'), 0, 10);
    }
}
