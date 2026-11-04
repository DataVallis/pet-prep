<?php

namespace App\Console\Commands;

use App\Enums\AiSpendPurpose;
use App\Models\AppSettingChange;
use App\Models\ItemMedia;
use App\Services\AppSettingsService;
use App\Services\Media\AiSpendGuard;
use App\Services\Media\StillQualityService;
use Illuminate\Console\Command;

/**
 * AI still check of images that are ALREADY stored (David 2026-11-04) — the check every new key /
 * pose / item image now gets before a video is made from it (StillQualityService). Only files
 * whose stored generation was never checked are selected, so a run never pays for the same file
 * twice and a repeated run finds nothing new.
 *
 *   (default) / --items   READY item key images (few rows). Scheduled daily (self-limiting: after
 *                         the first run only files made with the check off are left). A fail in
 *                         stills mode enforce regenerates the key image (new seed; ≤ max_still_retries
 *                         per chain) and its clip follows — the old files stay shown until then.
 *   --pets                READY pet key / pose images (own media + shared look rows). NEVER
 *                         scheduled (cost): verdicts are only recorded (fail = `shadow_failed`, shown;
 *                         /admin → Pet → AI media → Regenerate / »Approve anyway«).
 *   --dry-run             count + estimated cost only — no paid call, nothing written.
 *   --limit=N             at most N files this run.
 */
class StillCheckMediaCommand extends Command
{
    protected $signature = 'media:still-check
        {--items : READY item key images (the default when neither --items nor --pets is given)}
        {--pets : READY pet key / pose images (manual only — cost)}
        {--dry-run : Count and estimated cost only, no paid call, nothing written}
        {--limit=0 : At most this many files (0 = all)}';

    protected $description = 'AI still check of stored key / pose / item images that were never checked; item key images that fail are regenerated (enforce)';

    public function handle(StillQualityService $stills, AiSpendGuard $guard, AppSettingsService $settings): int
    {
        $pets = (bool) $this->option('pets');
        $items = (bool) $this->option('items') || ! $pets;
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $mode = $settings->qualityStillsMode(fresh: true);

        if (! $dryRun && $mode === AppSettingsService::QUALITY_OFF) {
            $this->info('The still check is switched off (/admin → Feature switches) — nothing checked.');

            return self::SUCCESS;
        }

        $itemQuery = $items ? $stills->uncheckedItemImages() : null;
        $petQuery = $pets ? $stills->uncheckedPetStills() : null;
        $itemCount = $itemQuery?->count() ?? 0;
        $petCount = $petQuery?->count() ?? 0;
        $total = $limit > 0 ? min($limit, $itemCount + $petCount) : $itemCount + $petCount;
        $perCheck = $stills->costUsd();
        $estimate = round($total * $perCheck, 4);
        $unavailable = $stills->unavailable();

        $this->line(sprintf('Unchecked stored stills: %d item key image(s), %d pet key / pose image(s)%s. To check now: %d × ≈ $%.4f = ≈ $%.4f (production AI budget; left today $%.2f of $%.2f). Still check mode: %s.',
            $itemCount, $petCount, $pets ? '' : ' (pets not selected — --pets)', $total, $perCheck, $estimate,
            max(0, $guard->dailyCapUsd() - $guard->spentTodayUsd()), $guard->dailyCapUsd(), $mode));

        if ($dryRun || $total === 0) {
            if ($dryRun) {
                $this->info('Dry run: nothing checked, nothing written, $0.');
            }

            return self::SUCCESS;
        }

        if ($unavailable !== null) {
            $this->warn("The vision check cannot run now ({$unavailable}) — nothing checked; the next run tries again.");

            return self::SUCCESS;
        }

        if ($guard->refusalFor($estimate, AiSpendPurpose::QualityCheck) !== null) {
            $this->warn('The estimate does not fit today\'s budget: files after the budget is used up wait for the next run.');
        }

        $done = 0;
        $outcomes = [];
        $cost = 0.0;
        $failedIds = [];
        $rows = [];

        foreach ([$itemQuery, $petQuery] as $query) {
            if ($query === null) {
                continue;
            }
            foreach ($query->when($limit > 0, fn ($q) => $q->limit(max(1, $limit - $done)))->get() as $row) {
                if ($limit > 0 && $done >= $limit) {
                    break 2;
                }
                $result = $row instanceof ItemMedia ? $stills->recheckItem($row) : $stills->recheckPet($row);
                if ($result['outcome'] === 'waiting') {
                    $this->warn('Out of AI budget — the remaining files wait for the next run.');

                    break 2;
                }
                $done++;
                $cost += $result['cost_usd'];
                $outcomes[$result['outcome']] = ($outcomes[$result['outcome']] ?? 0) + 1;
                $label = $row instanceof ItemMedia ? "item #{$row->id} {$row->design} ({$row->scope})" : "pet_media #{$row->id} {$row->kind} {$row->state}";
                if (in_array($result['outcome'], ['failed', 'regenerating'], true)) {
                    $failedIds[] = $label;
                }
                $rows[] = [$label, $result['outcome'], mb_strimwidth((string) $result['reason'], 0, 80, '…')];
            }
        }

        if ($rows !== []) {
            $this->table(['File', 'Outcome', 'Reason'], $rows);
        }
        $this->info(sprintf('Checked %d file(s) for ≈ $%.4f: %s.', $done, $cost, $outcomes === [] ? 'none' : collect($outcomes)->map(fn ($n, $k) => "{$k} {$n}")->implode(', ')));

        if ($done > 0) {
            AppSettingChange::create([
                'key' => StillQualityService::AUDIT_COMMAND,
                'user_id' => null,
                'old' => null,
                'new' => ['items' => $items, 'pets' => $pets, 'checked' => $done, 'outcomes' => $outcomes, 'cost_usd' => round($cost, 4), 'failed' => array_slice($failedIds, 0, 50)],
            ]);
        }

        return self::SUCCESS;
    }
}
