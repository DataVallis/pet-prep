<?php

namespace App\Console\Commands;

use App\Models\PetMedia;
use App\Services\Media\AiSpendGuard;
use App\Services\Media\FrozenClipRegenerator;
use Illuminate\Console\Command;

/**
 * Fix 2026-10-23: remake the clips stored the frozen way — state loops made with the same
 * image as start and end frame (recipe `loop`) and same-pose school / game phase clips
 * made with an end frame — through the normal pipeline (FrozenClipRegenerator). Manual
 * only; `--dry-run` lists them with the estimated cost and makes no fal call.
 */
class RegenerateFrozenClipsCommand extends Command
{
    protected $signature = 'media:regenerate-frozen
        {--pet= : Only this pet (a pet on a shared look = that look)}
        {--look= : Only this shared look (pet_looks.id)}
        {--dry-run : List the frozen clips and the estimated cost, queue nothing}';

    protected $description = 'Regenerate frozen same-frame clips (loops + same-pose phase clips) as start-frame-only clips';

    public function handle(FrozenClipRegenerator $regenerator, AiSpendGuard $guard): int
    {
        $petId = $this->option('pet') !== null ? (int) $this->option('pet') : null;
        $lookId = $this->option('look') !== null ? (int) $this->option('look') : null;
        $plan = $regenerator->plan($petId, $lookId);

        if ($plan['state_media_off']) {
            $this->warn('State media is switched off (/admin → Feature switches) — nothing to regenerate.');
        } elseif ($plan['phase_clips_off']) {
            $this->warn('»School & game clips« is switched off — phase clips are skipped.');
        }

        if ($plan['rows']->isEmpty()) {
            $this->info('No frozen clips found.');

            return self::SUCCESS;
        }

        $this->table(['Media', 'Owner', 'Kind', 'State', 'Stage', 'Est. cost'], $plan['rows']->map(fn (PetMedia $r) => [
            $r->id,
            $r->pet_id !== null ? "pet {$r->pet_id}" : "look {$r->pet_look_id}",
            $r->isVideo() ? 'loop' : 'phase',
            $r->state,
            $r->life_stage ?? '—',
            sprintf('$%.3f', $regenerator->costOf($r)),
        ])->all());

        $summary = sprintf('%d loop(s) + %d phase clip(s), estimated $%.2f. Budget left today: $%.2f of $%.2f.',
            $plan['loops'], $plan['phases'], $plan['cost_usd'], max(0, $guard->dailyCapUsd() - $guard->spentTodayUsd()), $guard->dailyCapUsd());

        if ($this->option('dry-run')) {
            $this->info('Dry run: '.$summary);

            return self::SUCCESS;
        }

        $result = $regenerator->regenerate(null, $petId, $lookId);

        return match ($result['outcome']) {
            'queued' => tap(self::SUCCESS, fn () => $this->info(sprintf('Queued %d clip(s), estimated $%.2f. The current files stay visible until the new ones are stored.', $result['queued'], $result['cost_usd']))),
            'budget' => tap(self::FAILURE, fn () => $this->error(sprintf('Estimated $%.2f does not fit the AI budget now — nothing queued. %s', $result['cost_usd'], $summary))),
            'ai_off' => tap(self::FAILURE, fn () => $this->error('fal.ai is disabled (FAL_AI_API_KEY) or its balance is flagged as exhausted — nothing queued.')),
            default => tap(self::SUCCESS, fn () => $this->info('No frozen clips found.')),
        };
    }
}
