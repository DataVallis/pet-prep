<?php

namespace App\Models;

use App\Enums\HygieneEventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scheduled random hygiene event ("the dog made a mess", M1-05).
 * See HygieneEventService for scheduling and application rules.
 */
class PetHygieneEvent extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'pet_id',
        'local_date',
        'scheduled_at',
        'status',
        'resolved_at',
        'cleaned_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'local_date' => 'date:Y-m-d',
            'scheduled_at' => 'datetime',
            'resolved_at' => 'datetime',
            'cleaned_at' => 'datetime',
            'status' => HygieneEventStatus::class,
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }
}
