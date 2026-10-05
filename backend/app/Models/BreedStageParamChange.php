<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit row of one change to a sourced life-stage value (M5-R01): who
 * (admin user), what (created / updated / deleted), old and new values.
 * Append-only. `actor` labels a non-user writer (user_id null), e.g. the
 * M5-R01b data migration "system: David decision 2026-10-05".
 */
class BreedStageParamChange extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'breed_stage_param_id', 'breed_slug', 'stage', 'age_from_months', 'key',
        'action', 'user_id', 'actor', 'old', 'new',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old' => 'array',
            'new' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function param(): BelongsTo
    {
        return $this->belongsTo(BreedStageParam::class, 'breed_stage_param_id');
    }
}
