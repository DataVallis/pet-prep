<?php

namespace App\Console\Commands;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Enums\BreedType;
use App\Enums\Species;
use App\Services\Media\AiCallException;
use App\Services\Media\AiSpendGuard;
use App\Services\Media\BreedPortraitService;
use App\Services\Media\FalGateway;
use App\Services\Media\ModelProfile;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Manual (never scheduled): AI-generated breed photos for the website
 * animal register (M5-R11, David 2026-10-10) — see BreedPortraitService.
 * Charged to the AI Lab budget. `--dry-run` prints prompts and the estimated
 * cost without calling fal.ai. Existing files are skipped unless --force.
 *
 * `--colours` (David 2026-10-26): instead of the default portraits, a colour
 * variant per standard coat colour (config/breed_colours.php) except the one the
 * default portrait shows — an image EDIT of that portrait (the reference, read
 * from --reference-dir, default the output directory). See BreedPortraitService.
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
        {--profile= : Image profile from config/media.php (default: the reference-image profile; with --colours the colour edit profile)}
        {--colours : Colour variants: every standard coat colour except the one of the default portrait, edited from that portrait}
        {--reference-dir= : With --colours: where the default portraits + manifest are read (default: the output directory)}';

    protected $description = 'Generate AI breed photos for the website animal register (AI Lab budget)';

    public function handle(BreedPortraitService $portraits, AiSpendGuard $guard): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $species = $this->option('species') !== null ? Species::tryFrom((string) $this->option('species')) : null;
            if ($this->option('species') !== null && $species === null) {
                throw new InvalidArgumentException('--species must be one of: '.implode(', ', Species::values()).'.');
            }
            $breeds = $portraits->breeds(array_values((array) $this->option('breed')), $species);
            $colours = (bool) $this->option('colours');
            $profile = $colours ? $portraits->colourProfile($this->option('profile')) : $portraits->profile($this->option('profile'));
            $outDir = $this->outDir();
            $referenceDir = $colours ? $this->referenceDir($outDir) : $outDir;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($breeds === []) {
            $this->warn('No register breeds match.');

            return self::SUCCESS;
        }

        if ($colours) {
            return $this->handleColours($portraits, $guard, $breeds, $profile, $outDir, $referenceDir, $dryRun);
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

    /**
     * @param  list<BreedType>  $breeds
     */
    private function handleColours(BreedPortraitService $portraits, AiSpendGuard $guard, array $breeds, ModelProfile $profile, string $outDir, string $referenceDir, bool $dryRun): int
    {
        $plan = $portraits->colourPlan($breeds, $outDir, $referenceDir, (bool) $this->option('force'));
        $todo = array_values(array_filter($plan, fn (array $r) => in_array($r['action'], ['generate', 'force'], true)));
        $unit = $profile->estimatedCostUsd();
        $total = $portraits->estimate($profile, count($todo));

        $this->line("Colour variants. Profile: {$profile->key} ({$profile->endpoint}), ~\$".number_format($unit, 4).' per image, budget: AI Lab');
        $this->line("Output: {$outDir}");
        $this->line("Reference portraits: {$referenceDir}");

        foreach ($breeds as $breed) {
            if ($portraits->colours($breed) === []) {
                $this->warn("{$breed->value}: no colour list in config/breed_colours.php — no colour variants.");
            }
        }

        if ($dryRun) {
            foreach ($plan as $row) {
                $this->newLine();
                $this->line(sprintf('[%s] %s %s → %s (reference %s, default colour %s)', $row['action'], $row['breed']->value, $row['colour']['key'], $row['file'], $row['reference'], $row['default_colour']));
                $this->line('  '.$row['prompt']);
                $this->line('  '.$row['prompt_hash']);
            }
            $this->newLine();
        }

        $this->table(['Breed', 'Colour', 'File', 'Action'], array_map(fn (array $r) => [
            $r['breed']->value, $r['colour']['key'], $r['file'], $r['action'],
        ], $plan));

        $left = $portraits->labBudgetLeft();
        $this->line(sprintf(
            'To generate: %d image(s), estimated $%.4f. AI Lab budget left: today $%.2f, this month $%.2f.',
            count($todo), $total, $left['today'], $left['month'],
        ));

        $missing = [];
        foreach ($plan as $row) {
            if ($row['action'] === 'stale') {
                $this->warn("{$row['breed']->value} {$row['colour']['key']}: the prompt changed since {$row['file']} was generated — use --force --colours --breed={$row['breed']->value} to regenerate.");
            }
            if ($row['action'] === 'no-reference') {
                $missing[$row['breed']->value] = $row['reference'];
            }
        }
        foreach ($missing as $breed => $reference) {
            $this->warn("{$breed}: no default portrait {$reference} in the reference directory — generate it first (breeds:portraits --breed={$breed}).");
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

            return $missing === [] ? self::SUCCESS : self::FAILURE;
        }

        if (FalGateway::balanceExhaustedAt() !== null) {
            $this->error('fal.ai balance is flagged as exhausted — nothing generated.');

            return self::FAILURE;
        }

        $done = 0;
        $failed = 0;
        $spent = 0.0;

        foreach ($todo as $index => $row) {
            $label = "{$row['breed']->value} {$row['colour']['key']}";

            try {
                $entry = $portraits->generateColour($row['breed'], $row['colour'], $profile, $outDir, $row['reference_path'], $row['reference']);
                $done++;
                $spent += (float) $entry['cost_usd'];
                $this->info("{$label}: wrote {$entry['file']} ({$entry['width']}×{$entry['height']})");
            } catch (AiCallException $e) {
                $failed++;
                $spent += $e->chargedUsd;
                $this->error("{$label}: {$e->reason->value} — {$e->getMessage()}");

                if (in_array($e->reason, [AiCallFailure::BudgetLab, AiCallFailure::FalBalance, AiCallFailure::Disabled], true)) {
                    $rest = count($todo) - $index - 1;
                    $this->warn("Stopped: {$rest} more colour variant(s) not tried. Re-run later — finished variants are skipped.");

                    break;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$label}: {$e->getMessage()}");
            }
        }

        $this->line(sprintf('Generated %d, failed %d, estimated spend $%.4f (AI Lab).', $done, $failed, $spent));

        return $failed > 0 || $missing !== [] ? self::FAILURE : self::SUCCESS;
    }

    /** --reference-dir (absolute, or relative to backend/), default the output directory. */
    private function referenceDir(string $outDir): string
    {
        $option = $this->option('reference-dir');
        if ($option === null || $option === '') {
            return $outDir;
        }

        $path = (string) $option;
        $absolute = rtrim($this->normalise(str_starts_with($path, '/') ? $path : base_path($path)), '/');

        if (! is_dir($absolute)) {
            throw new InvalidArgumentException("--reference-dir {$absolute} does not exist.");
        }

        return $absolute;
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
