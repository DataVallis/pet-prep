<?php

namespace App\Services\SystemHealth;

use App\Filament\Resources\PetResource;
use App\Models\FailedJob;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\SystemError;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read model + admin actions of /admin/system-health (David 2026-10-19):
 * status numbers, the errors-per-hour series, resolving errors, retrying /
 * forgetting failed jobs, the scheduler heartbeat and the nightly prune.
 */
class SystemHealthService
{
    public const HEARTBEAT_KEY = 'system_health:scheduler_heartbeat';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ErrorRecorder $recorder,
    ) {}

    // ── Scheduler heartbeat ─────────────────────────────────────

    /** Written at the end of every `pets:process-decay` tick (minutely). */
    public function beat(?Carbon $now = null): void
    {
        try {
            $this->cache->forever(self::HEARTBEAT_KEY, ($now ?? Carbon::now())->utc()->toIso8601String());
        } catch (Throwable) {
            // The heartbeat must never break the game loop.
        }
    }

    public function lastHeartbeat(): ?Carbon
    {
        try {
            $value = $this->cache->get(self::HEARTBEAT_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_string($value) ? Carbon::parse($value)->utc() : null;
    }

    public function heartbeatIsStale(): bool
    {
        $last = $this->lastHeartbeat();

        return $last === null || $last->lt(Carbon::now()->subMinutes((int) config('system_health.heartbeat_stale_minutes', 5)));
    }

    // ── Errors ──────────────────────────────────────────────────

    /** Open errors seen in the last $hours. */
    public function openErrorCount(int $hours): int
    {
        return SystemError::open()->where('last_seen_at', '>=', Carbon::now()->subHours($hours))->count();
    }

    /**
     * Occurrences per hour for the last $hours hours (oldest first), keyed by
     * the UTC hour start.
     *
     * @return array<string, int>
     */
    public function occurrencesPerHour(int $hours = 48): array
    {
        $end = Carbon::now()->utc()->startOfHour();
        $start = $end->copy()->subHours($hours - 1);

        $rows = DB::table('system_error_hours')
            ->where('hour', '>=', $start)
            ->selectRaw('hour, SUM(occurrences) AS n')
            ->groupBy('hour')
            ->pluck('n', 'hour')
            ->mapWithKeys(fn ($n, $hour) => [Carbon::parse($hour, 'UTC')->toIso8601String() => (int) $n]);

        $series = [];
        for ($h = $start->copy(); $h->lte($end); $h->addHour()) {
            $series[$h->toIso8601String()] = $rows[$h->toIso8601String()] ?? 0;
        }

        return $series;
    }

    /**
     * @param  iterable<SystemError>  $errors
     */
    public function resolve(iterable $errors, User $by): int
    {
        $count = 0;
        foreach ($errors as $error) {
            if ($error->resolved_at !== null) {
                continue;
            }
            $error->forceFill(['resolved_at' => Carbon::now(), 'resolved_by' => $by->id])->save();
            // A repeat right after resolving must reopen at once, not wait for the write window.
            $this->recorder->forgetWindow($error->fingerprint);
            $count++;
        }

        return $count;
    }

    /**
     * Resolved errors older than 30 days and every error older than 90 days
     * (by last_seen_at); hourly buckets older than 14 days.
     *
     * @return array{errors: int, hours: int}
     */
    public function prune(?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now())->copy()->utc();
        $retention = (array) config('system_health.retention');

        $errors = SystemError::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNotNull('resolved_at')->where('resolved_at', '<', $now->copy()->subDays((int) $retention['resolved_days'])))
                ->orWhere('last_seen_at', '<', $now->copy()->subDays((int) $retention['any_days'])))
            ->delete();

        $hours = DB::table('system_error_hours')
            ->where('hour', '<', $now->copy()->subDays((int) $retention['hourly_buckets_days']))
            ->delete();

        return ['errors' => $errors, 'hours' => $hours];
    }

    // ── Failed jobs ─────────────────────────────────────────────

    public function failedJobCount(?int $hours = null): int
    {
        $query = FailedJob::query();
        if ($hours !== null) {
            $query->where('failed_at', '>=', Carbon::now()->subHours($hours));
        }

        return $query->count();
    }

    /**
     * Push the failed job back onto its queue (= `queue:retry {uuid}`).
     * The row is claimed first (forget() deletes it and says whether it
     * existed), so a double click or two admins never push it twice (a
     * second fal.ai job = a second AI cost). Returns false when nothing
     * was pushed.
     */
    public function retryFailedJob(FailedJob $job): bool
    {
        /** @var FailedJobProviderInterface $failer */
        $failer = app('queue.failer');
        $record = $failer->find($job->uuid);
        if ($record === null) {
            return false;
        }

        $payload = json_decode((string) $record->payload, true);
        if (! is_array($payload)) {
            return false;
        }

        if (! $failer->forget($job->uuid)) {
            return false; // somebody else claimed it
        }

        if (isset($payload['attempts'])) {
            $payload['attempts'] = 0;
        }
        $payload = $this->refreshRetryUntil($payload);

        try {
            app(QueueManager::class)->connection($record->connection)->pushRaw((string) json_encode($payload), $record->queue);
        } catch (Throwable $e) {
            // Put it back so it is not lost, then surface the error.
            try {
                // @phpstan-ignore argument.type (the database providers store the exception as text)
                $failer->log($record->connection, $record->queue, (string) $record->payload, (string) $record->exception);
            } catch (Throwable) {
                // best effort
            }
            throw $e;
        }

        return true;
    }

    /**
     * Like `queue:retry`: a job with retryUntil() gets a fresh deadline.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function refreshRetryUntil(array $payload): array
    {
        $command = $payload['data']['command'] ?? null;
        if (! is_string($command)) {
            return $payload;
        }

        try {
            $instance = str_starts_with($command, 'O:')
                ? unserialize($command)
                : unserialize(app(Encrypter::class)->decrypt($command));
        } catch (Throwable) {
            return $payload;
        }

        if (is_object($instance) && ! $instance instanceof \__PHP_Incomplete_Class && method_exists($instance, 'retryUntil')) {
            $until = $instance->retryUntil();
            $payload['retryUntil'] = $until instanceof DateTimeInterface ? $until->getTimestamp() : $until;
        }

        return $payload;
    }

    public function forgetFailedJob(FailedJob $job): void
    {
        app('queue.failer')->forget($job->uuid);
    }

    // ── Other status numbers ────────────────────────────────────

    /**
     * Pending jobs per queue the workers listen on; null when the driver
     * cannot tell (or is unreachable).
     *
     * @return array<string, int|null>
     */
    public function queueBacklog(): array
    {
        $sizes = [];
        foreach ((array) config('system_health.queues', []) as $queue) {
            try {
                $connection = app(QueueManager::class)->connection();
                $sizes[$queue] = method_exists($connection, 'size') && config('queue.default') !== 'sync'
                    ? (int) $connection->size($queue)
                    : null;
            } catch (Throwable) {
                $sizes[$queue] = null;
            }
        }

        return $sizes;
    }

    /**
     * Active born pets whose routine-ledger close is stuck: the close pointer
     * is more than N days behind, or the last close attempt failed and it is
     * waiting for a retry (`routines_next_close_at` in the future while days
     * are still open).
     *
     * @return Collection<int, int> pet ids
     */
    public function stuckLedgerPetIds(): Collection
    {
        $days = (int) config('system_health.stuck_ledger_days', 2);
        $cutoff = Carbon::now()->utc()->subDays($days)->toDateString();

        return Pet::born()
            ->where('is_active', true)
            ->where('born_at', '<', Carbon::now()->subDays($days))
            ->where(fn ($q) => $q->whereNull('routines_closed_through')->orWhere('routines_closed_through', '<', $cutoff))
            ->orderBy('id')
            ->limit(500)
            ->pluck('id');
    }

    public function failedMediaCount(int $hours = 24): int
    {
        return PetMedia::query()
            ->where('status', 'failed')
            ->where('updated_at', '>=', Carbon::now()->subHours($hours))
            ->count();
    }

    /**
     * Quality check (David 2026-10-23): AI clips waiting for a superadmin — failed the check
     * twice (»Needs review«) or flagged by media:quality-check. Pet slots and look rows; a pool
     * pet's slot that only mirrors its look row is not counted twice.
     */
    public function needsReviewMediaCount(): int
    {
        return PetMedia::query()->withheldByQuality()
            ->where(fn ($q) => $q->whereNull('look_media_id')->orWhereNull('pet_id'))
            ->count();
    }

    /** QA #363 m1: clips served UNCHECKED in the last $hours (ffmpeg missing / nothing measurable). */
    public function uncheckedMediaCount(int $hours = 24): int
    {
        return PetMedia::query()->where('quality', PetMedia::QUALITY_UNCHECKED)
            ->where(fn ($q) => $q->whereNull('look_media_id')->orWhereNull('pet_id'))
            ->where('updated_at', '>=', Carbon::now()->subHours($hours))
            ->count();
    }

    /** URL of the pet list filtered to pets with an AI clip that needs review (QA #363 m7). */
    public static function needsReviewUrl(): string
    {
        return PetResource::getUrl('index', ['tableFilters' => ['media_needs_review' => ['isActive' => true]]]);
    }

    /**
     * @return array{free: int, total: int, used_percent: float}|null
     */
    public function diskUsage(): ?array
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return null;
        }

        return [
            'free' => (int) $free,
            'total' => (int) $total,
            'used_percent' => round(100 * (1 - $free / $total), 1),
        ];
    }
}
