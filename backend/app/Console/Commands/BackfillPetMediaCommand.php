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
 * M5-T16: `--state-media --dry-run` prints the same calculation as /admin → »Upgrade
 * existing pets to new media« (StateMediaUpgradePlanner::preview — eligible pets only,
 * grouped per shared look + stage, each look item priced once, budget days).
 */
class BackfillPetMediaCommand extends Command
{
    protected $signature = 'media:backfill
        {--pet= : Only this pet id}
        {--limit=50 : Maximum pets to queue}
        {--dry-run : Show the plan and estimated cost, queue nothing}
        {--state-media : M5-T08: also give pets from before M5-T08 their state key images, loops and transitions}';

    protected $description = 'Generate missing AI media (reference image + state videos) for existing pets within the AI budget';

    public function handle(PetMediaService $media, MediaEntitlementService $entitlements, AiSpendGuard $guard, FalAiService $fal): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun && $this->option('state-media')) {
            return $this->stateMediaPreview(app(StateMediaUpgradePlanner::class));
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
            $upgrade = (bool) $this->option('state-media');
            $plan = $media->planMissing($pet, $upgrade);

            if ($plan['image'] === 'ok' && $plan['videos'] === [] && $plan['keyframes'] === [] && $plan['transitions'] === [] && $plan['poses'] === [] && $plan['phases'] === []) {
                continue;
            }

            $status = 'planned';

            if (! $dryRun) {
                if ($guard->refusalFor($planned + $plan['cost_usd'], AiSpendPurpose::StateVideo) !== null) {
                    $stopped = true;
                    $status = 'budget — stopped';
                } else {
                    $done = $media->generateMissing($pet, $upgrade);
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

    /** M5-T16: the bulk upgrade calculation (no fal call, nothing written). */
    private function stateMediaPreview(StateMediaUpgradePlanner $planner): int
    {
        $petId = $this->option('pet');
        $p = $planner->preview(petId: $petId !== null ? (int) $petId : null, limit: max(1, (int) $this->option('limit')));

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
                $g['unique'] ? "pet #{$g['pet_id']} (unique)" : "#{$g['look_id']}",
                $g['breed'],
                $g['colour'] ?? '—',
                $g['stage'] ?? '—',
                $g['pets'],
                ...array_values($g['items']),
                sprintf('$%.2f', $g['cost_usd']),
            ], $p['groups']),
        );

        $b = $p['budget'];
        $this->info(sprintf(
            'Dry run (state media upgrade): %d pet(s) in %d look(s) (%d already complete), estimated $%.2f. Budget left today: $%.2f of $%.2f; this month $%.2f of $%.2f. %s',
            $p['pets'], $p['looks'], $p['complete_looks'], $p['cost_usd'], $b['left_today_usd'], $b['daily_cap_usd'], $b['left_month_usd'], $b['monthly_cap_usd'],
            match (true) {
                $b['days'] === null => 'Daily cap 0 $ — never.',
                $b['fits_today'] => 'Fits today.',
                default => "About {$b['days']} budget day(s) at the current daily cap.",
            },
        ));

        return self::SUCCESS;
    }
}
