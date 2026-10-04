<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An AI Lab run (admin only, M4-02): N samples × M model profiles for an image
 * run, or one lab image × M video profiles for a video run. No child data.
 */
class MediaLabRun extends Model
{
    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'kind',
        'breed',
        'fixed_traits',
        'samples',
        'profiles',
        'pet_state',
        'source_result_id',
        'estimated_cost_usd',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fixed_traits' => 'array',
            'profiles' => 'array',
            'estimated_cost_usd' => 'float',
        ];
    }

    public function results(): HasMany
    {
        return $this->hasMany(MediaLabResult::class)->orderBy('sample_index')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceResult(): BelongsTo
    {
        return $this->belongsTo(MediaLabResult::class, 'source_result_id');
    }
}
