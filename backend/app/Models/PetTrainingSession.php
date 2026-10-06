<?php

namespace App\Models;

use App\Enums\TrainingCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One training mini-game session (M5-R03, TrainingService). The server
 * generates the schedule (cues, obey instants, praise window) at start and
 * scores the child's tap offsets against it at finish — the client is only
 * trusted for the tap timings. At most one `active` session per pet
 * (partial unique index); an active session past `expires_at` is expired by
 * the next start / finish of that pet.
 *
 * @property string $public_id UUID the app uses (never the row id)
 * @property int $pet_id
 * @property int|null $user_id The child who trains (null after the profile was deleted)
 * @property TrainingCommand $command
 * @property string $local_date Family-local date of the start (daily budget)
 * @property Carbon $started_at
 * @property Carbon $ends_at When the schedule has run its course
 * @property Carbon $expires_at Last moment a finish is accepted (TTL)
 * @property int $duration_ms
 * @property array{trials: list<array{index: int, cue_at_ms: int, obeys: bool, obey_at_ms: int|null, window_end_ms: int|null}>, praise_window_ms: int, min_reaction_ms?: int} $schedule
 * @property string $status active | completed | expired | interrupted
 * @property Carbon|null $finished_at
 * @property list<int>|null $taps
 * @property array<string, mixed>|null $result
 * @property float|null $progress_before
 * @property float|null $progress_gain
 */
class PetTrainingSession extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    /** A lock (hard stop, vet, game over) began during the session: not counted, time refunded (PR #53 m2). */
    public const STATUS_INTERRUPTED = 'interrupted';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id', 'pet_id', 'user_id', 'command', 'local_date', 'started_at', 'ends_at', 'expires_at',
        'duration_ms', 'schedule', 'status', 'finished_at', 'taps', 'result', 'progress_before', 'progress_gain',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'command' => TrainingCommand::class,
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'expires_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'schedule' => 'array',
            'taps' => 'array',
            'result' => 'array',
            'progress_before' => 'float',
            'progress_gain' => 'float',
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

    /** Active and not past its TTL at $now. */
    public function isLiveAt(\DateTimeInterface $now): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->expires_at->greaterThan($now);
    }
}
