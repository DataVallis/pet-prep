<?php

namespace App\Models;

use App\Enums\PetStateEnum;
use App\Enums\PetTransition;
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
 * M5-T08 kinds: `keyframe` (the key image of a state — an edit of the
 * reference image of the same stage, the start / end frame of that state's
 * loop) and `transition` (a clip from one pose to another, state = PetTransition).
 * isImage() / isVideo() keep meaning the reference image / a state video.
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
 * @property string|null $recipe
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

    /** M5-T08: the KEY IMAGE of a state (an edit of the reference image; state = PetStateEnum, never idle). */
    public const KIND_KEYFRAME = 'keyframe';

    /** M5-T08: a TRANSITION clip between two poses (state = PetTransition value). */
    public const KIND_TRANSITION = 'transition';

    /** M5-T08 `recipe`: a state video made from its key image only (no end frame). */
    public const RECIPE_POSE = 'pose';

    /** M5-T08 `recipe`: a seamless loop — start AND end frame = the state's key image. */
    public const RECIPE_LOOP = 'loop';

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
        // M5-T08: how a state video was made (null = from the reference image, before M5-T08).
        'recipe',
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

    public function isKeyframe(): bool
    {
        return $this->kind === self::KIND_KEYFRAME;
    }

    public function isTransition(): bool
    {
        return $this->kind === self::KIND_TRANSITION;
    }

    /** A picture (reference image or a key image): sync image call, image mimes. */
    public function isStill(): bool
    {
        return $this->isImage() || $this->isKeyframe();
    }

    /** A clip (state video or transition): fal queue + webhook, video mimes. */
    public function isClip(): bool
    {
        return $this->isVideo() || $this->isTransition();
    }

    /** The state of a video / key image slot (null for the reference image and transitions). */
    public function petState(): ?PetStateEnum
    {
        return $this->state !== null && ! $this->isTransition() ? PetStateEnum::tryFrom($this->state) : null;
    }

    public function transition(): ?PetTransition
    {
        return $this->isTransition() && $this->state !== null ? PetTransition::tryFrom($this->state) : null;
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
        return match ($this->kind) {
            self::KIND_IMAGE => 'reference image',
            self::KIND_KEYFRAME => "{$this->state} key image",
            self::KIND_TRANSITION => "{$this->state} transition",
            default => "{$this->state} video",
        };
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

    /**
     * M5-T08: state videos and transitions (everything that goes through the fal queue + webhook).
     *
     * @param  Builder<PetMedia>  $query
     * @return Builder<PetMedia>
     */
    public function scopeClips(Builder $query): Builder
    {
        return $query->whereIn('kind', [self::KIND_VIDEO, self::KIND_TRANSITION]);
    }

    /**
     * @param  Builder<PetMedia>  $query
     * @return Builder<PetMedia>
     */
    public function scopeKeyframes(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_KEYFRAME);
    }
}
