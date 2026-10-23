<?php

namespace App\Models;

use App\Enums\PetPhaseClip;
use App\Enums\PetPose;
use App\Enums\PetStateEnum;
use App\Enums\PetTransition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * M5-T09 / M5-T10 kinds: `pose` (a pose image a school / game phase clip
 * starts or ends in — an edit of the reference image like a key image; state =
 * PetPose) and `phase` (a 3 s school / game phase clip played once; state =
 * PetPhaseClip). Key images and poses are "pose stills" (isPoseStill()),
 * transitions and phase clips "pose clips" (isPoseClip(): start / end frame).
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
 * @property string|null $stored_recipe
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
 * @property string|null $candidate_path
 * @property int|null $candidate_bytes
 * @property string|null $candidate_mime
 * @property string|null $quality
 * @property string|null $quality_reason
 * @property int|null $quality_retry_generation
 * @property float|null $stored_duration_seconds
 */
class PetMedia extends Model
{
    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    /** M5-T08: the KEY IMAGE of a state (an edit of the reference image; state = PetStateEnum, never idle). */
    public const KIND_KEYFRAME = 'keyframe';

    /** M5-T08: a TRANSITION clip between two poses (state = PetTransition value). */
    public const KIND_TRANSITION = 'transition';

    /** M5-T09 / T10: a POSE image of a phase clip (state = PetPose value). */
    public const KIND_POSE = 'pose';

    /** M5-T09 / T10: a school / game PHASE clip, played once (state = PetPhaseClip value). */
    public const KIND_PHASE = 'phase';

    /** M5-T08 `recipe`: a state video made from its key image only (no end frame). */
    public const RECIPE_POSE = 'pose';

    /**
     * M5-T08 `recipe`: a loop made with start AND end frame = the state's key image.
     * Kling 3.0 Pro froze those clips (production 2026-10-23) — rows with this recipe
     * are what `media:regenerate-frozen` remakes; new loops are RECIPE_START_ONLY.
     */
    public const RECIPE_LOOP = 'loop';

    /**
     * Fix 2026-10-23: a clip whose start and end picture are the same (a state loop, a
     * same-pose phase clip — PetPhaseClip::sharesFrames) made from the START frame only
     * (no end_image_url) with a clearly-visible-motion prompt (FalAiService::endFrameUrl).
     */
    public const RECIPE_START_ONLY = 'start_only';

    /** @var list<string> recipes of a state loop (made from the state's key image as a loop) */
    public const LOOP_RECIPES = [self::RECIPE_LOOP, self::RECIPE_START_ONLY];

    /*
     * Quality check (David 2026-10-23) — `quality` = verdict of the STORED file (like
     * stored_recipe: written only when a file is stored / linked / checked; null = stored
     * before the check existed or with the check switched off):
     *   passed            technical + AI vision check passed
     *   passed_technical  technical check passed, NOT AI-checked (budget out / vision profile off / vision error)
     *   unchecked         the check could not run (ffmpeg missing) — served
     *   flagged           a stored clip failed `media:quality-check` in enforce mode — NOT shown (isShown)
     *   shadow_failed     failed in shadow mode (QA #363 M1) — still shown, the would-be verdict
     *   approved          a superadmin approved it (»Approve anyway«)
     * »Needs review« (needsReview()): the candidate failed twice — slot `failed` with
     * error_reason `quality_failed`, the candidate kept in `candidate_path` for »Approve anyway«.
     */
    public const QUALITY_PASSED = 'passed';

    public const QUALITY_PASSED_TECHNICAL = 'passed_technical';

    public const QUALITY_UNCHECKED = 'unchecked';

    public const QUALITY_FLAGGED = 'flagged';

    /** QA #363 M1: failed the check in SHADOW mode — still shown (the would-be verdict, for calibration). */
    public const QUALITY_SHADOW_FAILED = 'shadow_failed';

    public const QUALITY_APPROVED = 'approved';

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
        // Fix 2026-10-23 (QA #361 M1): how the STORED file was made — written only when a file is stored / linked.
        'stored_recipe',
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
        // Quality check (David 2026-10-23): the downloaded clip waiting for its check.
        'candidate_path',
        'candidate_bytes',
        'candidate_mime',
        'quality',
        'quality_reason',
        'quality_retry_generation',
        'stored_duration_seconds',
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
            'stored_duration_seconds' => 'float',
            'candidate_bytes' => 'integer',
            'quality_retry_generation' => 'integer',
            'cost_usd' => 'float',
            'completed_at' => 'datetime',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    /** @return HasMany<PetMediaQualityCheck, $this> */
    public function qualityChecks(): HasMany
    {
        return $this->hasMany(PetMediaQualityCheck::class);
    }

    /** Quality check: the candidate failed twice and waits for a superadmin (never shown to children). */
    public function needsReview(): bool
    {
        return $this->error_reason === 'quality_failed' && filled($this->candidate_path);
    }

    /** A stored clip with a failed verdict (enforce: flagged / hidden; shadow: would fail, shown). */
    public function isFlaggedQuality(): bool
    {
        return in_array($this->quality, [self::QUALITY_FLAGGED, self::QUALITY_SHADOW_FAILED], true);
    }

