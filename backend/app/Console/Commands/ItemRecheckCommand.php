<?php

namespace App\Console\Commands;

use App\Enums\AiSpendPurpose;
use App\Models\AppSettingChange;
use App\Models\ItemMedia;
use App\Services\AppSettingsService;
use App\Services\Media\AiSpendGuard;
use App\Services\Media\ItemMediaService;
use App\Services\Media\StillQualityService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Re-check STORED »Želje« item content with the realism rubric (David 2026-11-19: a »Navy cushion
 * bed« looked pasted in — razor-sharp, studio-lit, floating with a detached shadow — under a big
 * Dobermann lying ON TOP of a too-small bed; the old rubric let it through). Selects READY item key
 * images and clips (pet / look rows) whose current generation was never decided by the current
 * item rubric (StillQualityService::itemsToRecheck()) — a file is never paid for twice and a repeated
 * run finds nothing new.
 *
 * DRY RUN BY DEFAULT: without --run it only counts and prices (no paid call, nothing written).
 *   --run          check them (one vision call each, production AI budget). In item check mode
 *                  enforce a failing file is flagged — never shown again — and regenerated (a key
 *                  image: new key image + its clip; a clip with a wrong object: a new key image
 *                  first; any other clip fail: a new clip), each regeneration guarded by
 *                  AiSpendGuard. Shadow: verdicts recorded only. The flagged file stays on the disk
 *                  until the new one is stored (the normal store replaces it, as an admin Regenerate does).
 *   --limit=N      at most N files this run (0 = all).
 *   --max-regenerations=N  stop checking after N regenerations (default
 *                  media.quality_check.max_recheck_regenerations, 10); the rest stay for the next run.
 * Only files an active owner's pet SHOWS now are checked (QA #1414 M2: owns the item — an action
 * item only while in use —, current stage, current content). A failing stored clip always starts a
 * fresh key image chain (M4). An AI error is reported as `error`, recorded on nothing, not audited
 * and not re-selected by later runs (m2). One run at a time (QA #1414 m5: Isolatable `--isolated`
 * plus a cache lock for --run). Never scheduled. Audited (`app_setting_changes` `media_item_recheck`).
 */
class ItemRecheckCommand extends Command implements Isolatable
{
    /** Cache lock of a real run (QA #1414 m5). */
    public const LOCK_KEY = 'media:item-recheck:run';

    /** app_setting_changes key of a real run. */
    public const AUDIT_KEY = 'media_item_recheck';

    protected $signature = 'media:item-recheck
        {--run : Really check (paid) and regenerate failing files — without it this is a dry run}
        {--limit=0 : At most this many files (0 = all)}
        {--max-regenerations= : Stop after this many regenerations (default media.quality_check.max_recheck_regenerations)}';

    protected $description = 'Re-check stored item key images and clips with the realism rubric (pasted / floating / too-small items); dry run unless --run';

