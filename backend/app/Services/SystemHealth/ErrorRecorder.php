<?php

namespace App\Services\SystemHealth;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Stores production errors in `system_errors` (System health, David
 * 2026-10-19 — the superadmin page instead of Sentry / Grafana).
 *
 * Fed by the exception handler's report callback (every reported exception:
 * uncaught HTTP / command / job errors and every `report($e)`) and by
 * error-level log lines (MessageLogged). One row per fingerprint =
 * sha256(exception class | normalised message | first app file:line);
 * a repeat increments `occurrences`, moves `last_seen_at` and reopens a
 * resolved row.
 *
 * Safety rules — this runs inside the error path, so it must never make
 * things worse:
 *  - never throws (everything is caught) and never re-enters itself;
 *  - skips errors that mean the database is unreachable, and pauses for a
 *    minute (per process) after a failed write;
 *  - at most one DB write per fingerprint per minute (cache); occurrences in
 *    between are counted in the cache and added on the next write;
 *  - writes through its own connection (a clone of the default one), so a
 *    rolled-back or aborted transaction of the caller cannot lose or poison
 *    the row;
 *  - exceptions with their own report() method that returns non-false
 *    (Laravel then skips every report callback) are not captured;
 *  - no personal data: no request bodies, headers, query strings, names or
 *    e-mails — at most the user id + role. Messages are scrubbed
 *    (ErrorText::scrub); a QueryException keeps its SQL with placeholders,
 *    never the bindings or Postgres' "DETAIL: Failing row contains (…)".
 */
class ErrorRecorder
{
    use DetectsLostConnections;

    public const CONNECTION = 'system_health';

    private const WINDOW_KEY = 'system_health:w:';

    private const PENDING_KEY = 'system_health:p:';

    private bool $recording = false;

    private ?int $pausedUntil = null;

    /** Fallback rate limit when the cache itself fails. @var array<string, int> */
    private array $localWindow = [];

    private ?string $currentJob = null;

    private bool $connectionPrepared = false;

    private ?int $cacheDownUntil = null;

    private ?string $currentCommand = null;

    /**
     * Dependencies are resolved lazily (inside the guarded code): building
     * the recorder must never touch the cache or the DB, so a broken / mocked
     * cache store cannot make the error path itself fail.
     */
    public function __construct(
        private ?DatabaseManager $db = null,
        private ?CacheRepository $cache = null,
    ) {}

    private function cache(): CacheRepository
    {
        return $this->cache ??= app(CacheRepository::class);
    }

    private function db(): DatabaseManager
    {
        return $this->db ??= app(DatabaseManager::class);
    }

    public function jobStarted(string $name): void
    {
        $this->currentJob = $name;
    }

    public function jobEnded(): void
    {
        $this->currentJob = null;
    }

    public function commandStarted(?string $name): void
    {
        $this->currentCommand = $name;
    }

    /** Exception handler hook. */
    public function recordException(Throwable $e): void
    {
        $this->guarded(function () use ($e): void {
            if ($this->isDatabaseUnavailable($e)) {
                return;
            }

            $max = (int) config('system_health.message_max_chars', 1000);
            [$file, $line] = $this->origin($e);
            $message = ErrorText::scrub($this->rawMessage($e), $max);
            $class = $e::class;

            $this->store(
                fingerprint: hash('sha256', $class.'|'.ErrorText::normalise($message).'|'.$file.':'.$line),
                level: 'error',
                class: $class,
                message: $message,
                file: $file,
                line: $line,
                trace: $this->trace($e),
                context: $this->context($e),
            );
        });
    }

