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
 * Shared looks (M4-10): a row with `pet_look_id` (and no pet) is the media of
 * a look of the free-pet pool, one per (kind, state, life_stage); it runs
 * through the same pipeline. A pool pet's own slot points at the look row it
 * shows (`look_media_id`) and carries a copy of its `storage_path` — the file
 * exists once, under `looks/{look_id}/`, and is never deleted with a pet.
 *
 * @property int $id
 * @property int|null $pet_id
 * @property int|null $pet_look_id
 * @property int|null $look_media_id
 * @property string|null $life_stage
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
        // M4-10: a look row (no pet) / the look row a pool pet's slot shows.
        'pet_look_id',
        'look_media_id',
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
        // M5-R01: life stage the current generation depicts (image).
        'life_stage',
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

    public function look(): BelongsTo
    {
        return $this->belongsTo(PetLook::class, 'pet_look_id');
    }

    /** The look row this pet slot shows (M4-10; null for a pet with unique media). */
    public function lookMedia(): BelongsTo
    {
        return $this->belongsTo(self::class, 'look_media_id');
    }

    /** A media row of a shared look (M4-10), not of a pet. */
    public function isLookMedia(): bool
    {
        return $this->pet_look_id !== null;
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
