<?php

namespace App\Console\Commands;

use App\Enums\AiSpendPurpose;
use App\Models\Pet;
use App\Services\FalAiService;
use App\Services\Media\AiSpendGuard;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaEntitlementService;
use App\Services\Media\PetMediaService;
use App\Services\Media\StateMediaUpgradePlanner;
use Illuminate\Console\Command;

/**
 * Manual (never automatic): give existing pets the media a new pet gets at
 * birth (M4-03 / M4-05) — store their fal reference image on our disk (free)
 * or generate one, then the entitled state videos — within the production AI
 * budget. `--dry-run` only prints the plan and the estimated cost.
 *
 * M5-T16: `--state-media` is the bulk upgrade of /admin → »Upgrade existing pets to new
 * media« (StateMediaUpgradePlanner — eligible pets only, grouped per shared look + stage,
 * each look item priced once, budget days); `--dry-run` prints that calculation, the real
 * run queues exactly it through StateMediaUpgradePlanner::upgrade() (audited).
 */
class BackfillPetMediaCommand extends Command
{
    protected $signature = 'media:backfill
        {--pet= : Only this pet id}
        {--limit=50 : Maximum pets to queue (ignored with --state-media)}
        {--dry-run : Show the plan and estimated cost, queue nothing}
        {--state-media : M5-T16: bulk upgrade of eligible paid pets to the v3 media (StateMediaUpgradePlanner, same as /admin → Upgrade to new media)}';

    protected $description = 'Generate missing AI media (reference image + state videos) for existing pets within the AI budget';

    public function handle(PetMediaService $media, MediaEntitlementService $entitlements, AiSpendGuard $guard, FalAiService $fal): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('state-media')) {
            return $this->stateMediaUpgrade(app(StateMediaUpgradePlanner::class), $dryRun);
        }

        if (! $dryRun && (! $fal->isEnabled() || FalGateway::balanceExhaustedAt() !== null)) {
            $this->error('fal.ai is disabled (FAL_AI_API_KEY) or its balance is flagged as exhausted — nothing queued.');

            return self::FAILURE;
        }

        $petId = $this->option('pet');
        $pets = Pet::query()
            ->where('is_active', true)
            ->when($petId !== null, fn ($q) => $q->whereKey((int) $petId))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $rows = [];
        $planned = 0.0;
        $queuedPets = 0;
        $stopped = false;

        foreach ($pets as $pet) {
            $plan = $media->planMissing($pet);

            if ($plan['image'] === 'ok' && $plan['videos'] === [] && $plan['keyframes'] === [] && $plan['transitions'] === [] && $plan['poses'] === [] && $plan['phases'] === []) {
                continue;
            }

            $status = 'planned';

            if (! $dryRun) {
                if ($guard->refusalFor($planned + $plan['cost_usd'], AiSpendPurpose::StateVideo) !== null) {
                    $stopped = true;
                    $status = 'budget — stopped';
                } else {
                    $done = $media->generateMissing($pet);
                    $status = $done['image'] ? 'queued image (videos follow)' : "queued {$done['videos']} video(s)";
                    $queuedPets++;
                    $planned += $plan['cost_usd'];
                }
            } else {
                $planned += $plan['cost_usd'];
            }

            $rows[] = [
                $pet->id,
                $pet->breed_type->value,
                $entitlements->tierFor($pet),
                $plan['image'],
                implode(', ', [...$plan['videos'], ...array_map(fn ($k) => "key:{$k}", $plan['keyframes']), ...$plan['transitions'], ...array_map(fn ($p) => "pose:{$p}", $plan['poses']), ...$plan['phases']]) ?: '—',
                sprintf('$%.2f', $plan['cost_usd']),
                $status,
            ];

            if ($stopped) {
                break;
            }
        }

        if ($rows === []) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $this->table(['Pet', 'Breed', 'Tier', 'Image', 'Videos', 'Est. cost', 'Result'], $rows);

        if ($dryRun) {
            $this->info(sprintf('Dry run: %d pet(s), estimated $%.2f. Budget left today: $%.2f of $%.2f.', count($rows), $planned, max(0, $guard->dailyCapUsd() - $guard->spentTodayUsd()), $guard->dailyCapUsd()));

            return self::SUCCESS;
        }

        $this->info(sprintf('Queued %d pet(s), estimated $%.2f.%s', $queuedPets, $planned, $stopped ? ' Stopped at the AI budget — run again tomorrow.' : ''));

        return self::SUCCESS;
    }

    /**
     * M5-T16 (QA M3): `--state-media` — the dry run prints the calculation of /admin →
     * »Upgrade existing pets to new media«; the real run queues exactly that set through
     * StateMediaUpgradePlanner::upgrade() (same eligibility, audited, chunked). `--limit`
     * does not apply: the calculation always covers every eligible pet.
     */
    private function stateMediaUpgrade(StateMediaUpgradePlanner $planner, bool $dryRun): int
    {
        $petId = $this->option('pet');
        $p = $planner->preview(petId: $petId !== null ? (int) $petId : null);

        if ($p['blocked'] !== null) {
            $this->warn("Nothing would be generated: {$p['blocked']}.");
        }

        if ($p['groups'] === []) {
            $this->info(sprintf('Nothing to upgrade (%d eligible pet(s)).', $p['eligible']));

            return self::SUCCESS;
        }

        $this->table(
            ['Look', 'Breed', 'Colour', 'Stage', 'Pets', ...array_values(StateMediaUpgradePlanner::CATEGORIES), 'Est. cost'],
            array_map(fn (array $g): array => [
                ($g['unique'] ? "pet #{$g['pet_id']} (unique)" : "#{$g['look_id']}").($g['stage_remake'] > 0 ? ' stage remake: full set' : ''),
                $g['breed'],
                $g['colour'] ?? '—',
                $g['stage'] ?? '—',
                $g['pets'],
                ...array_values($g['items']),
                sprintf('$%.2f', $g['cost_usd']),
            ], $p['groups']),
        );

        $b = $p['budget'];
        $summary = sprintf(
            '%d pet(s) in %d look(s) (%d already complete), estimated $%.2f (all %d eligible; --limit is ignored). Budget left today: $%.2f of $%.2f (%s); this month $%.2f of $%.2f. %s',
            $p['pets'], $p['looks'], $p['complete_looks'], $p['cost_usd'], $p['eligible'], $b['left_today_usd'], $b['daily_cap_usd'],
            $b['today_share_percent'] === null ? 'none left today' : "uses {$b['today_share_percent']} %",
            $b['left_month_usd'], $b['monthly_cap_usd'],
            match (true) {
                $b['days'] === null => 'Never at the current caps.',
                $b['fits_today'] => 'Fits today.',
                default => "About {$b['days']} budget day(s).",
            },
        );

        if ($dryRun) {
            $this->info('Dry run (state media upgrade): '.$summary);

            return self::SUCCESS;
        }

        $petFilter = $petId !== null ? (int) $petId : null;
        $result = $planner->upgrade(null, ['pet' => $petFilter, 'source' => 'console'], $p['pet_ids'], (float) $p['cost_usd']);

        if ($result['status'] !== 'queued') {
            $this->error("Nothing queued ({$result['status']}".($result['reason'] !== null ? ": {$result['reason']}" : '').').');

            return self::FAILURE;
        }

        $this->info(sprintf('Queued the state media upgrade of %d pet(s), estimated $%.2f. %s', $result['pets'], $result['cost_usd'], $summary));

        return self::SUCCESS;
    }
}
