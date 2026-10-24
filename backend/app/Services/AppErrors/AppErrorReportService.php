<?php

namespace App\Services\AppErrors;

use App\Enums\AdminAlertKind;
use App\Enums\AppErrorScope;
use App\Enums\DevicePlatform;
use App\Enums\UserRole;
use App\Models\AppErrorFingerprint;
use App\Models\AppErrorReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mobile app crash / render-error reports (M5-05, David 2026-10-19: our own
 * System health instead of Sentry — no third-party SDK, nothing leaves the
 * server).
 *
 *  - record(): scrub, fingerprint (name + normalised message + first frame),
 *    then three upserts in one transaction — fingerprint (+1), the
 *    (fingerprint, app version, platform) row (+1, +1 for the role, latest
 *    sample) and the hourly bucket (+1). Growth is bounded by distinct errors
 *    × versions × platforms, never by occurrences.
 *  - Times are the server's receive time; the device's `occurred_at` is only
 *    kept in the sample (queued offline reports arrive late, device clocks lie).
 *  - Only the role of the sending token is stored (parent | child | none) —
 *    never a user id, family, pet, name or e-mail (DECISIONS 2026-10-24).
 *  - Returns which Discord alert (if any) the caller should queue: a NEW
 *    fingerprint → `app_error_new`; the hourly bucket reaching
 *    `spike_per_hour` → `app_error_spike`. The once-per-day limit is the
 *    claim in claimAlert(), done by the job.
 */
class AppErrorReportService
{
    /**
     * @param  array{scope: string, name: string, message: string, stack?: string|null, app_version: string, runtime_version?: string|null, update_id?: string|null, build?: string|null, platform: string, os_version?: string|null, route?: string|null, occurred_at: string, fatal?: bool|null}  $data
     */
    public function record(array $data, ?UserRole $role, ?Carbon $now = null): AppErrorRecordResult
    {
        $now = ($now ?? Carbon::now())->copy()->utc();
        $scope = AppErrorScope::from($data['scope']);
        $platform = DevicePlatform::from($data['platform']);

        $name = mb_substr(trim($data['name']) !== '' ? trim($data['name']) : 'Error', 0, 100);
        $message = AppErrorText::message($data['message']);
        $stack = AppErrorText::stack($data['stack'] ?? null);
        $firstFrame = AppErrorText::firstFrame($stack);
        $fingerprint = AppErrorText::fingerprint($name, $message, $firstFrame);
        $hour = $now->copy()->startOfHour();

        $sample = [
            'scope' => $scope->value,
            'name' => $name,
            'message' => $message,
            'stack' => $stack,
            'fatal' => (bool) ($data['fatal'] ?? false),
            'app_version' => $data['app_version'],
            'runtime_version' => $data['runtime_version'] ?? null,
            'update_id' => $data['update_id'] ?? null,
            'build' => $data['build'] ?? null,
            'platform' => $platform->value,
            'os_version' => $data['os_version'] ?? null,
            'route' => $data['route'] ?? null,
            'occurred_at' => Carbon::parse($data['occurred_at'])->utc()->toIso8601String(),
            'received_at' => $now->toIso8601String(),
            'role' => $role?->value,
        ];

        $roleColumn = match ($role) {
            UserRole::Parent => 'parent_count',
            UserRole::Child => 'child_count',
            null => 'anonymous_count',
        };

        return DB::transaction(function () use ($fingerprint, $scope, $name, $message, $firstFrame, $now, $hour, $data, $platform, $sample, $roleColumn): AppErrorRecordResult {
            $row = DB::selectOne(
                'INSERT INTO app_error_fingerprints (fingerprint, scope, error_name, message, first_frame, occurrences, first_seen_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?)
                 ON CONFLICT (fingerprint) DO UPDATE
                    SET occurrences = app_error_fingerprints.occurrences + 1,
                        last_seen_at = GREATEST(app_error_fingerprints.last_seen_at, EXCLUDED.last_seen_at)
                 RETURNING (xmax = 0) AS inserted',
                [$fingerprint, $scope->value, $name, $message, $firstFrame, $now, $now],
            );

            DB::statement(
                "INSERT INTO app_error_reports (fingerprint, app_version, platform, occurrences, {$roleColumn}, sample, first_seen_at, last_seen_at)
                 VALUES (?, ?, ?, 1, 1, ?::jsonb, ?, ?)
                 ON CONFLICT (fingerprint, app_version, platform) DO UPDATE
                    SET occurrences = app_error_reports.occurrences + 1,
                        {$roleColumn} = app_error_reports.{$roleColumn} + 1,
                        sample = EXCLUDED.sample,
                        last_seen_at = GREATEST(app_error_reports.last_seen_at, EXCLUDED.last_seen_at)",
                [$fingerprint, $data['app_version'], $platform->value, json_encode($sample, JSON_THROW_ON_ERROR), $now, $now],
            );

            $bucket = DB::selectOne(
                'INSERT INTO app_error_hours (fingerprint, hour, occurrences) VALUES (?, ?, 1)
                 ON CONFLICT (fingerprint, hour) DO UPDATE SET occurrences = app_error_hours.occurrences + 1
                 RETURNING occurrences',
                [$fingerprint, $hour],
            );

            $isNew = (bool) ($row->inserted ?? false);
            $alert = match (true) {
                $isNew => AdminAlertKind::AppErrorNew,
                (int) ($bucket->occurrences ?? 0) === (int) config('system_health.app_errors.spike_per_hour') => AdminAlertKind::AppErrorSpike,
                default => null,
            };

            return new AppErrorRecordResult($fingerprint, $isNew, $alert);
        });
    }

