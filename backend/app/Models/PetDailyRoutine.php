<?php

namespace App\Models;

use App\Enums\HygieneEventKind;
use App\Enums\RoutineStatus;
use App\Enums\RoutineType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One expected routine of a closed family-local day (M2-06), written by
 * RoutineLedgerService::closeDueDays(). Immutable once written: later
 * changes of quiet hours or feed windows do not rewrite history.
 *
 * @property int $pet_id
 * @property string $local_date
 * @property RoutineType $routine_type
 * @property int $slot
 * @property Carbon $opens_at
 * @property Carbon $due_at
 * @property RoutineStatus $status
 * @property Carbon|null $done_at
 * @property int|null $actor_user_id
 * @property int|null $steps Walk only: the pet's steps that day.
 * @property int|null $goal Walk only: the breed goal that day.
 * @property HygieneEventKind|null $event_kind Clean only (M5-R02): poop | accident | chewing (null on rows closed before M5-R02 = poop).
 */
class PetDailyRoutine extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'pet_id', 'local_date', 'routine_type', 'slot', 'opens_at', 'due_at',
        'status', 'done_at', 'actor_user_id', 'steps', 'goal', 'event_kind',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'local_date' => 'date:Y-m-d',
            'routine_type' => RoutineType::class,
            'status' => RoutineStatus::class,
            'slot' => 'integer',
            'opens_at' => 'datetime',
            'due_at' => 'datetime',
            'done_at' => 'datetime',
            'steps' => 'integer',
            'goal' => 'integer',
            'event_kind' => HygieneEventKind::class,
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }
}
