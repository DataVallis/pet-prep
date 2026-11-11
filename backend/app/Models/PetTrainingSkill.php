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

    /**
     * Learned (»naučeno«): the DISPLAYED progress is 100 (PRODUCT_SPEC §6 —
     * thresholds follow the displayed value, half up: 99.5 counts). The one
     * definition for the payload's `learned` and the decay exemption
     * (David 2026-11-11: a learned command does not decay).
     */
    public static function isMasteredProgress(float|int|string|null $progress): bool
    {
        return Pet::displayValue($progress) >= 100;
    }

    public function isMastered(): bool
    {
        return self::isMasteredProgress($this->progress);
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }
}
