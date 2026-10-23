<?php

namespace App\Console\Commands;

use App\Enums\AiSpendPurpose;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Services\Media\AiSpendGuard;
use App\Services\Media\MediaQualityService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Quality check of clips that are ALREADY stored (David 2026-10-23) — the same technical +
 * AI vision check new clips get (MediaQualityService). Manual only.
 *
 *   --dry-run      technical check only (ffmpeg, free) — nothing written, no paid call
 *   (real run)     technical + AI check (production budget, cost printed before); a failure
 *                  flags the stored file (`quality = flagged` — not shown, the app's fallback)
 *   --regenerate   also re-queue every flagged clip ONCE (new generation; a second failure
 *                  → »Needs review« in /admin)
 *
 * `--pet` of a pet on a shared look = that look's rows (checked once per look row).
 */
class QualityCheckMediaCommand extends Command
{
    protected $signature = 'media:quality-check
        {--pet= : Only this pet (a pet on a shared look = that look)}
        {--look= : Only this shared look (pet_looks.id)}
        {--dry-run : Technical check only (free), write nothing, no paid call}
        {--regenerate : Re-queue the clips that fail (once)}
        {--recheck : Also re-check clips that already have a quality verdict}';

    protected $description = 'Run the clip quality check (technical + AI vision) on stored AI clips; optionally regenerate failures once';

    public function handle(MediaQualityService $quality, AiSpendGuard $guard): int
    {
        if ($this->option('pet') !== null && $this->option('look') !== null) {
            $this->error('Use --pet or --look, not both (--pet of a pet on a shared look already means that look).');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = $this->candidates();

        if ($rows->isEmpty()) {
            $this->info('No stored clips to check.');

            return self::SUCCESS;
        }

        $perClip = $quality->visionCostUsd();
        $vision = $quality->visionUnavailable();
        $estimate = $dryRun || $vision !== null ? 0.0 : round($rows->count() * $perClip, 4);

        $this->line(sprintf('%d clip(s) to check. %s', $rows->count(), $dryRun
            ? 'Dry run: technical check only (ffmpeg), no paid call, nothing written.'
            : ($vision !== null
                ? "AI vision check not available ({$vision}) — technical check only, \$0."
                : sprintf('AI vision check ≈ $%.4f per clip → estimated $%.2f (production budget; left today $%.2f of $%.2f).',
                    $perClip, $estimate, max(0, $guard->dailyCapUsd() - $guard->spentTodayUsd()), $guard->dailyCapUsd()))));

        if (! $dryRun && $vision === null && $guard->refusalFor($estimate, AiSpendPurpose::QualityCheck) !== null) {
            $this->warn('The estimate does not fit the budget now: clips after the budget is used up are judged by the technical check only (»not AI-checked«).');
        }

        $table = [];
        $failed = collect();
        $cost = 0.0;

        foreach ($rows as $row) {
            $result = $quality->checkStored($row, $dryRun);
            $cost += $result['cost_usd'];
            if ($result['ok'] === false) {
                $failed->push($row);
            }
            $table[] = [
                $row->id,
                $row->pet_id !== null ? "pet {$row->pet_id}" : "look {$row->pet_look_id}",
                $row->kind,
                $row->state,
                rtrim(rtrim(number_format((float) ($row->stored_duration_seconds ?? $row->duration_seconds), 1), '0'), '.').' s',
                match ($result['ok']) {
                    true => 'pass',
                    false => 'FAIL',
                    null => 'unchecked',
                },
                $result['ai_status'],
                mb_strimwidth((string) ($result['reason'] ?? ''), 0, 90, '…'),
            ];
        }

        $this->table(['Media', 'Owner', 'Kind', 'State', 'Length', 'Result', 'AI', 'Reason'], $table);
        $this->info(sprintf('%d passed, %d failed%s. AI cost (estimated): $%.4f.',
            $rows->count() - $failed->count(), $failed->count(), $dryRun ? ' (technical only)' : ' — failed clips are flagged and not shown', $cost));

        $queued = 0;
        if ($this->option('regenerate')) {
            if ($dryRun) {
                $this->warn('--regenerate is ignored with --dry-run (nothing is flagged in a dry run).');
            } else {
                foreach ($failed as $row) {
                    $queued += $quality->regenerateFlagged($row) ? 1 : 0;
                }
                $this->info("Re-queued {$queued} clip(s) once (normal pipeline + budget guard; a second failure → »Needs review« in /admin).");
            }
        }

        if (! $dryRun) {
            AppSettingChange::create([
                'key' => MediaQualityService::AUDIT_COMMAND,
                'user_id' => null,
                'old' => null,
                'new' => [
                    'checked' => $rows->count(),
                    'failed_ids' => $failed->pluck('id')->all(),
                    'regenerated' => $queued,
                    'ai_estimated_usd' => round($cost, 4),
                    'filter' => ['pet' => $this->option('pet'), 'look' => $this->option('look')],
                ],
            ]);
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, PetMedia> */
    private function candidates(): Collection
    {
        $pet = $this->option('pet') !== null ? Pet::find((int) $this->option('pet')) : null;

        if ($this->option('pet') !== null && $pet === null) {
            return collect();
        }

        $lookId = $pet?->usesLookPool() ? (int) $pet->pet_look_id : ($this->option('look') !== null ? (int) $this->option('look') : null);
        $recheck = (bool) $this->option('recheck');

        return PetMedia::query()->clips()
            ->where('status', PetMedia::STATUS_READY)
            ->whereNotNull('storage_path')
            ->when(! $recheck, fn (Builder $q) => $q->whereNull('quality'))
            // Pool pets' slots mirror their look rows: check the look row once.
            ->whereNull('look_media_id')
            ->when($lookId !== null, fn (Builder $q) => $q->where('pet_look_id', $lookId))
            ->when($lookId === null && $pet !== null, fn (Builder $q) => $q->where('pet_id', $pet->id))
            ->when($lookId === null && $pet === null, fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNotNull('pet_look_id')
                ->orWhereHas('pet', fn (Builder $q) => $q->where('is_active', true))))
            ->orderBy('id')
            ->get();
    }
}
