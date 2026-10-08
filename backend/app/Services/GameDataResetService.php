<?php

namespace App\Services;

use App\Models\User;
use App\Services\Media\PetMediaService;
use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * One-off production reset before the beta (David 2026-10-08 13:50): delete all
 * game data — families, parents, children, pets and everything that hangs off
 * them, payments ledger, AI spend / AI Lab rows, queues, sessions, cache and
 * the stored pet media files — and KEEP the superadmin accounts (Filament) and
 * the breed reference data (incl. admin edits and their history).
 *
 * Every table of the schema must be classified in KEEP, PARTIAL or DELETE.
 * An unclassified table (a migration added after this class) blocks the
 * execution — `unclassifiedTables()` — so a new table is never silently kept
 * or silently wiped. The Pest test enumerates the schema too.
 *
 * Deletion is a single transaction of plain query-builder DELETEs (no model
 * events — the Pet `deleted` hook would queue one media job per pet) in
 * foreign-key-safe order (DELETE_ORDER: referencing tables first). TRUNCATE is
 * not used: `users` and `personal_access_tokens` are only partly deleted, and
 * `users.pairing_pet_id → pets` would make TRUNCATE pets CASCADE into users.
 * Sequences are NOT reset (old ids in a stale app cache or signed media URL can
 * never point at a new row's data). Files on the pet_media disk go after the
 * commit, then the queues and the cache are cleared. Idempotent: a second run
 * deletes nothing.
 *
 * Kept tables reference users only with ON DELETE SET NULL
 * (`breed_stage_param_changes.user_id`, `breed_stage_params.updated_by`):
 * edits by a non-admin user stay, only the author is nulled (reported in the plan).
 */
class GameDataResetService
{
    /** Reference / framework tables kept untouched. */
    public const KEEP = [
        'migrations',
        'breed_configs',
        'breed_stage_params',
        'breed_stage_param_changes',
    ];

    /** Tables where only some rows go (see keptQuery()). */
    public const PARTIAL = [
        'users',                  // superadmins stay (Filament)
        'personal_access_tokens', // tokens of the kept superadmins stay
    ];

    /**
     * Deleted completely, in this order (referencing tables before the tables
     * they reference). `users` / `personal_access_tokens` (PARTIAL) are deleted
     * at their place in this order too.
     */
    public const DELETE_ORDER = [
        // push (tickets → notifications / devices → tokens, users, pets)
        'push_tickets',
        'push_notifications',
        'device_push_tokens',
        // payments
        'challenge_credits',
        'purchase_events',
        // AI spend + AI Lab (ledger → lab results → lab runs)
        'ai_spend_ledger',
        'media_lab_results',
        'media_lab_runs',
        // everything that hangs off a pet
        'activities_log',
        'pet_caretakers',
        'pet_contracts',
        'pet_daily_routines',
        'pet_daily_steps',
        'pet_daily_walks',
        'pet_hygiene_events',
        'pet_media_history',
        'pet_media',
        'pet_play_events',
        'pet_status_periods',
        'pet_training_sessions',
        'pet_training_skills',
        'child_login_pins',
        'pets',
        // family model
        'family_invites',
        'family_user',
        'quiet_hours',
        // auth, framework
        'personal_access_tokens',
        'sessions',
        'password_reset_tokens',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'users',
        'families',
    ];

    /** Queues the workers listen to (compose.production.yaml). */
    public const QUEUES = ['default', 'broadcasts', 'notifications'];

    public function __construct(private readonly PetMediaService $media) {}

    /** Every table name in the current schema. @return list<string> */
    public function schemaTables(): array
    {
        return array_values(array_map(
            fn ($row): string => (string) $row->tablename,
            DB::select('select tablename from pg_tables where schemaname = current_schema() order by tablename'),
        ));
    }

    /** Tables present in the schema but in none of the lists. @return list<string> */
    public function unclassifiedTables(): array
    {
        $known = array_merge(self::KEEP, self::PARTIAL, self::DELETE_ORDER);

        return array_values(array_diff($this->schemaTables(), $known));
    }

    /** Ids of the users that stay (superadmins). @return list<int> */
    public function keptUserIds(): array
    {
        return User::query()->where('is_superadmin', true)->orderBy('id')->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * What a run would do. Never prints personal data: counts and admin ids only.
     *
     * @return array{
     *     delete: array<string, int>,
     *     keep: array<string, int>,
     *     admin_ids: list<int>,
     *     orphaned_breed_edits: int,
     *     media: array{files: int, bytes: int},
     *     media_root: string,
     *     media_root_problem: ?string,
     *     unclassified: list<string>
     * }
     */
    public function plan(): array
    {
        $tables = $this->schemaTables();
        $adminIds = $this->keptUserIds();

        $delete = [];
        foreach (self::DELETE_ORDER as $table) {
            if (in_array($table, $tables, true)) {
                $delete[$table] = $this->deletableQuery($table, $adminIds)->count();
            }
        }

        $keep = [];
        foreach (array_merge(self::KEEP, self::PARTIAL) as $table) {
            if (in_array($table, $tables, true)) {
                $keep[$table] = DB::table($table)->count() - ($delete[$table] ?? 0);
            }
        }

        return [
            'delete' => $delete,
            'keep' => $keep,
            'admin_ids' => $adminIds,
            'orphaned_breed_edits' => $this->orphanedBreedEdits($adminIds),
            'media' => $this->mediaUsage(),
            'media_root' => $this->mediaRoot(),
            'media_root_problem' => $this->mediaRootProblem(),
            'unclassified' => array_values(array_diff($tables, array_merge(self::KEEP, self::PARTIAL, self::DELETE_ORDER))),
        ];
    }

    /**
     * Delete everything (one transaction), then the media files, the queues and
     * the cache. The caller has checked the guards (flags, backup, confirmation).
     *
     * Everything after the commit (media files, queues, cache) never throws:
     * a failure there is returned in `post_commit_errors` — the DB is already
     * reset and a rerun (idempotent) finishes the cleanup.
     *
     * @return array{deleted: array<string, int>, admin_ids: list<int>, media: array{files: int, bytes: int}, queues_cleared: int, cache_cleared: bool, post_commit_errors: list<string>}
     *
     * @throws \RuntimeException before anything is deleted: unclassified tables,
     *                           unexpected media root, no superadmin to keep
     * @throws Throwable a failing DELETE — the transaction is rolled back
     */
    public function execute(): array
    {
        $unclassified = $this->unclassifiedTables();
        if ($unclassified !== []) {
            throw new \RuntimeException('Unclassified tables: '.implode(', ', $unclassified).' — classify them in GameDataResetService first.');
        }

        $rootProblem = $this->mediaRootProblem();
        if ($rootProblem !== null) {
            throw new \RuntimeException($rootProblem);
        }

        $deleted = DB::transaction(function (): array {
            // Read inside the transaction: admins are locked so a concurrent
            // Filament edit cannot flip the flag between the read and the deletes.
            $adminIds = User::query()->where('is_superadmin', true)->orderBy('id')->lockForUpdate()->pluck('id')
                ->map(fn ($id): int => (int) $id)->all();
            if ($adminIds === []) {
                throw new \RuntimeException('No superadmin account found — refusing (Filament would be locked out).');
            }

            // Kept users must not hang off a deleted row: users.parent_id
            // CASCADEs (an admin whose parent_id points at a deleted parent
            // would be deleted with them); pairing_pet_id is SET NULL anyway.
            // The legacy pairing PIN of a kept admin is game data too.
            DB::table('users')->whereIn('id', $adminIds)->update([
                'parent_id' => null, 'pairing_pet_id' => null, 'pairing_pin' => null, 'pin_expires_at' => null,
            ]);

            $counts = [];
            foreach (self::DELETE_ORDER as $table) {
                $counts[$table] = $this->deletableQuery($table, $adminIds)->delete();
            }

            return $counts;
        });

        // ---- committed: from here on nothing may throw (see the docblock) ----
        $errors = [];
        $media = ['files' => 0, 'bytes' => 0];
        try {
            $media = $this->deleteAllMediaFiles();
        } catch (Throwable $e) {
            $errors[] = 'media files: '.$e->getMessage();
        }
        $queues = 0;
        try {
            $queues = $this->clearQueues();
        } catch (Throwable $e) {
            $errors[] = 'queues: '.$e->getMessage();
        }
        $cache = $this->clearCache();
        if (! $cache) {
            $errors[] = 'cache: cache:clear failed';
        }

        Log::warning('Game data reset executed', [
            'deleted' => $deleted,
            'kept_admins' => count($this->keptUserIds()),
            'media_files' => $media['files'],
            'post_commit_errors' => $errors,
            'at' => now()->utc()->toIso8601String(),
        ]);

        return [
            'deleted' => $deleted,
            'admin_ids' => $this->keptUserIds(),
            'media' => $media,
            'queues_cleared' => $queues,
            'cache_cleared' => $cache,
            'post_commit_errors' => $errors,
        ];
    }

    /**
     * Rows of $table that a run deletes.
     *
     * @param  list<int>  $adminIds
     */
    private function deletableQuery(string $table, array $adminIds): Builder
    {
        $query = DB::table($table);

        return match ($table) {
            'users' => $query->whereNotIn('id', $adminIds === [] ? [0] : $adminIds),
            // Tokens of kept admins stay; everything else (other users, other
            // tokenable types) goes.
            'personal_access_tokens' => $query->where(fn (Builder $q) => $q
                ->where('tokenable_type', '!=', User::class)
                ->orWhereNotIn('tokenable_id', $adminIds === [] ? [0] : $adminIds)),
            default => $query,
        };
    }

    /**
     * Kept breed rows whose author (ON DELETE SET NULL) is a user that goes.
     *
     * @param  list<int>  $adminIds
     */
    private function orphanedBreedEdits(array $adminIds): int
    {
        $ids = $adminIds === [] ? [0] : $adminIds;

        return DB::table('breed_stage_param_changes')->whereNotNull('user_id')->whereNotIn('user_id', $ids)->count()
            + DB::table('breed_stage_params')->whereNotNull('updated_by')->whereNotIn('updated_by', $ids)->count();
    }

    /** Absolute root of the pet media disk (what the wrapper archives). */
    public function mediaRoot(): string
    {
        return rtrim($this->media->disk()->path(''), '/');
    }

    /**
     * Why the media disk must not be emptied, or null. The wrapper script
     * archives storage/app/pet-media from the app container — the reset may
     * only delete files from exactly that directory on a local disk.
     */
    public function mediaRootProblem(): ?string
    {
        $disk = (string) config('media.storage.disk', 'pet_media');
        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            return "Media disk '{$disk}' is not a local disk — the wrapper's archive would not contain its files; refusing.";
        }

        $expected = rtrim((string) (config('media.storage.reset_expected_root') ?: storage_path('app/pet-media')), '/');
        $actual = $this->mediaRoot();
        if ($actual !== $expected) {
            return "Media disk root is {$actual}, expected {$expected} (the directory the wrapper archives); refusing.";
        }

        return null;
    }

    /** @return array{files: int, bytes: int} */
    public function mediaUsage(): array
    {
        $disk = $this->media->disk();
        $files = 0;
        $bytes = 0;
        foreach ($disk->allFiles() as $path) {
            if ($this->isKeptFile($path)) {
                continue;
            }
            $files++;
            $bytes += (int) $disk->size($path);
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    /**
     * Empty the pet_media disk (one directory per pet + pet_media_history
     * files inside them). The disk root stays — Caddy mounts it read-only.
     *
     * @return array{files: int, bytes: int}
     */
    private function deleteAllMediaFiles(): array
    {
        $usage = $this->mediaUsage();
        $disk = $this->media->disk();

        foreach ($disk->directories() as $directory) {
            $disk->deleteDirectory($directory);
        }
        foreach ($disk->files() as $path) {
            if (! $this->isKeptFile($path)) {
                $disk->delete($path);
            }
        }

        return $usage;
    }

    private function isKeptFile(string $path): bool
    {
        return basename($path) === '.gitignore';
    }

    /** Pending jobs on the worker queues (redis in production). */
    private function clearQueues(): int
    {
        $connection = Queue::connection();
        if (! $connection instanceof ClearableQueue) {
            return 0; // sync / null; the database queue's `jobs` table is already empty
        }

        $cleared = 0;
        foreach (self::QUEUES as $name) {
            try {
                $cleared += $connection->clear($name);
            } catch (Throwable $e) {
                Log::warning('Game data reset: could not clear queue', ['queue' => $name, 'error' => $e->getMessage()]);
            }
        }

        return $cleared;
    }

    /** Rate limiters, throttles, cached flags of deleted users. */
    private function clearCache(): bool
    {
        try {
            return Artisan::call('cache:clear') === 0;
        } catch (Throwable $e) {
            Log::warning('Game data reset: cache:clear failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
