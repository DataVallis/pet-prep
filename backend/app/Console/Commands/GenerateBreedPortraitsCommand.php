<?php

namespace App\Console\Commands;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Enums\Species;
use App\Services\Media\AiCallException;
use App\Services\Media\AiSpendGuard;
use App\Services\Media\BreedPortraitService;
use App\Services\Media\FalGateway;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Manual (never scheduled): AI-generated breed illustrations for the website
 * animal register (M5-R11, David 2026-10-10) — see BreedPortraitService.
 * Charged to the AI Lab budget. `--dry-run` prints prompts and the estimated
 * cost without calling fal.ai. Existing files are skipped unless --force.
 *
 * Runs synchronously on purpose: an operator tool for a handful of images, no
 * DB transaction is open around the fal call (FalGateway / MediaDownloader
 * enforce it), and a failed breed is simply re-run (files and manifest are
 * written per breed).
 */
class GenerateBreedPortraitsCommand extends Command
{
    protected $signature = 'breeds:portraits
        {--breed=* : Only these breeds (key "border_collie" or slug "border-collie"; repeatable)}
        {--species= : Only one species (dog | cat)}
        {--force : Regenerate portraits that already exist}
        {--dry-run : Print prompts and the estimated cost; no fal.ai call, nothing written}
        {--out= : Output directory (absolute, or relative to backend/; default ../docs/research/breed-portraits)}
        {--profile= : Image profile from config/media.php (default: the reference-image profile)}';

    protected $description = 'Generate AI breed illustrations for the website animal register (AI Lab budget)';

    public function handle(BreedPortraitService $portraits, AiSpendGuard $guard): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $species = $this->option('species') !== null ? Species::tryFrom((string) $this->option('species')) : null;
            if ($this->option('species') !== null && $species === null) {
                throw new InvalidArgumentException('--species must be one of: '.implode(', ', Species::values()).'.');
            }
            $breeds = $portraits->breeds(array_values((array) $this->option('breed')), $species);
            $profile = $portraits->profile($this->option('profile'));
            $outDir = $this->outDir();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($breeds === []) {
            $this->warn('No register breeds match.');

            return self::SUCCESS;
        }

        $plan = $portraits->plan($breeds, $outDir, (bool) $this->option('force'));
        $todo = array_values(array_filter($plan, fn (array $r) => in_array($r['action'], ['generate', 'force'], true)));
        $unit = $profile->estimatedCostUsd();
        $total = $portraits->estimate($profile, count($todo));

        $this->line("Profile: {$profile->key} ({$profile->endpoint}), ~\$".number_format($unit, 4).' per image, budget: AI Lab');
        $this->line("Output: {$outDir}");

        if ($dryRun) {
            foreach ($plan as $row) {
                $this->newLine();
                $this->line(sprintf('[%s] %s → %s', $row['action'], $row['breed']->value, $row['file']));
                $this->line('  '.$row['prompt']);
                $this->line('  '.$row['prompt_hash']);
            }
            $this->newLine();
        }

        $this->table(['Breed', 'Species', 'File', 'Action'], array_map(fn (array $r) => [
            $r['breed']->value, $r['breed']->species()->value, $r['file'], $r['action'],
        ], $plan));

        $left = $portraits->labBudgetLeft();
        $this->line(sprintf(
            'To generate: %d image(s), estimated $%.4f. AI Lab budget left: today $%.2f, this month $%.2f.',
            count($todo), $total, $left['today'], $left['month'],
        ));

        foreach ($plan as $row) {
            if ($row['action'] === 'stale') {
                $this->warn("{$row['breed']->value}: the prompt changed since {$row['file']} was generated — use --force --breed={$row['breed']->value} to regenerate.");
            }
        }

        if ($dryRun) {
            if ($todo !== [] && $guard->refusalFor($total, AiSpendPurpose::Lab) !== null) {
                $this->warn('The AI Lab budget would not cover all of them now; a run stops at the cap and skips the finished ones next time.');
            }
            $this->info('Dry run — no fal.ai call, nothing written.');

            return self::SUCCESS;
        }

        if ($todo === []) {
            $this->info('Nothing to generate.');

            return self::SUCCESS;
        }

        if (FalGateway::balanceExhaustedAt() !== null) {
            $this->error('fal.ai balance is flagged as exhausted — nothing generated.');

            return self::FAILURE;
        }

        $done = 0;
        $failed = 0;
        $spent = 0.0;

        foreach ($todo as $index => $row) {
            $breed = $row['breed'];

            try {
                $entry = $portraits->generate($breed, $profile, $outDir);
                $done++;
                $spent += (float) $entry['cost_usd'];
                $this->info("{$breed->value}: wrote {$entry['file']} ({$entry['width']}×{$entry['height']})");
            } catch (AiCallException $e) {
                $failed++;
                $spent += $e->chargedUsd;
                $this->error("{$breed->value}: {$e->reason->value} — {$e->getMessage()}");

                if (in_array($e->reason, [AiCallFailure::BudgetLab, AiCallFailure::FalBalance, AiCallFailure::Disabled], true)) {
                    $rest = count($todo) - $index - 1;
                    $this->warn("Stopped: {$rest} more breed(s) not tried. Re-run later — finished portraits are skipped.");

                    break;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$breed->value}: {$e->getMessage()}");
            }
        }

        $this->line(sprintf('Generated %d, failed %d, estimated spend $%.4f (AI Lab).', $done, $failed, $spent));

        if ($done > 0) {
            $this->line('Next: node scripts/export-breed-registry.mjs (adds `portrait` to the register export), then copy the files to the website (see HANDOFF).');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function outDir(): string
    {
        $option = $this->option('out');
        $default = $option === null || $option === '';
        $path = $default ? BreedPortraitService::DEFAULT_OUT : (string) $option;
        $absolute = str_starts_with($path, '/') ? $path : base_path($path);

        if ($default && ! is_dir(dirname($absolute))) {
            if ($this->option('dry-run')) {
                $this->warn('docs/research is not visible from here (Sail mounts only backend/) — for the real run pass --out=storage/app/breed-portraits.');

                return rtrim($this->normalise($absolute), '/');
            }

            throw new InvalidArgumentException(
                'The repo folder docs/research is not visible from here ('.dirname($absolute).'). '
                .'Under Sail only backend/ is mounted: pass --out=storage/app/breed-portraits and copy that folder to docs/research/breed-portraits afterwards.'
            );
        }

        return rtrim($this->normalise($absolute), '/');
    }

    /** Resolves "a/b/../c" without requiring the path to exist yet. */
    private function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $part;
        }

        return '/'.implode('/', $parts);
    }
}
