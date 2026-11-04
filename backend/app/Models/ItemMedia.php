<?php

namespace App\Models;

use App\Enums\LifeStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * AI content of a »Želje« item (M6-W03, SHOP_SPEC §5, David 2026-11-03) — written only by
 * Media\ItemMediaService. Its own table, never a pet_media row (the pet media pipeline never
 * sees it).
 *
 *   scope catalogue — the product picture of one design (kind `picture`, the object alone);
 *   scope look      — a shared look's content per (look, design, life stage): kind `image`
 *                     (the animal with the item — Nano Banana Pro Edit of the look's reference
 *                     image of that stage) and kind `clip` (Kling, start frame = that image);
 *                     made once, every pet of the look shows it;
 *   scope pet       — the same for a pet with its own media (unique DNA), per (pet, design,
 *                     kind), regenerated in place when the pet's reference image changes.
 *
 * `design` = the design key (config/shop_designs.php) or, for treats, `dog_treats` /
 * `cat_treats`. Status like pet_media: pending → running (submitted; `request_id`) → ready
 * (`storage_path`) | failed (`error_reason`, `error`). The stored file stays servable while a
 * newer generation runs. Self-healing: `auto_retries` / `prompt_variant` of the current failure
 * chain; `exhausted_at` = given up (the app then shows the item without this content).
 *
 * @property int $id
 * @property string $scope
 * @property int|null $pet_id
 * @property int|null $pet_look_id
 * @property string $item_key
 * @property string $design
 * @property string $kind
 * @property string|null $life_stage
 * @property string|null $source_path
 * @property string|null $target_path
 * @property string $status
 * @property int $generation
 * @property string|null $profile
 * @property string|null $request_id
 * @property string|null $status_url
 * @property string|null $response_url
 * @property string|null $source_url
 * @property string|null $storage_path
 * @property int|null $bytes
 * @property string|null $mime
 * @property float|null $duration_seconds
 * @property float $cost_usd
 * @property string|null $error_reason
 * @property string|null $error
 * @property int $attempts
 * @property int $auto_retries
 * @property int $prompt_variant
 * @property int $download_attempts
 * @property Carbon|null $exhausted_at
 * @property Carbon|null $alerted_at
 * @property Carbon|null $completed_at
 * @property int|null $seed_override
 * @property int $quality_retries
 * @property string|null $candidate_path
 * @property int|null $candidate_bytes
 * @property string|null $candidate_mime
 * @property string|null $quality
 * @property string|null $quality_reason
 * @property Carbon|null $updated_at
 */
class ItemMedia extends Model
{
    public const SCOPE_CATALOGUE = 'catalogue';

    public const SCOPE_LOOK = 'look';

    public const SCOPE_PET = 'pet';

    /** The catalogue picture of a design (no animal). */
    public const KIND_PICTURE = 'picture';

    /** The key image: the animal with the item. */
    public const KIND_IMAGE = 'image';

    /** The clip made from the key image (loop for bed / toys, played once for bowls / treats). */
    public const KIND_CLIP = 'clip';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $table = 'item_media';

    /** @var list<string> */
    protected $fillable = [
        'scope', 'pet_id', 'pet_look_id', 'item_key', 'design', 'kind', 'life_stage', 'source_path', 'target_path',
        'status', 'generation', 'profile', 'request_id', 'status_url', 'response_url', 'source_url',
        'storage_path', 'bytes', 'mime', 'duration_seconds', 'cost_usd', 'error_reason', 'error',
        'attempts', 'auto_retries', 'prompt_variant', 'download_attempts', 'exhausted_at', 'alerted_at', 'completed_at',
        // David 2026-11-04 (StillQualityService): seed of a regeneration, check retries, the file waiting for its check.
        'seed_override', 'quality_retries', 'candidate_path', 'candidate_bytes', 'candidate_mime', 'quality', 'quality_reason',
    ];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'bytes' => 'integer',
            'duration_seconds' => 'float',
            'cost_usd' => 'float',
            'attempts' => 'integer',
            'auto_retries' => 'integer',
            'prompt_variant' => 'integer',
            'download_attempts' => 'integer',
            'seed_override' => 'integer',
            'quality_retries' => 'integer',
            'candidate_bytes' => 'integer',
            'exhausted_at' => 'datetime',
            'alerted_at' => 'datetime',
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

    public function isPicture(): bool
    {
        return $this->kind === self::KIND_PICTURE;
    }

    /** A still (catalogue picture or key image): image mimes, `images[0].url` on the webhook. */
    public function isStill(): bool
    {
        return $this->kind !== self::KIND_CLIP;
    }

    public function isClip(): bool
    {
        return $this->kind === self::KIND_CLIP;
    }

    /** A stored file exists (possibly of an older generation while a new one runs). */
    public function isServable(): bool
    {
        return filled($this->storage_path);
    }

    /** The current generation is stored. */
    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY && $this->isServable();
    }

    public function isInFlight(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }

    /** The self-healing gave this chain up — the app shows the item without this content. */
    public function isExhausted(): bool
    {
        return $this->status === self::STATUS_FAILED && $this->exhausted_at !== null;
    }

    /** David 2026-11-04: the image seed of this row's request (base seed until a regeneration wrote a fresh one). */
    public function seedFor(int $base): int
    {
        return $this->seed_override !== null ? (int) $this->seed_override : $base;
    }

    /** The candidate failed its check for good and waits for »Approve anyway« (never shown). */
    public function needsReview(): bool
    {
        return $this->status === self::STATUS_FAILED && $this->error_reason === 'quality_failed' && filled($this->candidate_path);
    }

    /** @return HasMany<ItemMediaQualityCheck, $this> */
    public function qualityChecks(): HasMany
    {
        return $this->hasMany(ItemMediaQualityCheck::class);
    }

    public function stage(): ?LifeStage
    {
        return $this->life_stage !== null ? LifeStage::tryFrom($this->life_stage) : null;
    }

    public function label(): string
    {
        return match ($this->kind) {
            self::KIND_PICTURE => "{$this->design} catalogue picture",
            self::KIND_IMAGE => "{$this->design} key image",
            default => "{$this->design} clip",
        };
    }
}
