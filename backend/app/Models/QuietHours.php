<?php

namespace App\Models;

use App\Services\FamilyService;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class QuietHours extends Model
{
    /**
     * The quiet hours every family has until a parent saves its own
     * (fix/quiet-hours-default, confirmed by David 2026-10-08 08:54 — PRODUCT_SPEC §5): night 21:00–07:00
     * family-local, no school window, active. The single source for the
     * row FamilyService creates with a family, the data migration that
     * backfilled families without a row, and the in-code fallback when no
     * row can exist (family without a parent). The parent app shows the same
     * values. Change it here only, together with PRODUCT_SPEC §5.
     */
    public const DEFAULTS = [
        'school_start' => null,
        'school_end' => null,
        'bedtime_start' => '21:00',
        'bedtime_end' => '07:00',
        'is_active' => true,
    ];

    /**
     * Fallback when a family has no row at all: the defaults, unsaved, read
     * in the family timezone. Never saved from here (a row needs a parent_id).
     */
    public static function defaultFor(?Family $family): self
    {
        $quietHours = new self(self::DEFAULTS);
        if ($family !== null) {
            $quietHours->family_id = $family->id;
            $quietHours->setRelation('family', $family);
        }

        return $quietHours;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'family_id',
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
     * Quiet hours belong to the family (M2-01); parent_id is the parent who
     * created them. Code that sets only parent_id gets that parent's family.
     *
     * Trap (2026-10-08): since every family gets a default row when its first
     * parent joins (FamilyService::ensureDefaultQuietHours), a plain
     * QuietHours::create(['parent_id' => …]) for an existing parent now hits
     * the unique keys (parent_id, family_id). Update the family's row instead
     * (QuietHoursController::update; tests: setQuietHours()).
     */
    protected static function booted(): void
    {
        static::creating(function (QuietHours $quietHours): void {
            if ($quietHours->family_id === null && $quietHours->parent_id !== null) {
                $parent = User::find($quietHours->parent_id);
                if ($parent !== null) {
                    $quietHours->family_id = app(FamilyService::class)->ensureFamilyFor($parent)->id;
                }
            }
        });
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * The parent who created this quiet hours configuration.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    // ──────────────────────────────────────────────────────────────
    //  Quiet Hours Checking
    // ──────────────────────────────────────────────────────────────

    /**
     * The timezone the windows are defined in: the family timezone.
     */
    public function timezone(): string
    {
        return $this->family?->timezone ?? $this->parent?->timezone ?? User::DEFAULT_TIMEZONE;
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
     * Split [from, to) into consecutive segments that are entirely quiet or
     * entirely normal, walking from boundary to boundary (≤ 4 per day plus
     * DST transitions). A null or inactive schedule yields one normal segment.
     *
     * @return list<array{0: Carbon, 1: Carbon, 2: bool}> [start, end, isQuiet]
     */
    public static function segmentsBetween(?self $quietHours, CarbonInterface $from, CarbonInterface $to): array
    {
        $cursor = Carbon::instance($from);
        $end = Carbon::instance($to);

        if ($cursor->greaterThanOrEqualTo($end)) {
            return [];
        }

        if (! $quietHours || ! $quietHours->is_active) {
            return [[$cursor, $end, false]];
        }

        $segments = [];
        while ($cursor->lessThan($end)) {
            $next = $quietHours->nextBoundaryAfter($cursor);
            $next = ($next === null || $next->greaterThan($end)) ? $end->copy() : Carbon::instance($next);

            $segments[] = [$cursor, $next, $quietHours->isQuietNow($cursor)];
            $cursor = $next;
        }

        return $segments;
    }

    /**
     * Seconds of [from, to) outside and inside quiet hours.
     *
     * @return array{normal: float, quiet: float}
     */
    public static function splitSecondsBetween(?self $quietHours, CarbonInterface $from, CarbonInterface $to): array
    {
        $normal = 0.0;
        $quiet = 0.0;

        foreach (self::segmentsBetween($quietHours, $from, $to) as [$start, $end, $isQuiet]) {
            $seconds = (float) $start->diffInSeconds($end, false);
            if ($isQuiet) {
                $quiet += $seconds;
            } else {
                $normal += $seconds;
            }
        }

        return ['normal' => $normal, 'quiet' => $quiet];
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