    /**
     * Error-level log line (Log::error / critical …) — those without an
     * exception in the context; the handler logs reported exceptions itself
     * and they are recorded by recordException().
     *
     * @param  array<string, mixed>  $context
     */
    public function recordLog(string $level, string $message, array $context): void
    {
        if (! in_array($level, (array) config('system_health.capture_log_levels', []), true)
            || (($context['exception'] ?? null) instanceof Throwable)) {
            return;
        }

        $this->guarded(function () use ($level, $message, $context): void {
            $max = (int) config('system_health.message_max_chars', 1000);
            $clean = ErrorText::scrub($message, $max);

            // Only integer ids from the log context (pet_id, pet_media_id …): never strings.
            $ids = [];
            foreach ($context as $key => $value) {
                if (is_string($key) && str_ends_with($key, '_id') && is_int($value)) {
                    $ids[$key] = $value;
                }
            }

            $this->store(
                fingerprint: hash('sha256', 'log:'.$level.'|'.ErrorText::normalise($clean)),
                level: $level,
                class: null,
                message: $clean,
                file: null,
                line: null,
                trace: null,
                context: $this->context(null) + ($ids === [] ? [] : ['ids' => $ids]),
                sourceOverride: 'log',
            );
        });
    }

    /** Forget the write window of a fingerprint (after "Mark resolved", so a repeat reopens at once). */
    public function forgetWindow(string $fingerprint): void
    {
        try {
            $this->cache()->forget(self::WINDOW_KEY.$fingerprint);
            unset($this->localWindow[$fingerprint]);
        } catch (Throwable) {
            // best effort
        }
    }

    // ──────────────────────────────────────────────────────────────

    private function guarded(callable $fn): void
    {
        if ($this->recording || ! config('system_health.capture_enabled', true)) {
            return;
        }
        if ($this->pausedUntil !== null && time() < $this->pausedUntil) {
            return;
        }

        $this->recording = true;
        try {
            $fn();
        } catch (Throwable $failure) {
            $this->pausedUntil = time() + (int) config('system_health.pause_after_failure_seconds', 60);
            if (! app()->runningUnitTests()) {
                // Plain stderr: never back through the logger / handler.
                error_log('ErrorRecorder: could not record an error: '.ErrorText::scrub($failure->getMessage(), 300));
            }
        } finally {
            $this->recording = false;
        }
    }

