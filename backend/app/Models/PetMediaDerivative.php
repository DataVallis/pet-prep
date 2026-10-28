<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A clip DERIVED with ffmpeg from a stored school phase clip (school smooth playback,
 * David 2026-10-28, SCHOOL_SMOOTH_PLAYBACK.md §A): ping-pong loops of `attention` /
 * `success` and the reversed `performing` (= stand up, `release`). Zero AI cost — made
 * by Media\SchoolClipDerivativeService, never through fal or the AI ledger.
 *
 * `pet_media_id` = the row owning the source file (a look row or a pet's unique row);
 * the derived file is current while `source_path` equals the source's storage_path.
 *
 * @property int $id
 * @property int $pet_media_id
 * @property string $key
 * @property string $status
 * @property string $source_path
 * @property string|null $storage_path
 * @property int|null $bytes
 * @property float|null $duration_seconds
 * @property int $attempts
 * @property string|null $error
 */
class PetMediaDerivative extends Model
{
    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pet_media_id',
        'key',
        'status',
        'source_path',
        'storage_path',
        'bytes',
        'duration_seconds',
        'attempts',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'duration_seconds' => 'float',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<PetMedia, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(PetMedia::class, 'pet_media_id');
    }

    /** Ready and made from this source file. */
    public function isCurrentFor(?string $sourcePath): bool
    {
        return $this->status === self::STATUS_READY && filled($this->storage_path) && $this->source_path === $sourcePath;
    }
}
