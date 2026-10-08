<?php

namespace App\Services;

use App\Enums\CareRefusal;
use App\Enums\CareSessionKind;
use App\Enums\CareSessionStatus;
use App\Enums\HygieneEventKind;
use App\Enums\HygieneEventStatus;
use App\Enums\StageParamKey;
use App\Models\Pet;
use App\Models\PetCareSession;
use App\Models\PetHygieneEvent;
use App\Models\QuietHours;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * "Opraskala je kavč" — the cat's scratching (M5-R06-05, CAT_SPEC Q2 / Q10,
 * §6; David 2026-10-08 13:47 + ~22:20).
 *
 * When: only the family-local day AFTER a missed play routine
 * (`pets.play_missed_on` = yesterday, written by WandPlayService::closeDay
 * at the cat's midnight), at most one per day, at a random whole minute
 * outside quiet hours (the hygiene schedule's draw, own RNG stream) — never
 * at random for kittens (no frequency source). Only cats with life-stage
 * data whose breed says `scratching_after_missed_play`. Decided once per
 * day; for a cat `pets.behaviour_scheduled_through` is this pointer (cats
 * have no chewing). After a scheduler outage nothing is made up.
 *
 * What: a `scratching` mess in `pet_hygiene_events` — hygiene 0 like the
 * dog's chewing; the tick applies it (HygieneEventService::applyDue). The
 * resolve deadline is 2 h outside quiet hours exactly like chewing (David
 * 2026-10-08 ~22:20, RoutineLedgerService::CLEAN_WITHIN_SECONDS); if it is
 * not resolved the existing ladder runs (alarm, illness after 6 h outside
 * quiet hours, game over after 24 h).
 *
 * Resolve: "Odnesi na praskalnik in pohvali" (AAFP C23 — never a
 * punishment): POST …/scratching/start = the child carries the cat to the
 * scratcher; the server's schedule says when the cat lands (`land_at_ms`);
 * POST …/scratching/finish {praise_ms} — the praise counts when it comes
 * ≥ `min_reaction_ms` and ≤ `praise_window_ms` (3 s) after the landing
 * (scored on the server, like training). Too early / too late / no praise:
 * no consequence, try again. Success resolves every open scratching event.
 */
class ScratchingService
{
    public function __construct(
        private readonly HygieneEventService $hygiene,
        private readonly LifeStageService $lifeStages,
        private ?string $seedSalt = null,
    ) {
        $this->seedSalt ??= (string) config('app.key');
    }

    /** Scratching after a missed play applies to this cat on $localDate. */
    public function appliesOn(Pet $pet, string $localDate): bool
    {
        if (! $pet->isCat() || $pet->isLegacyProfile()) {
            return false;
        }

        return ($this->lifeStages->stageValueOn($pet, $localDate, StageParamKey::ScratchingAfterMissedPlay)['value'] ?? false) === true;
    }

    // ──────────────────────────────────────────────────────────────
    //  Schedule (tick, after the midnight close)
    // ──────────────────────────────────────────────────────────────

    /**
     * Decide today's scratching once (attributes on $pet; the caller saves).
     * Only today: a missed play day is known from `play_missed_on`.
     */
    public function ensureScheduled(Pet $pet, CarbonInterface $from, CarbonInterface $now, ?QuietHours $quiet): void
    {
        if ($pet->isUnborn() || ! $pet->isCat() || $pet->isLegacyProfile()) {
            return;
        }

        $today = $pet->localDate($now);
        $through = self::dateOf($pet->behaviour_scheduled_through);
        if ($through !== null && $through >= $today) {
            return;
        }
        $pet->behaviour_scheduled_through = $today;

        $yesterday = CarbonImmutable::parse($today, 'UTC')->subDay()->toDateString();
        $missed = self::dateOf($pet->play_missed_on) === $yesterday;
        if (! $missed || ! $this->appliesOn($pet, $today)) {
            return;
        }

        $outage = BehaviourEventService::isOutage($from, $now);
        $stamp = now();
        foreach ($this->hygiene->scheduleDay($pet, $today, $quiet, 1, $this->randomizerFor($pet, $today)) as $at) {
            $late = $at->lessThanOrEqualTo($now);
            PetHygieneEvent::insertOrIgnore([[
                'pet_id' => $pet->id,
                'kind' => HygieneEventKind::Scratching->value,
                'local_date' => $today,
                'scheduled_at' => $at,
                'status' => $outage && $late ? HygieneEventStatus::Skipped->value : HygieneEventStatus::Pending->value,
                'resolved_at' => $outage && $late ? $stamp : null,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]]);
        }
    }

    /** A date attribute (string or Carbon, maybe set in memory this tick) as Y-m-d. */
    private static function dateOf(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof CarbonInterface ? $value->toDateString() : substr((string) $value, 0, 10);
    }

    public function randomizerFor(Pet $pet, string $localDate): Randomizer
    {
        return new Randomizer(new Xoshiro256StarStar(hash('sha256', $this->seedSalt.'|scratching|'.$pet->id.'|'.$localDate, true)));
    }

    // ──────────────────────────────────────────────────────────────
    //  Reading
    // ──────────────────────────────────────────────────────────────

    /** The open scratching mess (applied, not resolved), oldest first, if any. */
    public function openEvent(Pet $pet): ?PetHygieneEvent
    {
        return $pet->hygieneEvents()
            ->where('kind', HygieneEventKind::Scratching->value)
            ->where('status', HygieneEventStatus::Applied->value)
            ->whereNull('cleaned_at')
            ->orderBy('scheduled_at')
            ->first();
    }

    public function liveSession(Pet $pet, CarbonInterface $now): ?PetCareSession
    {
        return PetCareSession::where('pet_id', $pet->id)
            ->where('kind', CareSessionKind::Scratching->value)
            ->where('status', CareSessionStatus::Active->value)
            ->where('expires_at', '>', $now)
            ->whereNot(fn ($q) => CatChoreService::whereInterrupted($q, $now))
            ->first();
    }

    /**
     * @return array{refusal: CareRefusal, next_allowed_at: CarbonImmutable|null}|null
     */
    public function startRefusal(Pet $pet, ?User $child, CarbonInterface $now): ?array
    {
        if (! $pet->isCat() || $this->openEvent($pet) === null) {
            return ['refusal' => CareRefusal::ScratchingNotNeeded, 'next_allowed_at' => null];
        }
        $live = $this->liveSession($pet, $now);
        if ($live !== null && ($child === null || (int) $live->user_id !== $child->id)) {
            return ['refusal' => CareRefusal::ScratchingSessionActive, 'next_allowed_at' => CarbonImmutable::instance($live->expires_at)->utc()];
        }

        return null;
    }

    public static function praiseWindowMs(): int
    {
        return max(1, (int) config('cat_care.scratching.praise_window_ms', 3000));
    }

    public static function minReactionMs(): int
    {
        return max(0, (int) config('cat_care.scratching.min_reaction_ms', 150));
    }

    // ──────────────────────────────────────────────────────────────
    //  Start / finish (caller holds the pet lock)
    // ──────────────────────────────────────────────────────────────

    /**
     * The child picks the cat up and carries it to the scratcher. The same
     * child's own unfinished carry is replaced (no penalty).
     *
     * @return array{refusal: CareRefusal|null, next_allowed_at: CarbonInterface|null, session: PetCareSession|null}
     */
    public function start(Pet $pet, User $child, CarbonInterface $now, ?Randomizer $rng = null): array
    {
        $now = CarbonImmutable::instance($now)->utc()->startOfSecond();
        app(CatChoreService::class)->expireStale($pet, $now);

        $refusal = $this->startRefusal($pet, $child, $now);
        if ($refusal !== null) {
            return ['refusal' => $refusal['refusal'], 'next_allowed_at' => $refusal['next_allowed_at'], 'session' => null];
        }

        PetCareSession::where('pet_id', $pet->id)
            ->where('kind', CareSessionKind::Scratching->value)
            ->where('status', CareSessionStatus::Active->value)
            ->update(['status' => CareSessionStatus::Aborted->value, 'updated_at' => $now]);

        $rng ??= new Randomizer;
        $min = max(0, (int) config('cat_care.scratching.land_at_min_ms', 800));
        $land = $rng->getInt($min, max($min, (int) config('cat_care.scratching.land_at_max_ms', 2000)));
        $durationMs = $land + self::praiseWindowMs();
        $endsAt = $now->addMilliseconds($durationMs);

        $session = PetCareSession::create([
            'public_id' => (string) Str::uuid(),
            'pet_id' => $pet->id,
            'user_id' => $child->id,
            'kind' => CareSessionKind::Scratching,
            'local_date' => $pet->localDate($now),
            'started_at' => $now,
            'ends_at' => $endsAt,
            'expires_at' => $endsAt->addSeconds(max(0, (int) config('cat_care.scratching.finish_grace_seconds', 30))),
            'duration_ms' => $durationMs,
            'schedule' => [
                // The cat lands on the scratcher (ms since start): praise from here on.
                'land_at_ms' => $land,
                'praise_window_ms' => self::praiseWindowMs(),
                'min_reaction_ms' => self::minReactionMs(),
            ],
            'status' => CareSessionStatus::Active,
        ]);

        return ['refusal' => null, 'next_allowed_at' => null, 'session' => $session];
    }

    /**
     * The praise: `praise_ms` = ms since the start when the child praised
     * (null = no praise). Success → the session is `completed` (the caller
     * resolves the scratching); otherwise `failed` — no consequence.
     *
     * @return array{refusal: CareRefusal|null, session: PetCareSession|null, repeat: bool, counted: bool}
     */
    public function finish(Pet $pet, User $child, string $publicId, ?int $praiseMs, CarbonInterface $now): array
    {
        $exact = CarbonImmutable::instance($now)->utc();
        $now = $exact->startOfSecond();
        $refuse = fn (CareRefusal $r, ?PetCareSession $s = null): array => ['refusal' => $r, 'session' => $s, 'repeat' => false, 'counted' => false];

        $session = PetCareSession::where('public_id', $publicId)
            ->where('pet_id', $pet->id)
            ->where('kind', CareSessionKind::Scratching->value)
            ->first();
        if ($session === null || (int) $session->user_id !== $child->id) {
            return $refuse(CareRefusal::CareSessionInvalid);
        }
        if (in_array($session->status, [CareSessionStatus::Completed, CareSessionStatus::Failed], true)) {
            return ['refusal' => null, 'session' => $session, 'repeat' => true, 'counted' => $session->status === CareSessionStatus::Completed];
        }

        app(CatChoreService::class)->expireStale($pet, $now);
        $session->refresh();
        if ($session->status === CareSessionStatus::Interrupted) {
            return $refuse(CareRefusal::CareSessionInterrupted, $session);
        }
        if ($session->status !== CareSessionStatus::Active || ! $session->expires_at->greaterThan($now)) {
            if ($session->status === CareSessionStatus::Active) {
                $session->forceFill(['status' => CareSessionStatus::Expired])->save();
            }

            return $refuse(CareRefusal::CareSessionExpired, $session);
        }

        $land = (int) ($session->schedule['land_at_ms'] ?? 0);
        $started = CarbonImmutable::instance($session->started_at)->utc();
        $elapsedMs = (int) (($exact->getTimestamp() - $started->getTimestamp()) * 1000 + intdiv((int) $exact->format('u'), 1000));
        // The cat has not landed yet: nothing to praise.
        if ($elapsedMs < $land) {
            return $refuse(CareRefusal::CareSessionNotOver, $session);
        }
        $tolerance = max(0, (int) config('cat_care.scratching.clock_tolerance_ms', 2000));
        if ($praiseMs !== null && ($praiseMs < 0 || $praiseMs > $elapsedMs + $tolerance)) {
            return $refuse(CareRefusal::CareSessionInvalidInput, $session);
        }

        $result = self::score($praiseMs, $land, (int) ($session->schedule['praise_window_ms'] ?? self::praiseWindowMs()), (int) ($session->schedule['min_reaction_ms'] ?? self::minReactionMs()));
        $session->forceFill([
            'status' => $result['success'] ? CareSessionStatus::Completed : CareSessionStatus::Failed,
            'finished_at' => $now,
            'result' => $result,
        ])->save();

        return ['refusal' => null, 'session' => $session, 'repeat' => false, 'counted' => $result['success']];
    }

    /**
     * Judge the praise (pure): in time = [land + min_reaction, land + window].
     *
     * @return array{success: bool, reason: 'no_praise'|'too_early'|'too_late'|null, praise_ms: int|null, land_at_ms: int, delay_ms: int|null, praise_window_ms: int}
     */
    public static function score(?int $praiseMs, int $landAtMs, int $windowMs, int $minReactionMs): array
    {
        $delay = $praiseMs !== null ? $praiseMs - $landAtMs : null;
        $reason = match (true) {
            $delay === null => 'no_praise',
            $delay < $minReactionMs => 'too_early',
            $delay > $windowMs => 'too_late',
            default => null,
        };

        return [
            'success' => $reason === null,
            'reason' => $reason,
            'praise_ms' => $praiseMs,
            'land_at_ms' => $landAtMs,
            'delay_ms' => $delay,
            'praise_window_ms' => $windowMs,
        ];
    }

    /** Resolve every open scratching mess now (after a successful finish). */
    public function resolve(Pet $pet, CarbonInterface $now): int
    {
        return $this->hygiene->settleScratching($pet, Carbon::instance($now), $pet->quietHours());
    }
}
