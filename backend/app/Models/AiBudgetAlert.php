<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One AI budget warning that was sent to the superadmins (M5-T15). The unique
 * (budget, period, period_key, threshold) row is the dedupe: written by
 * App\Services\Media\AiBudgetMonitor before the notification goes out.
 *
 * @property string $budget
 * @property string $period
 * @property string $period_key
 * @property int $threshold
 */
class AiBudgetAlert extends Model
{
    public const UPDATED_AT = null;

    public const BUDGET_PRODUCTION = 'production';

    public const BUDGET_LAB = 'lab';

    public const BUDGET_FAL_BALANCE = 'fal_balance';

    /**
     * @var list<string>
     */
    protected $fillable = ['budget', 'period', 'period_key', 'threshold', 'spent_usd', 'cap_usd'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
            'spent_usd' => 'float',
            'cap_usd' => 'float',
        ];
    }
}
