<?php

namespace App\Services;

use App\Enums\HygieneEventStatus;
use App\Models\BreedConfig;
use App\Models\Pet;
use App\Models\PetHygieneEvent;
use App\Models\QuietHours;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Random hygiene events — "the dog made a mess" (M1-05, PRODUCT_SPEC §5).
 *
 * Scheduling: for every family-local day the pet lives through, we store
 * `breed_configs.poops_per_day` instants chosen at random (whole minutes)
 * from the day's time outside quiet hours. The day's non-quiet time is cut
 * into N equal shares and one instant is drawn from each share, so two events
 * never land back to back. The draw is deterministic per (salt, pet, date):
 * re-running the schedule gives the same times; the salt defaults to the app
 * key so clients can't predict them.
 *
 * Application: the decay tick applies a pending event once its time lies in
 * the interval it is decaying, (last_decay_at, now]. Events at or before
 * last_decay_at — before the pet existed, or during a freeze (hard stop,
 * illness; frozen ticks and thaw advance the decay clock past them) — and
 * events that fall into quiet hours changed after scheduling are skipped.
 * After a scheduler gap every missed event is applied exactly once (each row
 * flips from pending once).
 *
 * All callers hold the pet's row lock (backend/CLAUDE.md).
 */
class HygieneEventService
{
    /**
     * Never back-fill more than this many past local days after a long gap
     * (the net effect — hygiene at 0 — is the same).
     */
    public const MAX_CATCH_UP_DAYS = 7;

    public function __construct(private ?string $seedSalt = null)
    {
        $this->seedSalt ??= (string) config('app.key');
    }

    /**
     * Make sure events exist for every family-local day from the day of
     * $from up to the day of $now (attributes on $pet; the caller saves).
     */
    public function ensureScheduled(Pet $pet, CarbonInterface $from, CarbonInterface $now, ?QuietHours $quietHours, BreedConfig $breedConfig): void
    {
        $timezone = $pet->familyTimezone();
        $today = Carbon::parse($pet->localDate($now), $timezone);
        $day = Carbon::parse($pet->localDate($from), $timezone);

        $scheduledThrough = $pet->hygiene_scheduled_through;
        if ($scheduledThrough !== null) {
            $next = Carbon::parse($scheduledThrough, $timezone)->addDay();
            if ($next->greaterThan($day)) {
                $day = $next;
            }
        }

        $earliest = $today->copy()->subDays(self::MAX_CATCH_UP_DAYS);
        if ($day->lessThan($earliest)) {
            $day = $earliest;
        }

        if ($day->greaterThan($today)) {
            return;
        }

        $rows = [];
        $stamp = now();
        for (; $day->lessThanOrEqualTo($today); $day->addDay()) {
            $date = $day->toDateString();
            foreach ($this->scheduleDay($pet, $date, $quietHours, $breedConfig->poops_per_day) as $at) {
                $rows[] = [
                    'pet_id' => $pet->id,
                    'local_date' => $date,
                    'scheduled_at' => $at,
                    'status' => HygieneEventStatus::Pending->value,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];
            }
        }

        if ($rows !== []) {
            PetHygieneEvent::insertOrIgnore($rows);
        }

        $pet->hygiene_scheduled_through = $today->toDateString();
    }

