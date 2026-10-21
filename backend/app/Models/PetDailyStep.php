<?php

namespace App\Models;

use App\Enums\StepUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One child's own step count for one family-local day on one pet (M2-01).
 * The pet's daily_step_count (→ energy, the daily walk) is the sum over its
 * caretakers for that day.
 *
 * @property int $pet_id
 * @property int $user_id
 * @property string $local_date
 * @property int $steps
 * @property Carbon|null $last_sync_at
 * @property StepUnit $unit M5-T12: what the child's phone counted (steps | wheelchair pushes, 1 : 1)
 * @property int $manual_steps M5-T12: the part of `steps` parents logged as walk minutes
 * @property int $manual_minutes M5-T12: those minutes (parent report)
 */
class PetDailyStep extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['pet_id', 'user_id', 'local_date', 'steps', 'last_sync_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'steps' => 'integer',
            'manual_steps' => 'integer',
            'manual_minutes' => 'integer',
            'last_sync_at' => 'datetime',
            'unit' => StepUnit::class,
        ];
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
