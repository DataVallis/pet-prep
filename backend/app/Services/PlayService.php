<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\ChallengeStatus;
use App\Enums\PetPlan;
use App\Enums\PlayKind;
use App\Enums\PlaySource;
use App\Enums\PlayStatus;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetPlayEvent;
use App\Models\QuietHours;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Play & cuddle (M5-R05, David 2026-10-07 / 2026-10-08, PLAY_CUDDLE_SPEC §12).
 *
 * A reward without a penalty: a finished ball game or cuddle only makes the
 * dog "happy" for `play.happy_minutes` (video / hearts in the app) and is
 * shown to the parents (timeline, daily count). It NEVER touches a metric,
 * decay, escalation, illness, game over, routine, Care Score or report —
 * nothing in RoutineLedgerService / CareScoreService reads
 * `pet_play_events` or the two activity types (allow-lists, §12.6).
 *
 * - Who: a 12-week challenge in its trial or paid (David Q1), never the
 *   free mutt, never while the challenge waits for payment, never a legacy
 *   pet (D, Q2 — `play.legacy_pets`), only born, active, not game over.
 * - Free play: any time the pet is not frozen (hard stop, vet, payment
 *   lock), no mess is open and it is not quiet hours (D). No limit, no
 *   cooldown (David Q3).
 * - Invitations (the dog asks): up to 2 per family-local day (1 ball, 1
 *   cuddle, random order), decided by the decay tick from a seeded RNG,
 *   whole minutes inside the non-quiet part of the 07:00–20:00 band, ≥ 3 h
 *   apart, open 2 h (cut at the next quiet hours). Shown only while the dog
 *   is cared for: today's walk goal reached (David Q4), hunger and thirst
 *   above 30 % (D). Completed by the first child who plays that kind while
 *   it is shown (shared pet, David Q8). Ignored → it expires, nothing else.
 *
 * Cats (M5-R06-04, David 2026-10-08, CAT_SPEC §5.5): the ball game is
 * dog-only (a cat's play is the wand game, its care routine) — kindsFor();
 * cuddles stay, with one cuddle invitation a day, shown once the cat's
 * play goal of the day is reached (the cat's counterpart of the walk, Q4).
 *
 * Callers that write hold the pet's row lock (backend/CLAUDE.md); pet
 * attributes are set on $pet and saved by the caller.
 */
class PlayService
{
    /** Attempts to draw an invitation pair at least `min_gap_minutes` apart. */
    private const MAX_PAIR_DRAWS = 64;

    public function __construct(
        private readonly LifeStageService $lifeStages,
        private readonly DailyWalkService $walks,
        private readonly WandPlayService $wand,
        private ?string $seedSalt = null,
    ) {
        $this->seedSalt ??= (string) config('app.key');
    }

    // ──────────────────────────────────────────────────────────────
    //  Who may play
    // ──────────────────────────────────────────────────────────────

    /**
     * The pet is a kind of pet that has play at all (fixed facts): a
     * challenge pet with a profile that the parent sees as a challenge — a
     * mutt shown as "Free" (grandfathered / admin-unlocked mutt challenge,
     * M5-F02) has no play like the free mutt. The parent dashboard shows
     * `play_today` only for these.
     */
    public function appliesTo(Pet $pet): bool
    {
        return $pet->plan === PetPlan::Challenge
            && ! PetPlanPayload::displaysAsFree($pet)
            && ((bool) config('play.legacy_pets', false) || ! $pet->isLegacyProfile());
    }

    /**
     * Play exists for the pet right now (§12.1): challenge in trial or paid,
     * not waiting for payment, born, active, not game over. A temporary
     * freeze (hard stop, vet) keeps it eligible — canPlayNow() says no.
     */
    public function eligible(Pet $pet, ?CarbonInterface $now = null): bool
    {
        if (! $this->appliesTo($pet) || $pet->isUnborn() || ! $pet->is_active || $pet->is_game_over) {
            return false;
        }

        // Both evaluated at $now (QA nit: awaitsPayment() reads the wall clock).
        $status = $pet->challengeStatus($now);

        return ($status === ChallengeStatus::Trial || $status === ChallengeStatus::Paid)
            && ! $pet->isPaymentLocked();
    }

    /**
     * A child may finish a game now (pet level; the caller adds the child's
     * own lock, e.g. an unsigned contract): eligible, not frozen, not quiet
     * hours (D) and no open mess (hygiene shows 0 % — "clean first",
     * PRODUCT_SPEC §8).
     */
    public function canPlayNow(Pet $pet, CarbonInterface $now, ?QuietHours $quiet = null): bool
    {
        return $this->eligible($pet, $now)
            && ! $pet->isFrozen()
            && $pet->displayMetric('hygiene_level') > 0
            && ! $this->sleepsNow($pet, $now, $quiet);
    }

    /**
     * When a refused play becomes possible (422 next_allowed_at): the end of
     * the current quiet stretch, else null (a mess / no play has no time).
     */
    public function nextPlayAt(Pet $pet, CarbonInterface $now): ?CarbonInterface
    {
        if (! $this->eligible($pet, $now) || ! $this->sleepsNow($pet, $now) || $pet->displayMetric('hygiene_level') <= 0) {
            return null;
        }

        return $this->walks->endOfQuietStretch($pet->quietHours(), $now);
    }

    private function sleepsNow(Pet $pet, CarbonInterface $now, ?QuietHours $quiet = null): bool
    {
        return ! config('play.free_play_in_quiet_hours', false) && ($quiet ?? $pet->quietHours())->isQuietNow($now);
    }

    /**
     * The kinds this pet can play (M5-R06-04): a dog ball + cuddle, a cat
     * only cuddle (David 2026-10-08: no ball game for cats).
     *
     * @return list<PlayKind>
     */
    public function kindsFor(Pet $pet): array
    {
        return $pet->isCat() ? [PlayKind::Cuddle] : PlayKind::cases();
    }

    public function kindAvailable(Pet $pet, PlayKind $kind): bool
    {
        return in_array($kind, $this->kindsFor($pet), true);
    }

    // ──────────────────────────────────────────────────────────────
    //  Invitations (decay tick)
    // ──────────────────────────────────────────────────────────────

    /**
     * Decide the invitations of every family-local day from the day of
     * $from to the day of $now not decided yet (`pets.play_scheduled_through`;
     * attribute on $pet, the caller saves). Same outage rule as chewing:
     * after a scheduler gap only today is decided.
     *
     * Nothing is made up (QA PR #82 M2): a day is normally decided by the
     * first tick after its midnight; when it is decided late — after a
     * freeze across midnight (the frozen tick schedules nothing), a payment
     * lock, an outage or the birth — every planned instant at or before now
     * is written as `skipped`, so it is never offered after the thaw.
     */
    public function ensureInvitationsScheduled(Pet $pet, CarbonInterface $from, CarbonInterface $now, QuietHours $quiet): void
    {
        if (! $this->eligible($pet, $now)) {
            return;
        }

        $tz = $pet->familyTimezone();
        $today = Carbon::parse($pet->localDate($now), $tz);
        $day = Carbon::parse($pet->localDate($from), $tz);
        $outage = BehaviourEventService::isOutage($from, $now);
        if ($outage && $day->lessThan($today)) {
            $day = $today->copy();
        }

        $through = $pet->play_scheduled_through;
        if ($through !== null) {
            $next = Carbon::parse($through, $tz)->addDay();
            if ($next->greaterThan($day)) {
                $day = $next;
            }
        }

        $earliest = $today->copy()->subDays(HygieneEventService::MAX_CATCH_UP_DAYS);
        if ($day->lessThan($earliest)) {
            $day = $earliest;
        }
        if ($day->greaterThan($today)) {
            return;
        }

        $born = CarbonImmutable::instance($pet->born_at)->utc();
        $nowUtc = CarbonImmutable::instance($now)->utc();
        $stamp = now();
        $rows = [];
        for (; $day->lessThanOrEqualTo($today); $day->addDay()) {
            $date = $day->toDateString();
            foreach ($this->planDay($pet, $date, $quiet) as [$kind, $at]) {
                $skip = $at->lessThanOrEqualTo($born) || $at->lessThanOrEqualTo($nowUtc);
                $rows[] = [
                    'pet_id' => $pet->id,
                    'kind' => $kind->value,
                    'source' => PlaySource::Invitation->value,
                    'local_date' => $date,
                    'scheduled_at' => $at,
                    'expires_at' => $this->expiresAt($at, $quiet),
                    'status' => ($skip ? PlayStatus::Skipped : PlayStatus::Pending)->value,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];
            }
        }

        if ($rows !== []) {
            PetPlayEvent::insertOrIgnore($rows);
        }

        $pet->play_scheduled_through = $today->toDateString();
    }

    /**
     * The invitations of one family-local day: [kind, instant (UTC)], in
     * time order. Deterministic per (salt, pet, date). Kinds are shuffled
     * (ball / cuddle in random order); instants are whole minutes inside
     * the non-quiet part of the day band, at least `min_gap_minutes` apart
     * (bounded rejection sampling; no valid pair → one; no non-quiet minute
     * in the band → none).
     *
     * @return list<array{0: PlayKind, 1: CarbonImmutable}>
     */
    public function planDay(Pet $pet, string $localDate, QuietHours $quiet): array
    {
        $rng = $this->randomizerFor($pet, $localDate);
        // A cat gets only the cuddle invitation (M5-R06-04); a dog's draw is unchanged.
        $kinds = $rng->shuffleArray($this->kindsFor($pet));
        $count = max(0, min((int) config('play.invitations_per_day', 2), count($kinds)));

        $minutes = $this->openMinutesOfBand($pet, $localDate, $quiet);
        $total = count($minutes);
        if ($count === 0 || $total === 0) {
            return [];
        }

        $first = $minutes[$rng->getInt(0, $total - 1)];
        $instants = [$first];
        if ($count >= 2) {
            $gap = max(0, (int) config('play.min_gap_minutes', 180)) * 60;
            for ($draw = 0; $draw < self::MAX_PAIR_DRAWS; $draw++) {
                $a = $draw === 0 ? $first : $minutes[$rng->getInt(0, $total - 1)];
                $b = $minutes[$rng->getInt(0, $total - 1)];
                if (abs($a->getTimestamp() - $b->getTimestamp()) >= $gap && ! $a->equalTo($b)) {
                    $instants = [$a, $b];
                    break;
                }
            }
        }

        usort($instants, fn (CarbonImmutable $x, CarbonImmutable $y) => $x->getTimestamp() <=> $y->getTimestamp());

        $plan = [];
        foreach ($instants as $i => $at) {
            $plan[] = [$kinds[$i], $at];
        }

        return $plan;
    }

    /**
     * Every whole minute of the family-local day band that lies outside
     * quiet hours (UTC instants, in order).
     *
     * @return list<CarbonImmutable>
     */
    private function openMinutesOfBand(Pet $pet, string $localDate, QuietHours $quiet): array
    {
        $tz = $pet->familyTimezone();
        [$from, $to] = (array) config('play.day_band', ['07:00', '20:00']);
        $start = Carbon::parse($localDate.' '.$from, $tz)->utc();
        $end = Carbon::parse($localDate.' '.$to, $tz)->utc();

        $minutes = [];
        foreach (QuietHours::segmentsBetween($quiet, $start, $end) as [$segStart, $segEnd, $isQuiet]) {
            if ($isQuiet) {
                continue;
            }
            $cursor = CarbonImmutable::instance($segStart)->utc();
            $stop = CarbonImmutable::instance($segEnd)->utc();
            while ($cursor->lessThan($stop)) {
                $minutes[] = $cursor;
                $cursor = $cursor->addMinute();
            }
        }

        return $minutes;
    }

    /**
     * End of an invitation: `open_minutes` after its time, or the start of the
     * next quiet hours if that comes first.
     */
    public function expiresAt(CarbonInterface $at, QuietHours $quiet): CarbonImmutable
    {
        $start = CarbonImmutable::instance($at)->utc();
        $end = $start->addMinutes(max(1, (int) config('play.open_minutes', 120)));

        foreach (QuietHours::segmentsBetween($quiet, $start, $end) as [$segStart, , $isQuiet]) {
            if ($isQuiet) {
                return CarbonImmutable::instance($segStart)->utc();
            }
        }

        return $end;
    }

    /**
     * The tick: pending invitations whose end has passed → expired (no
     * consequence).
     */
    public function expireDue(Pet $pet, CarbonInterface $now): int
    {
        if (! $this->hasInvitations($pet)) {
            return 0;
        }

        return PetPlayEvent::where('pet_id', $pet->id)
            ->where('status', PlayStatus::Pending->value)
            ->where('expires_at', '<=', $now)
            ->update(['status' => PlayStatus::Expired->value, 'updated_at' => now()]);
    }

    /**
     * The tick while the pet is frozen (hard stop, vet, payment lock): a
     * pending invitation whose time has come is dropped (`skipped`), like a
     * mess in a freeze — it is never made up.
     */
    public function skipWhileFrozen(Pet $pet, CarbonInterface $now): int
    {
        if (! $this->hasInvitations($pet)) {
            return 0;
        }

        return PetPlayEvent::where('pet_id', $pet->id)
            ->where('status', PlayStatus::Pending->value)
            ->where('scheduled_at', '<=', $now)
            ->update(['status' => PlayStatus::Skipped->value, 'updated_at' => now()]);
    }

    /**
     * The pet ended (game over, deactivated — Pet `updated` hook, QA PR #82
     * m3): every pending invitation is dropped; the tick never processes
     * such a pet again.
     */
    public function skipPending(Pet $pet): int
    {
        return PetPlayEvent::where('pet_id', $pet->id)
            ->where('status', PlayStatus::Pending->value)
            ->update(['status' => PlayStatus::Skipped->value, 'updated_at' => now()]);
    }

    /**
     * Cheap guard for the per-minute tick (QA PR #82 m1): only a pet whose
     * invitations were ever decided can have a pending one — no query for
     * free, legacy or never-scheduled pets.
     */
    public function hasInvitations(Pet $pet): bool
    {
        return $pet->play_scheduled_through !== null && $this->appliesTo($pet);
    }

    // ──────────────────────────────────────────────────────────────
    //  Offer (what the app shows)
    // ──────────────────────────────────────────────────────────────

    /**
     * The invitation the dog shows now (§12.3), optionally of one kind: the
     * oldest pending invitation with scheduled_at ≤ now < expires_at, while
     * the child can play, hunger and thirst show more than the threshold
     * and today's walk goal is reached. Evaluated on read.
     */
    public function offeredInvitation(Pet $pet, CarbonInterface $now, ?PlayKind $kind = null, ?QuietHours $quiet = null): ?PetPlayEvent
    {
        // Cheapest first (QA PR #82 m1): attributes, then one indexed query for a
        // pending row in its window, then quiet hours and the step goal.
        if (! $this->hasInvitations($pet)
            || ! $this->eligible($pet, $now)
            || $pet->isFrozen()
            || $pet->displayMetric('hygiene_level') <= 0
            || $pet->displayMetric('hunger_level') <= (int) config('play.offer_min_hunger', 30)
            || $pet->displayMetric('thirst_level') <= (int) config('play.offer_min_thirst', 30)) {
            return null;
        }

        $invitation = PetPlayEvent::where('pet_id', $pet->id)
            ->where('source', PlaySource::Invitation->value)
            ->where('status', PlayStatus::Pending->value)
            ->where('scheduled_at', '<=', $now)
            ->where('expires_at', '>', $now)
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind->value))
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->first();

        if ($invitation === null || $this->sleepsNow($pet, $now, $quiet) || ! $this->walkGoalReached($pet, $now)) {
            return null;
        }

        return $invitation;
    }

    /**
     * Id of the shown invitation (null = none) — the tick compares it before
     * and after to broadcast a flip once (quiet hours passed in: one read per tick).
     */
    public function offeredInvitationId(Pet $pet, CarbonInterface $now, ?QuietHours $quiet = null): ?int
    {
        return $this->offeredInvitation($pet, $now, null, $quiet)?->id;
    }

    /**
     * Today's walk is done (David Q4): the pet's steps of the family-local
     * day reached today's step goal — the same rule as the walk routine
     * (RoutineLedgerService, PRODUCT_SPEC §11.1). A goal of 0 = no walk needed.
     */
    public function walkGoalReached(Pet $pet, CarbonInterface $now): bool
    {
        // M5-R06-04: a cat is "cared for" once today's wand play goal is reached.
        if ($pet->isCat()) {
            $today = $pet->localDate($now);
            $goal = $this->wand->goalOn($pet, $today);

            return $goal <= 0 || $this->wand->successfulOn($pet, $today) >= $goal;
        }

        $config = $pet->breedConfig();
        if ($config === null) {
            return false;
        }

        $today = $pet->localDate($now);
        $steps = $pet->last_step_reset_at !== null && $pet->localDate($pet->last_step_reset_at) === $today
            ? (int) $pet->daily_step_count
            : 0;
        $goal = $this->lifeStages->rulesOn($pet, $today, $config)->stepGoal;

        return $goal <= 0 || $steps >= $goal;
    }

    // ──────────────────────────────────────────────────────────────
    //  Completing a play (child action, under the pet lock)
    // ──────────────────────────────────────────────────────────────

    /**
     * Record a finished ball game / cuddle by $child (caller checked the
     * locks and canPlayNow()). Completes the shown invitation of that kind
     * (the first child wins, Q8) or writes a `free` row, and makes the dog
     * happy until now + happy_minutes (a new play extends, never adds up).
     * Returns null for a repeat of the same child and kind within
     * `repeat_seconds` (double tap / retry — nothing written).
     */
    public function complete(Pet $pet, User $child, PlayKind $kind, CarbonInterface $now): ?PetPlayEvent
    {
        $repeat = PetPlayEvent::where('pet_id', $pet->id)
            ->where('completed_by', $child->id)
            ->where('kind', $kind->value)
            ->where('status', PlayStatus::Done->value)
            ->where('completed_at', '>', CarbonImmutable::instance($now)->subSeconds(max(0, (int) config('play.repeat_seconds', 10))))
            ->exists();
        if ($repeat) {
            return null;
        }

        $event = $this->offeredInvitation($pet, $now, $kind);
        if ($event !== null) {
            $event->forceFill([
                'status' => PlayStatus::Done,
                'completed_by' => $child->id,
                'completed_at' => $now,
            ])->save();
        } else {
            $event = PetPlayEvent::create([
                'pet_id' => $pet->id,
                'kind' => $kind,
                'source' => PlaySource::Free,
                'local_date' => $pet->localDate($now),
                'status' => PlayStatus::Done,
                'completed_by' => $child->id,
                'completed_at' => $now,
            ]);
        }

        $pet->happy_until = CarbonImmutable::instance($now)->addMinutes(max(0, (int) config('play.happy_minutes', 30)));

        return $event;
    }

    /**
     * Parent timeline (§7, D): one row per child and kind within
     * `timeline_merge_minutes`; a later play in that window increments the
     * row's value (number of plays) instead of adding a row, so play never
     * pushes the care actions out of the last 20 items. Rows are written
     * without model events (the action broadcasts once itself).
     */
    public function recordTimeline(Pet $pet, User $child, PlayKind $kind, CarbonInterface $now): void
    {
        $type = $kind->activityType();
        $recent = ActivityLog::where('pet_id', $pet->id)
            ->where('actor_user_id', $child->id)
            ->where('activity_type', $type->value)
            ->where('created_at', '>', CarbonImmutable::instance($now)->subMinutes(max(0, (int) config('play.timeline_merge_minutes', 60))))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');

        if ($recent !== null) {
            ActivityLog::whereKey($recent)->toBase()->increment('value');

            return;
        }

        ActivityLog::withoutEvents(fn () => ActivityLog::create([
            'pet_id' => $pet->id,
            'actor_user_id' => $child->id,
            'activity_type' => $type->value,
            'value' => 1,
        ]));
    }

    // ──────────────────────────────────────────────────────────────
    //  Parent dashboard
    // ──────────────────────────────────────────────────────────────

    /**
     * Completed plays of today's family-local date per pet (all children
     * together), one query. Pets without play (free, legacy) → null.
     *
     * @param  Collection<int, Pet>  $pets
     * @return array<int, array{play: int, cuddle: int}|null>
     */
    public function todayCounts(Collection $pets, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $out = [];
        $byDate = [];
        foreach ($pets as $pet) {
            if (! $this->appliesTo($pet)) {
                $out[$pet->id] = null;

                continue;
            }
            $out[$pet->id] = ['play' => 0, 'cuddle' => 0];
            $byDate[$pet->localDate($now)][] = $pet->id;
        }

        foreach ($byDate as $date => $ids) {
            $rows = PetPlayEvent::whereIn('pet_id', $ids)
                ->where('local_date', $date)
                ->where('status', PlayStatus::Done->value)
                ->selectRaw('pet_id, kind, count(*) as n')
                ->groupBy('pet_id', 'kind')
                ->toBase()
                ->get();
            foreach ($rows as $row) {
                $out[(int) $row->pet_id][(string) $row->kind] = (int) $row->n;
            }
        }

        return $out;
    }

    /**
     * Deterministic RNG for one pet and local day (not predictable by
     * clients: salted with the app key unless a test pins the salt).
     */
    public function randomizerFor(Pet $pet, string $localDate): Randomizer
    {
        $seed = hash('sha256', $this->seedSalt.'|play|'.$pet->id.'|'.$localDate, true);

        return new Randomizer(new Xoshiro256StarStar($seed));
    }

    /**
     * Activity types this feature writes (parent timeline only).
     *
     * @return list<ActivityType>
     */
    public static function activityTypes(): array
    {
        return [ActivityType::PlayedWithPet, ActivityType::CuddledPet];
    }
}
