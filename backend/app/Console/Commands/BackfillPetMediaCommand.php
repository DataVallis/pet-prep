<?php

namespace App\Console\Commands;

use App\Enums\AiSpendPurpose;
use App\Models\Pet;
use App\Services\FalAiService;
use App\Services\Media\AiSpendGuard;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaEntitlementService;
use App\Services\Media\PetMediaService;
use Illuminate\Console\Command;

/**
 * Manual (never automatic): give existing pets the media a new pet gets at
 * birth (M4-03 / M4-05) — store their fal reference image on our disk (free)
 * or generate one, then the entitled state videos — within the production AI
 * budget. `--dry-run` only prints the plan and the estimated cost.
 */
class BackfillPetMediaCommand extends Command
{
    protected $signature = 'media:backfill
        {--pet= : Only this pet id}
        {--limit=50 : Maximum pets to queue}
        {--dry-run : Show the plan and estimated cost, queue nothing}';

    protected $description = 'Generate missing AI media (reference image + state videos) for existing pets within the AI budget';

    public function handle(PetMediaService $media, MediaEntitlementService $entitlements, AiSpendGuard $guard, FalAiService $fal): int
    {
        $dryRun = (bool) $this->option('dry-run');

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

            if ($plan['image'] === 'ok' && $plan['videos'] === []) {
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
                implode(', ', $plan['videos']) ?: '—',
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
}
