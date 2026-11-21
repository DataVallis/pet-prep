<?php

namespace App\Models;

use App\Enums\DashboardOpenTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One parent dashboard open (H6, VALIDATION_PLAN §4.1, David 2026-11-21): at most one row per
 * parent per DashboardOpenService::DEDUPE_MINUTES, written only by DashboardOpenService. No child
 * data. Pruned after RETENTION_DAYS (`dashboard-opens:prune`); goes with the parent's account on
 * deletion (FK cascade + AccountDeletionService) and is part of the GDPR export.
 *
 * @property int $id
 * @property int $user_id
 * @property int $family_id
 * @property Carbon $opened_at
 * @property DashboardOpenTrigger|null $trigger
 */
class ParentDashboardOpen extends Model
{
    public const RETENTION_DAYS = 400;

    public $timestamps = false;

    /** @var array<string, mixed> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'trigger' => DashboardOpenTrigger::class];
    }
}
