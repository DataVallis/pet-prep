<?php

namespace App\Models;

use App\Enums\PetStateEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI media slot of a pet (M4-03 / M4-05): the reference image
 * (kind image, state null) or the video of one pet state.
 *
 * Lifecycle of the CURRENT generation:
 *   pending  — queued, nothing sent to fal yet
 *   running  — sent to fal (videos: request_id set, waiting for the webhook)
 *              or result URL received and the download is queued
 *   ready    — stored on the pet-media disk (storage_path)
 *   failed   — error_reason (AiCallFailure) / error
 *
 * `storage_path` is the last stored file and stays servable while a newer
 * generation is pending (regenerate keeps the old video until the new one is
 * stored). The apps only ever get our signed URLs, never `source_url`.
 *
 * @property int $id
 * @property int $pet_id
 * @property string $kind
 * @property string|null $state
 * @property string|null $profile
 * @property string $status
 * @property int $generation
 * @property int|null $source_generation
 * @property int $attempts
 * @property string|null $request_id
 * @property string|null $source_url
 * @property string|null $storage_path
 * @property int|null $bytes
 * @property string|null $mime
 * @property float|null $duration_seconds
 * @property float $cost_usd
 * @property string|null $error_reason
 * @property string|null $error
 */
class PetMedia extends Model
{
    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $table = 'pet_media';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pet_id',
        'kind',
        'state',
        'profile',
        'status',
        'generation',
        'source_generation',
        'attempts',
        'request_id',
        'source_url',
        'storage_path',
        'bytes',
        'mime',
        'duration_seconds',
        'cost_usd',
        'error_reason',
        'error',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'source_generation' => 'integer',
            'attempts' => 'integer',
            'bytes' => 'integer',
            'duration_seconds' => 'float',
            'cost_usd' => 'float',
            'completed_at' => 'datetime',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function isImage(): bool
    {
        return $this->kind === self::KIND_IMAGE;
    }

    public function isVideo(): bool
    {
        return $this->kind === self::KIND_VIDEO;
    }

    public function petState(): ?PetStateEnum
    {
        return $this->state !== null ? PetStateEnum::tryFrom($this->state) : null;
    }

    /** A stored file exists (possibly of an older generation while a new one is pending). */
    public function isServable(): bool
    {
        return filled($this->storage_path);
    }

    /** Generation is under way (nothing to do for a sweep / backfill). */
    public function isInFlight(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }

    public function label(): string
    {
        return $this->isImage() ? 'reference image' : "{$this->state} video";
    }

    /**
     * @param  Builder<PetMedia>  $query
     * @return Builder<PetMedia>
     */
    public function scopeImages(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_IMAGE);
    }

    /**
     * @param  Builder<PetMedia>  $query
     * @return Builder<PetMedia>
     */
    public function scopeVideos(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_VIDEO);
    }
}
