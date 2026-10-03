<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The result of one family-local day's walk (daily walk rule, PRODUCT_SPEC §5/§7).
 * Written once per pet and day by DailyWalkService when the day is closed at
 * the local midnight. Raw material for the parent dashboard (M2-05).
 *
 * @property string $local_date Y-m-d in the family timezone.
 * @property int $steps Steps counted for that day.
 * @property int $goal Breed daily_steps_required on that day.
 * @property bool $achieved steps >= goal.
 * @property bool $birth_day The pet was born that day (never causes illness).
 * @property Carbon|null $illness_due_at Planned illness start (no walk at all).
 * @property Carbon|null $illness_started_at When that illness actually started.
 */
class PetDailyWalk extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'pet_id',
        'local_date',
        'steps',
        'goal',
        'achieved',
        'birth_day',
        'illness_due_at',
        'illness_started_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'local_date' => 'date:Y-m-d',
            'steps' => 'integer',
            'goal' => 'integer',
            'achieved' => 'boolean',
            'birth_day' => 'boolean',
            'illness_due_at' => 'datetime',
            'illness_started_at' => 'datetime',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }
}
