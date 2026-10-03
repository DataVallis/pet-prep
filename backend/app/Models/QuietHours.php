<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class QuietHours extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'school_start',
        'school_end',
        'bedtime_start',
        'bedtime_end',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Relationships
    // ──────────────────────────────────────────────────────────────

    /**
     * The parent who owns this quiet hours configuration.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    // ──────────────────────────────────────────────────────────────
    //  Quiet Hours Checking
    // ──────────────────────────────────────────────────────────────

    /**
     * Determine if the current time falls within any quiet period.
     * Handles overnight wrap (e.g., 22:00–06:00).
     *
     * @param  Carbon|null  $now  The time to check (defaults to now).
     */
    public function isQuietNow($now = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = $now ?? now();
        $currentTime = $now->format('H:i');

        // Check school hours
        if ($this->isWithinTimeRange($currentTime, $this->school_start, $this->school_end)) {
            return true;
        }

        // Check bedtime hours
        if ($this->isWithinTimeRange($currentTime, $this->bedtime_start, $this->bedtime_end)) {
            return true;
        }

        return false;
    }

    /**
     * The first moment strictly after $time at which isQuietNow() can change
     * value: the next school/bedtime start or end (minute precision, same
     * clock as isQuietNow). Null when no window is configured.
     */
    public function nextBoundaryAfter(CarbonInterface $time): ?CarbonInterface
    {
        $next = null;

        foreach ([$this->school_start, $this->school_end, $this->bedtime_start, $this->bedtime_end] as $boundary) {
            if (! $boundary) {
                continue;
            }

            [$hour, $minute] = array_map('intval', explode(':', substr($boundary, 0, 5)));
            $candidate = $time->copy()->setTime($hour, $minute);
            if ($candidate->lessThanOrEqualTo($time)) {
                $candidate = $candidate->addDay();
            }

            if ($next === null || $candidate->lessThan($next)) {
                $next = $candidate;
            }
        }

        return $next;
    }

    /**
     * Check if a time string (H:i) falls within a range, handling overnight wrap.
     *
     * @param  string|null  $start  Start time (H:i) or null
     * @param  string|null  $end  End time (H:i) or null
     */
    private function isWithinTimeRange(string $time, ?string $start, ?string $end): bool
    {
        if (! $start || ! $end) {
            return false;
        }

        // Format times consistently (strip seconds if present)
        $time = substr($time, 0, 5);
        $start = substr($start, 0, 5);
        $end = substr($end, 0, 5);

        // Normal range (e.g., 08:00–13:00)
        if ($start <= $end) {
            return $time >= $start && $time < $end;
        }

        // Overnight wrap (e.g., 22:00–06:00)
        return $time >= $start || $time < $end;
    }
}
