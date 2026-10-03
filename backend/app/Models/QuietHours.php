<?php

namespace App\Models;

use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
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
     * The timezone the windows are defined in: the family (parent's) timezone.
     */
    public function timezone(): string
    {
        return $this->parent?->timezone ?? User::DEFAULT_TIMEZONE;
    }

    /**
     * Determine if the given moment falls within any quiet period, reading
     * the wall clock in the family timezone (M1-03). Handles overnight wrap
     * (e.g., 22:00–06:00).
     *
     * On DST days the windows follow the local clock: 22:00–06:00 lasts 9 real
     * hours on the night clocks go back and 7 on the night they go forward.
     *
     * @param  CarbonInterface|null  $now  The moment to check (any timezone; defaults to now).
     */
    public function isQuietNow($now = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $local = Carbon::instance($now ?? now())->setTimezone($this->timezone());
        $currentTime = $local->format('H:i');

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
     * value (same local clock as isQuietNow), returned in $time's timezone.
     * Null when no window is configured.
     *
     * Candidates are every instant whose local wall-clock reading equals a
     * school/bedtime start or end, plus every DST transition (the local clock
     * jumps, which can cross a boundary). This is exact on DST days: a
     * boundary inside the spring-forward gap (02:00–03:00) takes effect at
     * the jump, and one inside the fall-back hour occurs twice.
     */
    public function nextBoundaryAfter(CarbonInterface $time): ?CarbonInterface
    {
        $boundaries = [];
        foreach ([$this->school_start, $this->school_end, $this->bedtime_start, $this->bedtime_end] as $boundary) {
            if ($boundary) {
                $boundaries[] = array_map('intval', explode(':', substr($boundary, 0, 5)));
            }
        }

        if ($boundaries === []) {
            return null;
        }

        $zone = new DateTimeZone($this->timezone());
        $from = $time->getTimestamp();
        $next = null;
        $consider = function (int $candidate) use (&$next, $from): void {
            if ($candidate > $from && ($next === null || $candidate < $next)) {
                $next = $candidate;
            }
        };

        // Offsets in effect around $time; the first entry is the state at the
        // range start, the rest are real transitions.
        $transitions = $zone->getTransitions($from - 2 * 86400, $from + 3 * 86400) ?: [];
        $offsets = array_values(array_unique(array_column($transitions, 'offset')));
        if ($offsets === []) {
            $offsets = [$zone->getOffset(new DateTimeImmutable('@'.$from))];
        }
        foreach (array_slice($transitions, 1) as $transition) {
            $consider((int) $transition['ts']);
        }

        $localDay = Carbon::createFromTimestamp($from, $zone)->startOfDay();
        foreach ([0, 1, 2] as $dayOffset) {
            $day = $localDay->copy()->addDays($dayOffset);
            foreach ($boundaries as [$hour, $minute]) {
                $wallClock = gmmktime($hour, $minute, 0, $day->month, $day->day, $day->year);
                foreach ($offsets as $offset) {
                    // Keep only instants that really read $hour:$minute locally
                    // (none in a DST gap, two in a DST overlap).
                    $instant = $wallClock - $offset;
                    $reads = (new DateTimeImmutable('@'.$instant))->setTimezone($zone);
                    if ((int) $reads->format('G') === $hour && (int) $reads->format('i') === $minute
                        && $reads->format('Y-m-d') === $day->toDateString()) {
                        $consider($instant);
                    }
                }
            }
        }

        return $next === null ? null : Carbon::createFromTimestamp($next)->setTimezone($time->getTimezone());
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
