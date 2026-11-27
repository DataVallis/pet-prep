<?php

namespace App\Services\SystemHealth;

use App\Filament\Widgets\SystemHealth\AppErrorsTable;
use App\Models\AppErrorFingerprint;
use App\Models\AppErrorReport;
use App\Models\FailedJob;
use App\Models\SystemError;
use Illuminate\Support\Carbon;

/**
 * »Export open errors« on /admin/system-health (David 2026-11-27): one
 * Markdown file with everything needed to fix what is open — every OPEN
 * `system_errors` row in full (message, file:line, context, app trace), the
 * mobile app errors of the last 7 days (latest sample + stack) and the
 * failed jobs — so David can hand the whole file to a developer / AI agent
 * instead of opening each row.
 *
 * Read-only. It only prints what the page already shows a superadmin: the
 * stored values are scrubbed at capture (ErrorRecorder / AppErrorReportService);
 * a failed job's raw exception text is NOT stored scrubbed, so only its
 * scrubbed first line is exported — never the payload or the full text.
 */
class ErrorReportExport
{
    /** Hard caps so the file stays small enough to read and attach. */
    public const MAX_ERRORS = 500;

    public const MAX_APP_ERRORS = 100;

    public const MAX_FAILED_JOBS = 100;

    public const APP_ERROR_WINDOW_HOURS = 168;

    private const TIMEZONE = 'Europe/Ljubljana';

    /** A fence longer than any run of backticks an error message could reasonably hold. */
    private const FENCE = '````';

    public function filename(?Carbon $now = null): string
    {
        return 'petprep-open-errors-'.($now ?? Carbon::now())->copy()->timezone(self::TIMEZONE)->format('Y-m-d-Hi').'.md';
    }

    public function markdown(?Carbon $now = null): string
    {
        $now = ($now ?? Carbon::now())->copy();

        $openTotal = SystemError::open()->count();
        $errors = SystemError::open()
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ERRORS)
            ->get();

        $appErrors = AppErrorsTable::applyWindow(AppErrorFingerprint::query()->with('reports'), self::APP_ERROR_WINDOW_HOURS)
            ->orderByDesc('window_count')
            ->orderByDesc('last_seen_at')
            ->limit(self::MAX_APP_ERRORS)
            ->get();

        $failedTotal = FailedJob::query()->count();
        $failedJobs = FailedJob::query()
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit(self::MAX_FAILED_JOBS)
            ->get();

        $lines = [
            '# PetPrep — open errors',
            '',
            '- Exported: '.$this->time($now).' ('.self::TIMEZONE.')',
            '- Environment: '.app()->environment(),
            '- Open server errors: '.$openTotal.($openTotal > $errors->count() ? ' (the '.$errors->count().' most recent are listed)' : ''),
            '- App errors seen in the last 7 days: '.$appErrors->count(),
            '- Failed jobs: '.$failedTotal.($failedTotal > $failedJobs->count() ? ' (the '.$failedJobs->count().' most recent are listed)' : ''),
            '',
            'All times are '.self::TIMEZONE.'. Messages are scrubbed at capture (e-mails, tokens, IPs and quoted values are replaced, e.g. `{s}`); traces hold app frames only.',
            '',
            '## Server errors (open)',
            '',
        ];

        if ($errors->isEmpty()) {
            $lines[] = 'None.';
            $lines[] = '';
        }
        foreach ($errors as $i => $error) {
            array_push($lines, ...$this->serverError($i + 1, $error));
        }

        $lines[] = '## App errors (mobile app, last 7 days)';
        $lines[] = '';
        if ($appErrors->isEmpty()) {
            $lines[] = 'None.';
            $lines[] = '';
        }
        foreach ($appErrors as $i => $appError) {
            array_push($lines, ...$this->appError($i + 1, $appError));
        }

