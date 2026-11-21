<?php

namespace App\Services\Validation;

use App\Enums\DashboardOpenTrigger;
use App\Models\ParentDashboardOpen;
use App\Models\User;
use App\Services\FamilyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Parent dashboard opens (H6, VALIDATION_PLAN §4.1, contract docs/engineering/VALIDATION_API.md §2,
 * David 2026-11-21): `POST /api/parent/dashboard-opened` records at most ONE row per parent per
 * DEDUPE_MINUTES — the app debounces too, so the dashboard's polling never counts. The only writer
 * of `parent_dashboard_opens`; the funnel reads it (FunnelService, H6).
 */
class DashboardOpenService
{
    public const DEDUPE_MINUTES = 30;

    public function __construct(private readonly FamilyService $families) {}

    /** True when a row was written, false when it fell inside the dedupe window. */
    public function record(User $parent, ?DashboardOpenTrigger $trigger, ?CarbonImmutable $now = null): bool
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $family = $this->families->ensureFamilyFor($parent);

        return DB::transaction(function () use ($parent, $family, $trigger, $now): bool {
            // The parent's row serialises two simultaneous calls of the same parent.
            User::whereKey($parent->id)->lockForUpdate()->firstOrFail(['id']);

            $recent = ParentDashboardOpen::where('user_id', $parent->id)
                ->where('opened_at', '>', $now->subMinutes(self::DEDUPE_MINUTES))
                ->exists();
            if ($recent) {
                return false;
            }

            ParentDashboardOpen::create([
                'user_id' => $parent->id,
                'family_id' => $family->id,
                'opened_at' => $now,
                'trigger' => $trigger,
            ]);

            return true;
        });
    }

    /** `dashboard-opens:prune`: rows older than RETENTION_DAYS. */
    public function prune(?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now())->utc();

        return ParentDashboardOpen::where('opened_at', '<', $now->subDays(ParentDashboardOpen::RETENTION_DAYS))->delete();
    }
}