    /**
     * Atomic Discord claim: true for at most one caller per fingerprint per
     * `alert_cooldown_hours`. A claimed alert is never released (a failed post
     * is reported, not repeated — same rule as the AI budget alerts).
     */
    public function claimAlert(string $fingerprint, ?Carbon $now = null): bool
    {
        $now = ($now ?? Carbon::now())->copy()->utc();
        $cutoff = $now->copy()->subHours((int) config('system_health.app_errors.alert_cooldown_hours'));

        return AppErrorFingerprint::query()
            ->whereKey($fingerprint)
            ->where(fn ($q) => $q->whereNull('last_alerted_at')->orWhere('last_alerted_at', '<=', $cutoff))
            ->update(['last_alerted_at' => $now]) === 1;
    }

    /** Occurrences per fingerprint in the last `$hours` hours. */
    public function countSince(string $fingerprint, int $hours, ?Carbon $now = null): int
    {
        return (int) DB::table('app_error_hours')
            ->where('fingerprint', $fingerprint)
            ->where('hour', '>=', self::windowStart($hours, $now))
            ->sum('occurrences');
    }

    /**
     * Occurrences and distinct fingerprints in the last `$hours` hours (System health tile).
     *
     * @return array{occurrences: int, fingerprints: int}
     */
    public function windowSummary(int $hours, ?Carbon $now = null): array
    {
        $row = DB::table('app_error_hours')
            ->where('hour', '>=', self::windowStart($hours, $now))
            ->selectRaw('COALESCE(SUM(occurrences), 0) AS occurrences, COUNT(DISTINCT fingerprint) AS fingerprints')
            ->first();

        return ['occurrences' => (int) ($row->occurrences ?? 0), 'fingerprints' => (int) ($row->fingerprints ?? 0)];
    }

    /** First hourly bucket inside a window of `$hours` hours ending now. */
    public static function windowStart(int $hours, ?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now())->copy()->utc()->subHours($hours)->startOfHour();
    }

    /**
     * Delete everything not seen for `retention_days` (default 30).
     *
     * @return array{fingerprints: int, reports: int, hours: int}
     */
    public function prune(?Carbon $now = null): array
    {
        $cutoff = ($now ?? Carbon::now())->copy()->utc()->subDays((int) config('system_health.app_errors.retention_days'));

        $hours = DB::table('app_error_hours')->where('hour', '<', $cutoff)->delete();
        $reports = AppErrorReport::query()->where('last_seen_at', '<', $cutoff)->delete();
        // Cascades to whatever per-version rows / buckets are left.
        $fingerprints = AppErrorFingerprint::query()->where('last_seen_at', '<', $cutoff)->delete();

        return ['fingerprints' => $fingerprints, 'reports' => $reports, 'hours' => $hours];
    }
}
