<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\HygieneEventKind;
use App\Enums\HygieneEventStatus;
use App\Enums\StageParamKey;
use App\Models\ActivityLog;
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
 * An unborn pet (contract not signed, M1-07b) gets no schedule at all; the
 * birth day is scheduled by the first tick after birth, and its events
 * before the birth moment are skipped like any pre-birth event.
 * After a scheduler gap every missed event is applied exactly once (each row
 * flips from pending once).
 *
 * Cats (M5-R06-05, CAT_SPEC Q3): `poops_per_day` is 0; instead every
 * family-local day gets `litter_uses_per_day` (kitten 3, grown cat 2)
 * `litter_use` rows drawn the same way (own RNG stream). A litter use is NOT
 * a mess — applying it leaves hygiene alone; its scoop deadline and the
 * `litter_accident` after it are LitterService's. A `scratching` event (the
 * day after a missed play, ScratchingService) is a mess like chewing.
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

    public function __construct(private ?string $seedSalt = null, private ?LifeStageService $lifeStages = null)
    {
        $this->seedSalt ??= (string) config('app.key');
    }

    /**
     * Make sure events exist for every family-local day from the day of
     * $from up to the day of $now (attributes on $pet; the caller saves).
     */
    public function ensureScheduled(Pet $pet, CarbonInterface $from, CarbonInterface $now, ?QuietHours $quietHours, BreedConfig $breedConfig): void
    {
        // Unborn (contract not signed, M1-07b): no schedule before birth.
        if ($pet->isUnborn()) {
            return;
        }

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
                    'kind' => HygieneEventKind::Poop->value,
                    'local_date' => $date,
                    'scheduled_at' => $at,
                    'status' => HygieneEventStatus::Pending->value,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];
            }
            // M5-R06-05: the cat's litter uses of the day (not messes).
            $uses = $this->litterUsesOn($pet, $date);
            if ($uses > 0) {
                foreach ($this->scheduleDay($pet, $date, $quietHours, $uses, $this->randomizerFor($pet, 'litter|'.$date)) as $at) {
                    $rows[] = [
                        'pet_id' => $pet->id,
                        'kind' => HygieneEventKind::LitterUse->value,
                        'local_date' => $date,
                        'scheduled_at' => $at,
                        'status' => HygieneEventStatus::Pending->value,
                        'created_at' => $stamp,
                        'updated_at' => $stamp,
                    ];
                }
            }
        }

        if ($rows !== []) {
            PetHygieneEvent::insertOrIgnore($rows);
        }

        $pet->hygiene_scheduled_through = $today->toDateString();
    }

    /**
     * Litter uses a cat has on a family-local date (`litter_uses_per_day` of
     * that day's life stage); 0 for a dog or a cat without life-stage data.
     */
    public function litterUsesOn(Pet $pet, string $localDate): int
    {
        if (! $pet->isCat() || $pet->isLegacyProfile()) {
            return 0;
        }
        $this->lifeStages ??= app(LifeStageService::class);
        $value = $this->lifeStages->stageValueOn($pet, $localDate, StageParamKey::LitterUsesPerDay)['value'] ?? null;

        return is_int($value) && $value > 0 ? $value : 0;
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
     * Behaviour events (M5-R02 chewing, PR #42 review): after a scheduler
     * outage (the interval ($from, $now] is longer than
     * BehaviourEventService::OUTAGE_TOLERANCE_SECONDS) a pending non-poop
     * event is skipped instead of applied — its 2-hour deadline may already
     * be gone and nobody could react during the gap. Poops keep the M1-05
     * rule (applied once, late).
     *
     * @return Carbon|null The earliest event that happened (hygiene is 0 from then on), or null.
     */
    public function applyDue(Pet $pet, CarbonInterface $from, CarbonInterface $now, ?QuietHours $quietHours): ?Carbon
    {
        if ($pet->isUnborn()) {
            return null;
        }

        $first = null;
        $outage = BehaviourEventService::isOutage($from, $now);

        foreach ($this->duePending($pet, $now) as $event) {
            // Like a poop, a litter use (M5-R06-05) is applied late after an
            // outage — LitterService then decides its deadline (no accident made up).
            $lateOk = in_array($event->kind, [HygieneEventKind::Poop, HygieneEventKind::LitterUse], true);
            $happens = $event->scheduled_at->greaterThan($from)
                && ! ($quietHours?->isQuietNow($event->scheduled_at) ?? false)
                && ! ($outage && ! $lateOk);
            if ($outage && ! $lateOk) {
                BehaviourEventService::warnOutage($pet, $from, $now);
            }

            $event->forceFill([
                'status' => $happens ? HygieneEventStatus::Applied : HygieneEventStatus::Skipped,
                'resolved_at' => $now,
            ])->save();

            if ($happens) {
                // M5-R06-05: a litter use is not a mess — hygiene stays.
                if ($event->kind->isMess()) {
                    $first ??= $event->scheduled_at->copy();
                }
                Log::info('HygieneEventService: hygiene event happened', [
                    'pet_id' => $pet->id,
                    'kind' => $event->kind->value,
                    'scheduled_at' => $event->scheduled_at->toIso8601String(),
                ]);

                // M5-R02: the parent timeline shows behaviour events (system row, no actor).
                self::logBehaviourEvent($pet, $event);
            }
        }

        return $first;
    }

    /**
     * Cleaning (PetActivityService::clean): settle events that are already
     * due but not yet processed by a tick — they happened and are cleaned by
     * this action, so the next tick doesn't dirty the pet again — and mark
     * every uncleaned event the cleaning game resolves (poop, puppy accident,
     * the cat's litter accident; not chewing / scratching, M5-R02 / R06-05)
     * as cleaned.
     *
     * @return int Number of events this clean took care of.
     */
    public function settleForCleaning(Pet $pet, CarbonInterface $now, ?QuietHours $quietHours): int
    {
        $this->settleDuePending($pet, $now, $quietHours);

        return $pet->hygieneEvents()
            ->where('status', HygieneEventStatus::Applied->value)
            ->whereIn('kind', HygieneEventKind::cleanedByCleaning())
            ->whereNull('cleaned_at')
            ->update(['cleaned_at' => $now, 'updated_at' => $now]);
    }

    /**
     * "Pospravi in daj igračo" (M5-R02, PetActivityService::resolveChewing):
     * like settleForCleaning, for chewing events only.
     *
     * @return int Number of chewing events resolved.
     */
    public function settleChewing(Pet $pet, CarbonInterface $now, ?QuietHours $quietHours): int
    {
        $this->settleDuePending($pet, $now, $quietHours);

        return $pet->hygieneEvents()
            ->where('status', HygieneEventStatus::Applied->value)
            ->where('kind', HygieneEventKind::Chewing->value)
            ->whereNull('cleaned_at')
            ->update(['cleaned_at' => $now, 'updated_at' => $now]);
    }

    /**
     * "Odnesi na praskalnik in pohvali" (M5-R06-05, ScratchingService): like
     * settleChewing, for the cat's scratching events only.
     *
     * @return int Number of scratching events resolved.
     */
    public function settleScratching(Pet $pet, CarbonInterface $now, ?QuietHours $quietHours): int
    {
        $this->settleDuePending($pet, $now, $quietHours);

        return $pet->hygieneEvents()
            ->where('status', HygieneEventStatus::Applied->value)
            ->where('kind', HygieneEventKind::Scratching->value)
            ->whereNull('cleaned_at')
            ->update(['cleaned_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Messes that happened and are not resolved yet (any mess kind — not the
     * cat's litter uses, M5-R06-05), oldest first. Hygiene shows 0 % exactly
     * while one is open (M5-R02).
     *
     * @return Collection<int, PetHygieneEvent>
     */
    public function openEvents(Pet $pet)
    {
        return $pet->hygieneEvents()
            ->where('status', HygieneEventStatus::Applied->value)
            ->whereIn('kind', HygieneEventKind::messes())
            ->whereNull('cleaned_at')
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * One system row in activities_log (actor null) for something that
     * happened to the dog (M5-R02 accident / chewing), at the event time.
     * Created without model events (the caller broadcasts once).
     */
    public static function logSystemEvent(Pet $pet, ActivityType $type, CarbonInterface $at): void
    {
        ActivityLog::withoutEvents(fn () => (new ActivityLog)->forceFill([
            'pet_id' => $pet->id,
            'actor_user_id' => null,
            'activity_type' => $type,
            'value' => null,
            'created_at' => Carbon::instance($at)->utc(),
        ])->save());
    }

    /**
     * The parent-timeline system row of an applied behaviour event: chewing
     * (M5-R02), the cat's scratching (M5-R06-05). Nothing for other kinds.
     */
    private static function logBehaviourEvent(Pet $pet, PetHygieneEvent $event): void
    {
        $type = match ($event->kind) {
            HygieneEventKind::Chewing => ActivityType::PetChewed,
            HygieneEventKind::Scratching => ActivityType::PetScratched,
            default => null,
        };
        if ($type !== null) {
            self::logSystemEvent($pet, $type, $event->scheduled_at);
        }
    }

    /**
     * Events already due but not yet processed by a tick: they happened (or
     * were skipped) — decided the same way the tick decides.
     */
    private function settleDuePending(Pet $pet, CarbonInterface $now, ?QuietHours $quietHours): void
    {
        $from = $pet->last_decay_at;

        foreach ($this->duePending($pet, $now) as $event) {
            $happens = ($from === null || $event->scheduled_at->greaterThan($from))
                && ! ($quietHours?->isQuietNow($event->scheduled_at) ?? false);

            $event->forceFill([
                'status' => $happens ? HygieneEventStatus::Applied : HygieneEventStatus::Skipped,
                'resolved_at' => $now,
            ])->save();

            if ($happens) {
                self::logBehaviourEvent($pet, $event);
            }
        }
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
