<?php

namespace App\Console\Commands;

use App\Enums\BreedType;
use App\Models\BreedFact;
use App\Services\BreedFactImportService;
use Illuminate\Console\Command;

/**
 * Manual (never scheduled): import »Ali si vedel?« breed facts from the
 * shipped copy of docs/research/breed-facts (M5-R09b) — see
 * BreedFactImportService for the rules and docs/research/breed-facts/README.md
 * for the file format. A file with any error is skipped as a whole and the
 * command fails; the other files are still imported. Idempotent.
 *
 * Production: docker compose -f backend/compose.production.yaml run --rm app php artisan breeds:import-facts --dry-run
 */
class ImportBreedFactsCommand extends Command
{
    protected $signature = 'breeds:import-facts
        {--breed=* : Only these breeds (BreedType value "border_collie" or slug "border-collie"; repeatable)}
        {--dry-run : Validate and print what would change; nothing is written}
        {--print-hashes : Print "<key> <content hash> <verified_hash state>" per fact (the value for verified_hash); nothing is written}
        {--dir= : Folder with <breed>.json (absolute, or relative to backend/; default database/data/breed-facts)}';

    protected $description = 'Import breed facts (»Ali si vedel?«) from JSON files — upsert by key';

    public function handle(BreedFactImportService $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $dir = $importer->directory($this->option('dir'));
        if (! is_dir($dir)) {
            $this->error("Folder not found: {$dir}");

            return self::FAILURE;
        }

        $breeds = [];
        foreach ((array) $this->option('breed') as $value) {
            $breed = BreedType::tryFrom((string) $value) ?? BreedType::fromSlug((string) $value);
            if ($breed === null) {
                $this->error("Unknown breed: {$value}");

                return self::FAILURE;
            }
            $breeds[] = $breed->value;
        }

        $files = $importer->files($dir, $breeds);
        if ($files === []) {
            $this->warn('No breed fact files found in '.$dir.($breeds !== [] ? ' for '.implode(', ', $breeds) : '').'.');

            return self::SUCCESS;
        }

        if ($this->option('print-hashes')) {
            return $this->printHashes($importer, $files);
        }

        $failed = 0;
        foreach ($files as $file) {
            $r = $importer->importFile($file, $dryRun);
            if (! $r['ok']) {
                $failed++;
                $this->error("{$r['file']}: REJECTED (".count($r['errors']).' error(s), nothing imported)');
                foreach ($r['errors'] as $error) {
                    $this->line("  - {$error}");
                }

                continue;
            }
            $this->info(sprintf(
                '%s: %d new, %d updated, %d approved → re-review, %d draft → verified, %d reordered, %d unchanged',
                $r['file'], count($r['created']), count($r['updated']), count($r['reopened']), count($r['promoted']), count($r['reordered']), count($r['unchanged']),
            ));
            if ($r['unproven'] !== []) {
                $this->warn('  "verified" without a matching verified_hash (kept as draft): '.implode(', ', $r['unproven']));
            }
            foreach ($r['reopened'] as $key) {
                $this->line("  ! {$key}: approved content changed — back to review, approve again in /admin");
            }
            if ($r['missing'] !== []) {
                $this->warn('  In the database but not in the file (kept): '.implode(', ', $r['missing']));
            }
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing was written.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $files
     */
    private function printHashes(BreedFactImportService $importer, array $files): int
    {
        $failed = 0;
        foreach ($files as $file) {
            [, $rows, $errors] = $importer->parse($file);
            if ($errors !== []) {
                $failed++;
                $this->error(basename($file).': '.count($errors).' error(s) — fix them first');
                foreach ($errors as $error) {
                    $this->line("  - {$error}");
                }

                continue;
            }
            foreach ($rows as $key => $row) {
                $hash = BreedFact::hashOf($row['content']);
                $state = match (true) {
                    $row['verified_hash'] === null => 'no-verified_hash',
                    $row['verified_hash'] === $hash => 'verified_hash-ok',
                    default => 'verified_hash-MISMATCH',
                };
                $this->line("{$key} {$hash} {$state}");
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