    /**
     * The event instants (UTC, ascending) for one family-local day: $count
     * whole minutes drawn from the day's time outside quiet hours, one per
     * equal share. Fewer if the day has fewer non-quiet minutes; none if the
     * whole day is quiet.
     *
     * @return list<Carbon>
     */
    public function scheduleDay(Pet $pet, string $localDate, ?QuietHours $quietHours, int $count, ?Randomizer $randomizer = null): array
    {
        if ($count <= 0) {
            return [];
        }

        $timezone = $pet->familyTimezone();
        $dayStart = Carbon::parse($localDate, $timezone)->startOfDay();
        $dayEnd = $dayStart->copy()->addDay(); // next local midnight (23 / 25 h on DST days)

        // Non-quiet stretches of the day; all boundaries fall on whole minutes.
        $open = [];
        $totalMinutes = 0;
        foreach (QuietHours::segmentsBetween($quietHours, $dayStart->copy()->utc(), $dayEnd->copy()->utc()) as [$start, $end, $isQuiet]) {
            $minutes = intdiv((int) $start->diffInSeconds($end, false), 60);
            if (! $isQuiet && $minutes > 0) {
                $open[] = [$start, $minutes];
                $totalMinutes += $minutes;
            }
        }

        if ($totalMinutes === 0) {
            return [];
        }

        $count = min($count, $totalMinutes);
        $randomizer ??= $this->randomizerFor($pet, $localDate);

        $instants = [];
        for ($i = 0; $i < $count; $i++) {
            $low = intdiv($i * $totalMinutes, $count);
            $high = intdiv(($i + 1) * $totalMinutes, $count) - 1;
            $offset = $randomizer->getInt($low, $high);

            foreach ($open as [$start, $minutes]) {
                if ($offset < $minutes) {
                    $instants[] = $start->copy()->addMinutes($offset)->utc();
                    break;
                }
                $offset -= $minutes;
            }
        }

        return $instants;
    }

    /**
     * Apply pending events whose time has come (decay tick). Events in
     * ($from, $now] outside quiet hours happen; earlier ones are skipped.
     *
     * @return Carbon|null The earliest event that happened (hygiene is 0 from then on), or null.
     */
    public function applyDue(Pet $pet, CarbonInterface $from, CarbonInterface $now, ?QuietHours $quietHours): ?Carbon
    {
        $first = null;

        foreach ($this->duePending($pet, $now) as $event) {
            $happens = $event->scheduled_at->greaterThan($from)
                && ! ($quietHours?->isQuietNow($event->scheduled_at) ?? false);

            $event->forceFill([
                'status' => $happens ? HygieneEventStatus::Applied : HygieneEventStatus::Skipped,
                'resolved_at' => $now,
            ])->save();

            if ($happens) {
                $first ??= $event->scheduled_at->copy();
                Log::info('HygieneEventService: hygiene event happened', [
                    'pet_id' => $pet->id,
                    'scheduled_at' => $event->scheduled_at->toIso8601String(),
                ]);
            }
        }

        return $first;
    }

    /**
     * Cleaning (PetActivityService::clean): settle events that are already
     * due but not yet processed by a tick — they happened and are cleaned by
     * this action, so the next tick doesn't dirty the pet again — and mark
     * every uncleaned event as cleaned.
     *
     * @return int Number of events this clean took care of.
     */
    public function settleForCleaning(Pet $pet, CarbonInterface $now, ?QuietHours $quietHours): int
    {
        $from = $pet->last_decay_at;

        foreach ($this->duePending($pet, $now) as $event) {
            $happens = ($from === null || $event->scheduled_at->greaterThan($from))
                && ! ($quietHours?->isQuietNow($event->scheduled_at) ?? false);

            $event->forceFill([
                'status' => $happens ? HygieneEventStatus::Applied : HygieneEventStatus::Skipped,
                'resolved_at' => $now,
            ])->save();
        }

        return $pet->hygieneEvents()
            ->where('status', HygieneEventStatus::Applied->value)
            ->whereNull('cleaned_at')
            ->update(['cleaned_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Deterministic RNG for one pet and local day.
     */
    public function randomizerFor(Pet $pet, string $localDate): Randomizer
    {
        $seed = hash('sha256', $this->seedSalt.'|hygiene|'.$pet->id.'|'.$localDate, true);

        return new Randomizer(new Xoshiro256StarStar($seed));
    }

    /**
     * @return Collection<int, PetHygieneEvent>
     */
    private function duePending(Pet $pet, CarbonInterface $now)
    {
        return $pet->hygieneEvents()
            ->where('status', HygieneEventStatus::Pending->value)
            ->where('scheduled_at', '<=', $now)
            ->orderBy('scheduled_at')
            ->get();
    }
}
