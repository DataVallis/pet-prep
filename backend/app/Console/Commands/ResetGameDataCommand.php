<?php

namespace App\Console\Commands;

use App\Services\GameDataResetService;
use Illuminate\Console\Command;
use Throwable;

/**
 * One-off production reset before the beta (David 2026-10-08 13:50, DEPLOYMENT.md
 * D17): delete every family, parent, child, pet and all their data + the stored
 * pet media files; keep the superadmins and the breed reference data.
 *
 * Default = dry run (counts only, never personal data). `--execute` needs:
 *   --i-understand-this-deletes-all-game-data
 *   --backup-done=<backup file or timestamp>  — pg_dump runs on the HOST
 *     (scripts/backup-production-db.sh via docker), it is not reachable from
 *     inside the app container; scripts/reset-game-data.sh runs it first and
 *     passes the file here.
 *   production: the app must be in maintenance mode (the wrapper script does it)
 *   a typed confirmation of the app host (APP_URL), unless --no-interaction.
 * Normal entry point on the server: scripts/reset-game-data.sh.
 */
class ResetGameDataCommand extends Command
{
    public const LONG_FLAG = 'i-understand-this-deletes-all-game-data';

    protected $signature = 'petprep:reset-game-data
        {--execute : Actually delete (default is a dry run)}
        {--i-understand-this-deletes-all-game-data : Required with --execute}
        {--backup-done= : Path or timestamp of the fresh DB backup taken right before (required with --execute)}';

    protected $description = 'Delete all game data (families, users except superadmins, pets, logs, payments, media files); keep admins and breed data. Dry run by default.';

    public function handle(GameDataResetService $reset): int
    {
        $plan = $reset->plan();
        $this->printPlan($plan);

        if ($plan['unclassified'] !== []) {
            $this->error('Unclassified tables: '.implode(', ', $plan['unclassified']).'. Classify them in GameDataResetService (KEEP / PARTIAL / DELETE_ORDER) — refusing.');

            return self::FAILURE;
        }

        if (! $this->option('execute')) {
            $this->info('Dry run — nothing was changed. Re-run with --execute (see DEPLOYMENT.md D17).');

            return self::SUCCESS;
        }

        if (! $this->option(self::LONG_FLAG)) {
            $this->error('Refusing: --execute also needs --'.self::LONG_FLAG.'.');

            return self::FAILURE;
        }

        $backup = trim((string) $this->option('backup-done'));
        if ($backup === '') {
            $this->error('Refusing: no fresh backup. The DB backup runs on the host (scripts/backup-production-db.sh) — use scripts/reset-game-data.sh, or pass --backup-done=<file or timestamp> after taking one.');

            return self::FAILURE;
        }

        if ($plan['admin_ids'] === []) {
            $this->error('Refusing: no superadmin account (users.is_superadmin) — Filament would be locked out.');

            return self::FAILURE;
        }

        if ($this->laravel->environment('production') && ! $this->laravel->isDownForMaintenance()) {
            $this->error('Refusing: production must be in maintenance mode (php artisan down) — use scripts/reset-game-data.sh.');

            return self::FAILURE;
        }

        $host = $this->expectedHost();
        if ($this->input->isInteractive()) {
            $typed = trim((string) $this->ask("This deletes ALL game data on {$host} (env: ".$this->laravel->environment().'). Type the host to confirm'));
            if (! hash_equals($host, $typed)) {
                $this->error('Confirmation did not match — nothing was changed.');

                return self::FAILURE;
            }
        }

        $this->warn("Deleting game data (backup: {$backup}) ...");

        try {
            $result = $reset->execute();
        } catch (Throwable $e) {
            $this->error('Reset failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['table', 'rows deleted'], collect($result['deleted'])
            ->map(fn (int $n, string $table): array => [$table, $n])->values()->all());
        $this->info(sprintf('Done. Kept %d superadmin(s) (ids %s).', count($result['admin_ids']), implode(', ', $result['admin_ids'])));
        $this->line(sprintf('Media files deleted: %d (%s).', $result['media']['files'], $this->humanBytes($result['media']['bytes'])));
        $this->line("Queued jobs cleared: {$result['queues_cleared']}.");
        $this->line('Cache cleared: '.($result['cache_cleared'] ? 'yes.' : 'NO — run php artisan cache:clear.'));

        return self::SUCCESS;
    }

    /**
     * @param  array{delete: array<string, int>, keep: array<string, int>, admin_ids: list<int>, orphaned_breed_edits: int, media: array{files: int, bytes: int}, unclassified: list<string>}  $plan
     */
    private function printPlan(array $plan): void
    {
        $this->line('Environment: '.$this->laravel->environment().' · host: '.$this->expectedHost());
        $this->table(['DELETE — table', 'rows'], collect($plan['delete'])
            ->map(fn (int $n, string $table): array => [$table, $n])->values()->all());
        $this->table(['KEEP — table', 'rows'], collect($plan['keep'])
            ->map(fn (int $n, string $table): array => [$table, $n])->values()->all());
        $this->line(sprintf(
            'Superadmins kept: %d (ids %s) — e-mails are never printed.',
            count($plan['admin_ids']),
            $plan['admin_ids'] === [] ? '—' : implode(', ', $plan['admin_ids']),
        ));
        if ($plan['orphaned_breed_edits'] > 0) {
            $this->line("Breed edits by non-admin users: {$plan['orphaned_breed_edits']} (kept; author becomes null).");
        }
        $this->line(sprintf('Pet media files to delete: %d (%s).', $plan['media']['files'], $this->humanBytes($plan['media']['bytes'])));
    }

    private function expectedHost(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 1).' '.$units[$i];
    }
}