    /**
     * Quality check: the admin »Needs review« list — a twice-failed candidate, a stored clip
     * flagged by media:quality-check, or (QA #363 M1) a clip that failed in shadow mode.
     *
     * @param  Builder<PetMedia>  $query
     * @return Builder<PetMedia>
     */
    public function scopeNeedingReview(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('error_reason', 'quality_failed')->whereNotNull('candidate_path'))
            ->orWhereIn('quality', [self::QUALITY_FLAGGED, self::QUALITY_SHADOW_FAILED]));
    }

    /**
     * Withheld from children by the check (enforce mode): twice-failed candidates and flagged clips.
     *
     * @param  Builder<PetMedia>  $query
     * @return Builder<PetMedia>
     */
    public function scopeWithheldByQuality(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('error_reason', 'quality_failed')->whereNotNull('candidate_path'))
            ->orWhere('quality', self::QUALITY_FLAGGED));
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

    public function isPose(): bool
    {
        return $this->kind === self::KIND_POSE;
    }

    public function isPhase(): bool
    {
        return $this->kind === self::KIND_PHASE;
    }

    /** A picture (reference image, key image or pose image): sync image call, image mimes. */
    public function isStill(): bool
    {
        return $this->isImage() || $this->isKeyframe() || $this->isPose();
    }

    /** An EDIT of the reference image (key image or pose image) — GeneratePetKeyframe. */
    public function isPoseStill(): bool
    {
        return $this->isKeyframe() || $this->isPose();
    }

    /** A clip (state video, transition, phase clip): fal queue + webhook, video mimes. */
    public function isClip(): bool
    {
        return $this->isVideo() || $this->isTransition() || $this->isPhase();
    }

    /** A clip from a start frame to an end frame (transition or phase clip). */
    public function isPoseClip(): bool
    {
        return $this->isTransition() || $this->isPhase();
    }

    /** The STORED state video is a loop from its key image (old start = end frame, or start-only since 2026-10-23). */
    public function isStateLoop(): bool
    {
        return $this->isVideo() && in_array($this->stored_recipe, self::LOOP_RECIPES, true);
    }

    /**
     * The STORED file was made the frozen way (fix 2026-10-23): a loop with the same image
     * as start and end frame (stored_recipe `loop`), or a same-pose phase clip made with an
     * end frame (stored_recipe null; `$endFrameProfile` = its profile took an end frame —
     * QA #361 m3, never a paid remake of a clip that could not freeze). Any status: a remake
     * that failed (webhook error, sweep timeout) still shows the frozen file (QA #361 M1).
     */
    public function isFrozenSameFrameClip(bool $endFrameProfile = true): bool
    {
        if (! $this->isServable()) {
            return false;
        }

        if ($this->isVideo()) {
            return $this->stored_recipe === self::RECIPE_LOOP;
        }

        return $this->isPhase() && $this->stored_recipe === null && $endFrameProfile && ($this->phaseClip()?->sharesFrames() ?? false);
    }

    /** The state of a video / key image slot (null for every other kind). */
    public function petState(): ?PetStateEnum
    {
        return $this->state !== null && ($this->isVideo() || $this->isKeyframe()) ? PetStateEnum::tryFrom($this->state) : null;
    }

    public function transition(): ?PetTransition
    {
        return $this->isTransition() && $this->state !== null ? PetTransition::tryFrom($this->state) : null;
    }

    public function pose(): ?PetPose
    {
        return $this->isPose() && $this->state !== null ? PetPose::tryFrom($this->state) : null;
    }

    public function phaseClip(): ?PetPhaseClip
    {
        return $this->isPhase() && $this->state !== null ? PetPhaseClip::tryFrom($this->state) : null;
    }

    /**
     * The start and end frames of a pose clip: PetStateEnum (a state pose, idle =
     * the reference image) or PetPose (null = the reference image). Null for other kinds.
     *
     * @return array{0: PetStateEnum|PetPose|null, 1: PetStateEnum|PetPose|null}|null
     */
    public function frames(): ?array
    {
        if (($transition = $this->transition()) !== null) {
            return [$transition->fromState(), $transition->toState()];
        }

        if (($clip = $this->phaseClip()) !== null) {
            return [$clip->startPose(), $clip->endPose()];
        }

        return null;
    }

    /** A stored file exists (possibly of an older generation while a new one is pending). */
    public function isServable(): bool
    {
        return filled($this->storage_path);
    }

    /**
     * The stored file may be shown to the apps: servable and not flagged by the quality check
     * (David 2026-10-23 — a flagged clip is treated like a failed slot: the app's fallback).
     */
    public function isShown(): bool
    {
        return $this->isServable() && $this->quality !== self::QUALITY_FLAGGED;
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
            self::KIND_POSE => "{$this->state} pose image",
            self::KIND_PHASE => "{$this->state} phase clip",
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
     * M5-T08: state videos, transitions and (M5-T09 / T10) phase clips (everything that goes through the fal queue + webhook).
     *
     * @param  Builder<PetMedia>  $query
     * @return Builder<PetMedia>
     */
    public function scopeClips(Builder $query): Builder
    {
        return $query->whereIn('kind', [self::KIND_VIDEO, self::KIND_TRANSITION, self::KIND_PHASE]);
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
