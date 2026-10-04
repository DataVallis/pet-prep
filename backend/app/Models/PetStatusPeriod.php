<?php

namespace App\Models;

use App\Enums\PetStatusPeriodKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A period in which the pet could not be cared for (M2-06): hard stop,
 * illness, inactive (game over). Routines that overlap one are not expected
 * unless they were done anyway. Written by PetStatusPeriodService from the
 * Pet model hooks; `ended_at` null = still going on.
 *
 * @property int $pet_id
 * @property PetStatusPeriodKind $kind
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 */
class PetStatusPeriod extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['pet_id', 'kind', 'started_at', 'ended_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PetStatusPeriodKind::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }
}