    public function handle(StillQualityService $stills, AiSpendGuard $guard, AppSettingsService $settings, ItemMediaService $items): int
    {
        $run = (bool) $this->option('run');
        $limit = max(0, (int) $this->option('limit'));
        $regenCap = $this->option('max-regenerations') !== null
            ? max(0, (int) $this->option('max-regenerations'))
            : max(0, (int) config('media.quality_check.max_recheck_regenerations', 10));
        $mode = $settings->qualityStillsMode(fresh: true);

        $candidates = $stills->recheckCandidates();
        $images = $candidates->filter(fn (ItemMedia $r) => $r->isStill())->count();
        $clips = $candidates->count() - $images;
        $total = $limit > 0 ? min($limit, $images + $clips) : $images + $clips;
        $perCheck = $stills->costUsd();
        $estimate = round($total * $perCheck, 4);
        $perKeyImage = $items->keyImageRegenerationCostUsd();
        $regenerations = $mode === AppSettingsService::QUALITY_ENFORCE ? min($total, $regenCap) : 0;

        $this->line(sprintf('Stored item content shown to an owner and not checked with the realism rubric: %d key image(s), %d clip(s). To check: %d × ≈ $%.4f = ≈ $%.4f (production AI budget; left today $%.2f of $%.2f). Item check mode: %s.',
            $images, $clips, $total, $perCheck, $estimate, max(0, $guard->dailyCapUsd() - $guard->spentTodayUsd()), $guard->dailyCapUsd(), $mode));
        $this->line(sprintf('Regenerations if they fail (enforce, at most %d per run): up to %d × ≈ $%.2f (new key image + its clips — worst case the bowls: eating + drinking) = ≈ $%.2f — worst case of this run ≈ $%.2f.',
            $regenCap, $regenerations, $perKeyImage, $regenerations * $perKeyImage, $estimate + $regenerations * $perKeyImage));

        if (! $run) {
            $this->info('Dry run: nothing checked, nothing written, $0. Add --run to check (and, in enforce, regenerate what fails).');

            return self::SUCCESS;
        }

        if ($mode === AppSettingsService::QUALITY_OFF) {
            $this->info('The item check is switched off (/admin → Feature switches) — nothing checked.');

            return self::SUCCESS;
        }

        if ($total === 0) {
            return self::SUCCESS;
        }

        if (($unavailable = $stills->unavailable()) !== null) {
            $this->warn("The vision check cannot run now ({$unavailable}) — nothing checked; run again later.");

            return self::SUCCESS;
        }

        $lock = Cache::lock(self::LOCK_KEY, 3600);
        if (! $lock->get()) {
            $this->warn('Another media:item-recheck --run is running — nothing checked.');

            return self::SUCCESS;
        }

        try {
            return $this->runChecks($stills, $guard, $candidates, $limit, $regenCap, $estimate, $mode);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  Collection<int, ItemMedia>  $candidates
     */
    private function runChecks(StillQualityService $stills, AiSpendGuard $guard, Collection $candidates, int $limit, int $regenCap, float $estimate, string $mode): int
    {
        if ($guard->refusalFor($estimate, AiSpendPurpose::QualityCheck) !== null) {
            $this->warn('The estimate does not fit today\'s budget: files after the budget is used up wait for the next run.');
        }

        $done = 0;
        $regenerated = 0;
        $cost = 0.0;
        $outcomes = [];
        $failed = [];
        $rows = [];

        foreach ($limit > 0 ? $candidates->take($limit) : $candidates as $row) {
            if ($regenerated >= $regenCap) {
                $this->warn("Regeneration cap of this run reached ({$regenCap}) — the remaining files stay for the next run.");

                break;
            }
            $result = $stills->recheckItemRealism($row->fresh() ?? $row);
            if ($result['outcome'] === 'waiting') {
                $this->warn('Out of AI budget — the remaining files wait for the next run.');

                break;
            }
            $done += in_array($result['outcome'], ['skipped', 'busy', 'waiting', 'unavailable', 'error'], true) ? 0 : 1;
            $cost += $result['cost_usd'];
            $outcomes[$result['outcome']] = ($outcomes[$result['outcome']] ?? 0) + 1;
            $regenerated += in_array($result['outcome'], ['regenerating', 'regenerating_key_image'], true) ? 1 : 0;
            $label = "item #{$row->id} {$row->kind} {$row->design} ({$row->scope})";
            if (in_array($result['outcome'], ['failed', 'regenerating', 'regenerating_key_image'], true)) {
                $failed[] = $label;
            }
            $rows[] = [$label, $result['outcome'], mb_strimwidth((string) $result['reason'], 0, 90, '…')];
        }

        if ($rows !== []) {
            $this->table(['File', 'Outcome', 'Reason'], $rows);
        }
        $this->info(sprintf('Checked %d file(s) for ≈ $%.4f: %s.', $done, $cost, $outcomes === [] ? 'none' : collect($outcomes)->map(fn ($n, $k) => "{$k} {$n}")->implode(', ')));

        if ($done > 0) {
            AppSettingChange::create([
                'key' => self::AUDIT_KEY,
                'user_id' => null,
                'old' => null,
                'new' => ['checked' => $done, 'outcomes' => $outcomes, 'cost_usd' => round($cost, 4), 'mode' => $mode, 'failed' => array_slice($failed, 0, 50)],
            ]);
        }

        return self::SUCCESS;
    }
}
