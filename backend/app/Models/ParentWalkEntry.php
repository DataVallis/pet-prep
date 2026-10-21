<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * M5-T12: a walk a parent logged for a child in adapted mode (minutes → steps,
 * PetActivityService::logParentWalk). Unique per (child, entry_id).
 *
 * @property int $id
 * @property int $pet_id
 * @property int $child_user_id
 * @property int|null $parent_user_id
 * @property Carbon $local_date
 * @property int $minutes
 * @property int $steps
 * @property string $entry_id
 * @property Carbon|null $created_at
 */
class ParentWalkEntry extends Model
{
    /** @var list<string> */
    protected $fillable = ['pet_id', 'child_user_id', 'parent_user_id', 'local_date', 'minutes', 'steps', 'entry_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'local_date' => 'date:Y-m-d',
            'minutes' => 'integer',
            'steps' => 'integer',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    /**
     * @return array{id: int, entry_id: string, pet_id: int, child_id: int, local_date: string, minutes: int, steps: int, created_at: string|null}
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'entry_id' => $this->entry_id,
            'pet_id' => $this->pet_id,
            'child_id' => $this->child_user_id,
            'local_date' => $this->local_date->toDateString(),
            'minutes' => $this->minutes,
            'steps' => $this->steps,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