        $lines[] = '## Failed jobs';
        $lines[] = '';
        if ($failedJobs->isEmpty()) {
            $lines[] = 'None.';
            $lines[] = '';
        }
        foreach ($failedJobs as $job) {
            $lines[] = '- '.$this->time($job->failed_at).' · `'.$this->inline($job->displayName()).'` · queue `'.$this->inline($job->queue).'` — '
                .$this->inline(ErrorText::scrub($job->exceptionHeadline(), 300));
        }
        if ($failedJobs->isNotEmpty()) {
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function serverError(int $number, SystemError $error): array
    {
        $title = $error->exception_class !== null ? class_basename($error->exception_class) : strtoupper($error->level);
        $context = $error->context ?? [];

        $lines = [
            "### {$number}. {$title} — ".$this->inline(mb_substr($error->message, 0, 120)),
            '',
            '- Count: '.$error->occurrences,
            '- First seen: '.$this->time($error->first_seen_at),
            '- Last seen: '.$this->time($error->last_seen_at),
            '- Where: '.$error->source.($error->contextSummary() !== '' ? ' · `'.$this->inline($error->contextSummary()).'`' : ''),
            '- Level: '.$error->level,
        ];
        if ($error->exception_class !== null) {
            $lines[] = '- Exception: `'.$error->exception_class.'`';
        }
        if ($error->file !== null) {
            $lines[] = '- File: `'.$error->file.':'.$error->line.'`';
        }
        if (! empty($context['user_id'])) {
            $lines[] = '- User: #'.$context['user_id'].' ('.($context['role'] ?? '?').')';
        }
        if (! empty($context['ids'])) {
            $lines[] = '- Record ids: `'.$this->inline((string) json_encode($context['ids'])).'`';
        }
        $lines[] = '- Fingerprint: `'.substr($error->fingerprint, 0, 16).'`';
        $lines[] = '';
        array_push($lines, ...$this->block('Message', $error->message));

        if (! empty($error->trace)) {
            $trace = [];
            foreach (array_values($error->trace) as $i => $frame) {
                $trace[] = "#{$i} {$frame}";
            }
            array_push($lines, ...$this->block('Stack trace (app frames)', implode("\n", $trace)));
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function appError(int $number, AppErrorFingerprint $error): array
    {
        $reports = $error->reports->sortByDesc('last_seen_at')->values();
        /** @var AppErrorReport|null $latest */
        $latest = $reports->first();

        $lines = [
            "### {$number}. ".$this->inline($error->error_name).' — '.$this->inline(mb_substr($error->message, 0, 120)),
            '',
            '- Scope: '.$error->scope,
            '- Count (7 days): '.(int) $error->window_count.' · total: '.$error->occurrences,
            '- First seen: '.$this->time($error->first_seen_at),
            '- Last seen: '.$this->time($error->last_seen_at),
            '- Fingerprint: `'.substr($error->fingerprint, 0, 16).'`',
        ];
        foreach ($reports as $report) {
            $lines[] = '- Version `'.$this->inline($report->app_version).'` · '.$report->platform.' '.$this->inline((string) ($report->sample['os_version'] ?? ''))
                .' · count '.$report->occurrences.' · parent / child / signed out: '.AppErrorsTable::roles($report)
                .' · last seen '.$this->time($report->last_seen_at);
        }
        if ($latest !== null) {
            $sample = [];
            foreach (['route', 'fatal', 'runtime_version', 'update_id', 'build', 'role', 'occurred_at'] as $key) {
                $value = $latest->sample[$key] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $sample[] = $key.' `'.$this->inline(is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : (string) json_encode($value))).'`';
            }
            if ($sample !== []) {
                $lines[] = '- Latest sample: '.implode(' · ', $sample);
            }
        }
        $lines[] = '';
        array_push($lines, ...$this->block('Message', $error->message));

        $stack = $latest?->sample['stack'] ?? null;
        if (is_string($stack) && $stack !== '') {
            array_push($lines, ...$this->block('Stack (latest sample)', $stack));
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function block(string $label, string $text): array
    {
        // A stored text can never close the fence early.
        $text = str_replace(self::FENCE, "'''", $text);

        return [$label.':', '', self::FENCE.'text', $text, self::FENCE, ''];
    }

    /** One-line text safe inside a heading, list item or `code` span. */
    private function inline(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace('`', "'", $text)));
    }

    private function time(Carbon $at): string
    {
        return $at->copy()->timezone(self::TIMEZONE)->format('Y-m-d H:i:s');
    }
}
