<?php

namespace App\Models;

use App\Enums\QuietHoursChangeScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One parent save that changed a quiet-hours schedule (David 2026-11-24).
 * Append-only; written only by App\Services\QuietHoursChangeLog.
 *
 * @property int $id
 * @property int $family_id
 * @property int|null $pet_id
 * @property int|null $user_id
 * @property QuietHoursChangeScope $scope
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $user_agent
 * @property string|null $client_features
 * @property Carbon|null $created_at
 */
class QuietHoursChange extends Model
{
    /** Append-only: created_at only. */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'family_id',
        'pet_id',
        'user_id',
        'scope',
        'before',
        'after',
        'user_agent',
        'client_features',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => QuietHoursChangeScope::class,
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
