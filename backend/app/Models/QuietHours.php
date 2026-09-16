<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * @param  \Illuminate\Support\Carbon|null  $now  The time to check (defaults to now).
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