    /**
     * @param  list<string>|null  $trace
     * @param  array<string, mixed>  $context
     */
    private function store(string $fingerprint, string $level, ?string $class, string $message, ?string $file, ?int $line, ?array $trace, array $context, ?string $sourceOverride = null): void
    {
        $count = $this->takeWriteSlot($fingerprint);
        if ($count === null) {
            return;
        }

        $now = Carbon::now()->utc();
        $source = $sourceOverride ?? $context['source'];
        unset($context['source']);
        $connection = $this->connection();

        $connection->transaction(function () use ($connection, $fingerprint, $level, $class, $message, $file, $line, $trace, $source, $context, $count, $now): void {
            $row = $connection->selectOne(
                'INSERT INTO system_errors (fingerprint, level, exception_class, message, file, line, trace, source, context, occurrences, first_seen_at, last_seen_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?::jsonb, ?, ?, ?, ?, ?)
                 ON CONFLICT (fingerprint) DO UPDATE SET
                    level = EXCLUDED.level,
                    message = EXCLUDED.message,
                    trace = EXCLUDED.trace,
                    source = EXCLUDED.source,
                    context = EXCLUDED.context,
                    occurrences = system_errors.occurrences + EXCLUDED.occurrences,
                    last_seen_at = EXCLUDED.last_seen_at,
                    resolved_at = NULL,
                    resolved_by = NULL,
                    updated_at = EXCLUDED.updated_at
                 RETURNING id',
                [
                    $fingerprint, $level, $class, $message, $file, $line,
                    $trace === null ? null : json_encode($trace, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                    $source,
                    json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                    $count, $now, $now, $now, $now,
                ],
                false,
            );

            $connection->statement(
                'INSERT INTO system_error_hours (system_error_id, hour, occurrences) VALUES (?, ?, ?)
                 ON CONFLICT (system_error_id, hour) DO UPDATE SET occurrences = system_error_hours.occurrences + EXCLUDED.occurrences',
                [$row->id, $now->copy()->startOfHour(), $count],
            );
        });
    }

    /**
     * The number of occurrences to write now (this one + those counted in the
     * cache since the last write), or null when this fingerprint was written
     * less than a window ago (then it is only counted).
     */
    private function takeWriteSlot(string $fingerprint): ?int
    {
        $window = max(1, (int) config('system_health.write_window_seconds', 60));
        if ($this->cacheDownUntil !== null && time() < $this->cacheDownUntil) {
            return $this->localSlot($fingerprint, $window);
        }

        try {
            if ($this->cache()->add(self::WINDOW_KEY.$fingerprint, 1, $window)) {
                $pending = (int) $this->cache()->pull(self::PENDING_KEY.$fingerprint, 0);

                return 1 + $pending;
            }

            if ($this->cache()->increment(self::PENDING_KEY.$fingerprint) === 1) {
                // A fresh counter: give it an expiry so a fingerprint that never repeats does not linger.
                $this->cache()->put(self::PENDING_KEY.$fingerprint, 1, 86400);
            }

            return null;
        } catch (Throwable) {
            // Cache (Redis) down: stop asking it for a while (each call could
            // wait for its timeout) and use a per-process window only.
            $this->cacheDownUntil = time() + 30;

            return $this->localSlot($fingerprint, $window);
        }
    }

    private function localSlot(string $fingerprint, int $window): ?int
    {
        $last = $this->localWindow[$fingerprint] ?? 0;
        if (time() - $last < $window) {
            return null;
        }
        $this->localWindow[$fingerprint] = time();

        return 1;
    }

    private function connection(): ConnectionInterface
    {
        if (! config('system_health.isolated_connection', true)) {
            return $this->db()->connection();
        }

        if (config('database.connections.'.self::CONNECTION) === null) {
            config(['database.connections.'.self::CONNECTION => config('database.connections.'.config('database.default'))]);
        }

        $connection = $this->db()->connection(self::CONNECTION);
        if (! $this->connectionPrepared) {
            // Never wait long on a lock or a slow DB inside the error path.
            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement("SET lock_timeout = '2s'");
                $connection->statement("SET statement_timeout = '3s'");
            }
            $this->connectionPrepared = true;
        }

        return $connection;
    }

    private function isDatabaseUnavailable(Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($this->causedByLostConnection($cur)) {
                return true;
            }
            if ($cur instanceof PDOException || $cur instanceof QueryException) {
                $state = (string) ($cur->errorInfo[0] ?? $cur->getCode());
                // 08xxx connection exceptions, 53300 too many connections, 57P0x shutdown.
                if (str_starts_with($state, '08') || $state === '53300' || str_starts_with($state, '57P0')) {
                    return true;
                }
            }
            if (str_contains($cur->getMessage(), 'Connection refused') || str_contains($cur->getMessage(), 'could not connect to server')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The message without values that may carry personal data. Laravel's
     * QueryException message contains the SQL with the bindings substituted,
     * and wrappers (`new XException($e->getMessage(), previous: $e)`,
     * ViewException …) copy it — so any QueryException in the chain wins:
     * its driver message (without DETAIL) + the SQL with `?` placeholders.
     * ErrorText::scrub() then cuts any remaining "(Connection: …, SQL: …)"
     * tail and quoted values.
     */
    private function rawMessage(Throwable $e): string
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof QueryException) {
                $driver = $cur->getPrevious()?->getMessage() ?? $cur->getMessage();
                // Postgres appends "DETAIL: Failing row contains (…)" / "Key (col)=(value)": row data.
                $driver = (string) preg_replace('/\s*DETAIL:.*$/s', '', $driver);
                $prefix = $cur === $e ? '' : class_basename($e).' ← ';

                return $prefix.$driver.' (SQL: '.$cur->getSql().')';
            }
        }

