<?php

namespace App\Models;

use App\Enums\HygieneEventKind;
use App\Enums\HygieneEventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mess the dog made: a scheduled random hygiene event ("kakec", M1-05)
 * or a behaviour event (M5-R02 — puppy accident, chewing), see `kind`.
 * See HygieneEventService (scheduling, application, cleaning) and
 * BehaviourEventService (accidents, chewing). `cleaned_at` = resolved
 * (cleaned / tidied up by a child, or by the vet at the end of an illness).
 */
class PetHygieneEvent extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'pet_id',
        'kind',
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
            'kind' => HygieneEventKind::class,
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }
}
