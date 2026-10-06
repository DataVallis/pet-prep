<?php

namespace App\Models;

use App\Enums\TrainingCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How well a pet knows one command (M5-R03, TrainingService). `progress` is
 * precise (0–100, double) and rounded only for output; written only under
 * the pet's row lock (finish a session, daily decay in the tick).
 *
 * @property int $pet_id
 * @property TrainingCommand $command
 * @property float $progress
 * @property Carbon|null $last_practised_at Last completed session of this command.
 * @property int $sessions_completed
 */
class PetTrainingSkill extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['pet_id', 'command', 'progress', 'last_practised_at', 'sessions_completed'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'command' => TrainingCommand::class,
            'progress' => 'float',
            'last_practised_at' => 'datetime',
            'sessions_completed' => 'integer',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }
}
