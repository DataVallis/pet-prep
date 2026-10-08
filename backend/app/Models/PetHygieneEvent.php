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
 *
 * M5-R06-05 (cats): `litter_use` rows are not messes — `cleaned_at` = the
 * tray was scooped, `due_at` = the scoop deadline (fixed when the use
 * happens), `escalated_at` = the tick handled it after the deadline (wrote
 * the `litter_accident`, or skipped it after a freeze / outage). See
 * LitterService.
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
        // M5-R06-05 litter use: scoop deadline; when the tick handled it after the deadline.
        'due_at',
        'escalated_at',
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
            'due_at' => 'datetime',
            'escalated_at' => 'datetime',
            'status' => HygieneEventStatus::class,
            'kind' => HygieneEventKind::class,
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }
}