        return $e->getMessage() !== '' ? $e->getMessage() : '(no message)';
    }

    /**
     * File + line of the first application frame (relative path), so the
     * fingerprint is stable across release directories.
     *
     * @return array{0: string|null, 1: int|null}
     */
    private function origin(Throwable $e): array
    {
        if ($this->isAppFile($e->getFile())) {
            return [$this->relative($e->getFile()), $e->getLine()];
        }
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file'], $frame['line']) && $this->isAppFile($frame['file'])) {
                return [$this->relative($frame['file']), (int) $frame['line']];
            }
        }

        return [$this->relative($e->getFile()), $e->getLine()];
    }

    /**
     * Up to N frames, application frames only (all frames when there are none).
     *
     * @return list<string>
     */
    private function trace(Throwable $e): array
    {
        $max = (int) config('system_health.trace_frames', 15);
        $frames = [$this->relative($e->getFile()).':'.$e->getLine()];
        $all = [];

        foreach ($e->getTrace() as $frame) {
            $where = isset($frame['file']) ? $this->relative($frame['file']).':'.($frame['line'] ?? '?') : '[internal]';
            $call = isset($frame['class']) ? $frame['class'].($frame['type'] ?? '::').$frame['function'] : ($frame['function'] ?? '');
            $text = $where.' '.$call.'()';
            $all[] = $text;
            if (isset($frame['file']) && $this->isAppFile($frame['file'])) {
                $frames[] = $text;
            }
        }

        if (count($frames) === 1) {
            $frames = array_merge($frames, $all);
        }

        return array_slice($frames, 0, $max);
    }

    /**
     * Where the error happened — never the request input.
     *
     * @return array<string, mixed>
     */
    private function context(?Throwable $e): array
    {
        $context = [];
        /** @var Request|null $request */
        $request = app()->bound('request') ? app('request') : null;
        $route = $request?->route();
        $isHttp = $request !== null && (is_object($route) || ! app()->runningInConsole());

        if ($isHttp) {
            $context = [
                'source' => 'http',
                'method' => $request->getMethod(),
                // The route name / pattern (`api/parent/pets/{pet}`), never the concrete URL or query string.
                'route' => is_object($route) ? ($route->getName() ?? $route->uri()) : '(no route)',
            ];
            if ($e instanceof HttpExceptionInterface) {
                $context['status'] = $e->getStatusCode();
            }
            if ($this->currentJob !== null) {
                $context['job'] = $this->currentJob;
            }
        } elseif ($this->currentJob !== null) {
            $context = ['source' => 'job', 'job' => $this->currentJob];
        } else {
            $command = $this->currentCommand ?? ($_SERVER['argv'][1] ?? null);
            $context = $command !== null
                ? ['source' => 'command', 'command' => (string) $command]
                : ['source' => 'other'];
        }

        $user = $this->resolvedUser();
        if ($user !== null) {
            $context['user_id'] = $user->id;
            $context['role'] = $user->role?->value;
        }

        return $context;
    }

    /** The authenticated user only if a guard already loaded it (no query on the error path). */
    private function resolvedUser(): ?User
    {
        try {
            $auth = app('auth');
            if (! $auth->hasResolvedGuards()) {
                return null;
            }
            foreach (array_keys((array) config('auth.guards', [])) as $name) {
                $guard = $auth->guard($name);
                if (method_exists($guard, 'hasUser') && $guard->hasUser()) {
                    $user = $guard->user();

                    return $user instanceof User ? $user : null;
                }
            }
        } catch (Throwable) {
            // ignore
        }

        return null;
    }

    private function isAppFile(string $file): bool
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($file, $base) && ! str_starts_with($file, $base.'vendor'.DIRECTORY_SEPARATOR);
    }

    private function relative(string $file): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return mb_substr(str_starts_with($file, $base) ? substr($file, strlen($base)) : $file, 0, 500);
    }
}
