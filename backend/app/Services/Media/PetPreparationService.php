<?php

namespace App\Services\Media;

use App\Enums\PetStateEnum;
use App\Enums\PetTransition;
use App\Events\PetUpdated;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Services\FalAiService;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pet preparation (M5-T06, APP_REFINEMENT_SPEC §13, David 2026-10-20).
 *
 * The user never learns that images / videos are being made: while a pet's
 * media are not all stored, the apps show a neutral background set-up
 * (»Pripravljamo Luna … to lahko traja nekaj minut«), features whose clips
 * are missing say »… se še pripravlja«, and once everything is stored the
 * child hears »Luna je pripravljen!« (in-app + one push) — once per pet, and
 * only when the preparation was actually shown (the media were not all
 * stored at birth / after the purchase that added the full set).
 *
 * - What a pet must have = the reference image + every state video it is
 *   entitled to (MediaEntitlementService — unchanged; this class never
 *   decides entitlement and never generates anything).
 * - "Stored" = the pet's own slot has a servable file — its own or its shared
 *   look's (the same effective set PetMediaService::mediaFor / GameMediaReadiness
 *   read). An older generation still being served while a new one runs (life
 *   stage change, admin regenerate) counts as stored: no preparation for growth.
 * - "In progress" = not stored and not failed (a failed item waits for the daily
 *   retry — the apps never wait on it; the game keeps its own »se še pripravlja«).
 * - A pet without AI media (`media_status = disabled`, no image) or with AI off
 *   (no fal key) is ready: nothing will be made.
 *
 * Generation order ({@see order()}): what the child sees in the first minutes
 * first — reference image → idle (standing), sleeping, hungry (eating) — then
 * the games (playing), then the rest. Only the ORDER of the queued jobs changes.
 *
 * Bookkeeping: `pets.preparation_started_at` (birth, or the purchase of a pet
 * that was waiting for it, while something was in progress) and
 * `pets.preparation_ready_at` (the last item stored) — ready_at − started_at
 * is the measured preparation time (admin AI media panel). A pet has at most
 * one preparation — at birth, or at the purchase if it was ready at birth /
 * waited for the payment. Media made later (a new life stage, a regenerate)
 * never show the set-up screen: older files keep playing, missing clips fall
 * back and the games keep »Igra se še pripravlja«.
 */
class PetPreparationService
{
    /** Payload key of the reference image (the video keys are PetStateEnum values). */
    public const IMAGE = 'image';

    /** PetUpdated event type / push type of the single "ready" news. */
    public const EVENT_READY = 'pet_ready';

    /**
     * The set-up screen (`preparing`) shows at most this long after the preparation
     * started: a clip that hangs (lost webhook — the sweep gives up after 2 h) never
     * keeps the child from the pet. The preparation itself keeps running and is
     * still announced when everything is stored.
     */
    public const SCREEN_MAX_MINUTES = 15;

    /**
     * The push »… je pripravljen!« only when the preparation ended within this
     * long of its start: a clip that failed (budget, fal error) and was stored by
     * the next day's retry must not send day-old news. The `pet_ready` event and
     * `preparation_ready_at` are still written (the apps cap the in-app message too).
     */
    public const PUSH_MAX_HOURS = 2;

    /**
     * A preparation that ended this fast was never seen (the screen needs a state
     * fetch to appear): no `pet_ready` event, no push, no in-app message (QA m4).
     */
    public const MIN_SHOWN_SECONDS = 10;

    /**
     * QA M1: pets born before this release never get a preparation (their media
     * were made long ago; a later purchase only adds clips, which the games mark
     * »… se še pripravlja«). Release date of M5-T06 (migration 2027_01_04_120000).
     */
    public const SINCE = '2026-10-21T00:00:00Z';

    public const BEGIN_BIRTH = 'birth';

    public const BEGIN_PURCHASE = 'purchase';

    public function __construct(
        private readonly MediaEntitlementService $entitlements,
        private readonly FalAiService $fal,
    ) {}

    /**
     * The entitled video states in generation order (config `media.preparation_order`;
     * states not listed there keep their entitlement order, after the listed ones).
     *
     * @param  list<PetStateEnum>  $states
     * @return list<PetStateEnum>
     */
    public static function order(array $states): array
    {
        $rank = array_flip(array_map('strval', (array) config('media.preparation_order', [])));
        $indexed = array_map(null, $states, array_keys($states));

        usort($indexed, function (array $a, array $b) use ($rank): int {
            $ra = $rank[$a[0]->value] ?? PHP_INT_MAX;
            $rb = $rank[$b[0]->value] ?? PHP_INT_MAX;

            return $ra <=> $rb ?: $a[1] <=> $b[1];
        });

        return array_map(fn (array $pair): PetStateEnum => $pair[0], $indexed);
    }

    /**
     * Everything the pet is entitled to, in generation order: `image`, then the video states.
     *
     * @return list<string>
     */
    public function expectedKeys(Pet $pet): array
    {
        return [
            self::IMAGE,
            ...array_map(fn (PetStateEnum $s) => $s->value, self::order($this->entitlements->videoStatesFor($pet))),
            // M5-T08: transition clips come after every state video (key images are not
            // items of their own — a loop waits for its key image).
            ...array_map(fn (PetTransition $t) => $t->value, $this->entitlements->transitionsFor($pet)),
        ];
    }

    /**
     * The readiness summary (pure read, no personal data).
     *
     * @param  Collection<int, PetMedia>|null  $slots  the pet's media rows when already loaded
     */
    public function summary(Pet $pet, ?Collection $slots = null): PreparationPayload
    {
        $slots ??= $pet->relationLoaded('media') ? $pet->getRelation('media') : $pet->media()->get();
        $image = $slots->first(fn (PetMedia $m) => $m->isImage());

        // No AI media for this pet (breed without appearance data, AI media off at
        // creation) or no generation possible: nothing will come — ready. Not "no
        // image row": queueStateVideos() creates a pending image slot at birth even
        // for such a pet; `media_status` stays `disabled` until an image is stored.
        $nothingComing = ($pet->media_status === 'disabled' && ! ($image?->isServable() ?? false)) || ! $this->fal->isEnabled();

        $pending = [];
        $inProgress = [];
        foreach ($this->expectedKeys($pet) as $key) {
            $transition = PetTransition::tryFrom($key);
            $slot = match (true) {
                $key === self::IMAGE => $image,
                $transition !== null => $slots->first(fn (PetMedia $m) => $m->isTransition() && $m->state === $key),
                default => $slots->first(fn (PetMedia $m) => $m->isVideo() && $m->state === $key),
            };

            if ($slot?->isServable()) {
                continue;
            }
            $pending[] = $key;

            // M5-T08: a loop / transition whose key image failed waits for the daily retry too.
            $poses = match (true) {
                $transition !== null => [$transition->fromState()->value, $transition->toState()->value],
                $key === self::IMAGE => [],
                default => [$key],
            };
            $keyFailed = $slots->contains(fn (PetMedia $m) => $m->isKeyframe() && in_array($m->state, $poses, true) && $m->status === PetMedia::STATUS_FAILED);

            $failed = $slot?->status === PetMedia::STATUS_FAILED
                || $keyFailed
                || ($key === self::IMAGE && $slot === null && $pet->media_status === 'failed');
            if (! $nothingComing && ! $failed) {
                $inProgress[] = $key;
            }
        }

        if ($nothingComing) {
            $pending = [];
        }

        $essentials = array_map('strval', (array) config('media.preparation_essentials', []));
        $active = $pet->preparation_started_at !== null && $pet->preparation_ready_at === null;
        $screenOpen = $active && $pet->preparation_started_at->greaterThan(now()->subMinutes(self::SCREEN_MAX_MINUTES));

        return new PreparationPayload(
            ready: $pending === [],
            pending: $pending,
            preparing: $screenOpen && array_intersect($inProgress, [self::IMAGE, ...$essentials]) !== [],
            running: $active,
            readyAt: $pet->preparation_ready_at?->copy()->utc()->toIso8601String(),
            announce: $this->announces($pet),
        );
    }

    /**
     * The end of the pet's preparation is news (the `pet_ready` event, the push and
     * the in-app »Vse je pripravljeno …«): it ended, it lasted long enough for the
     * screen to have been seen (≥ MIN_SHOWN_SECONDS) and not longer than
     * PUSH_MAX_HOURS (a clip stored by the next day's retry is no news). The apps
     * additionally show the message only within 12 h of `ready_at`.
     */
    public function announces(Pet $pet): bool
    {
        if ($pet->preparation_started_at === null || $pet->preparation_ready_at === null) {
            return false;
        }
        $seconds = (int) $pet->preparation_started_at->diffInSeconds($pet->preparation_ready_at, true);

        return $seconds >= self::MIN_SHOWN_SECONDS && $seconds <= self::PUSH_MAX_HOURS * 3600;
    }

    /**
     * Birth (first contract), or the purchase of a pet that waited behind the
     * payment lock (`$reason` = purchase — the caller decides it waited, QA M1):
     * if something is still being made, the pet's preparation starts now (the
     * apps show it; its end is announced once). Never for a pet born before
     * SINCE. Idempotent: a pet whose preparation started — or ended — before is
     * left alone. Never generates.
     *
     * @param  self::BEGIN_*  $reason
     */
    public function begin(Pet $pet, string $reason = self::BEGIN_BIRTH, ?CarbonImmutable $now = null): bool
    {
        // A pet waiting for the purchase is behind the lock screen: its preparation
        // starts with the purchase (ChallengeService::markPaid), so the child sees it.
        if ($pet->isUnborn() || ! $pet->is_active || $pet->awaitsPayment()
            || $pet->preparation_started_at !== null || $pet->preparation_ready_at !== null
            || $pet->born_at->lessThan(CarbonImmutable::parse(self::SINCE))) {
            return false;
        }
        // At birth only right after the contract (a late call — retry, backfill — is no birth).
        if ($reason === self::BEGIN_BIRTH && $pet->born_at->lessThan(now()->subMinutes(self::SCREEN_MAX_MINUTES))) {
            return false;
        }

        $summary = $this->summary($pet, $pet->media()->get());
        if ($summary->ready || $summary->pending === []) {
            return false;
        }

        $started = Pet::whereKey($pet->id)
            ->whereNull('preparation_started_at')
            ->whereNull('preparation_ready_at')
            ->update(['preparation_started_at' => ($now ?? CarbonImmutable::now())->utc()]) === 1;

        if ($started) {
            $pet->refresh();
            // The last item may have been stored between the check and the update
            // (its settle() saw no preparation yet): settle now, so it never hangs.
            $this->settle($pet->id);
        }

        return $started;
    }

    /**
     * A media item of the pet was stored (StorePetMedia / look link, after
     * commit): if the pet's preparation is running and now everything is
     * stored, mark it ready ONCE (row lock) — and, when announces(), one
     * PetUpdated `pet_ready` and one push to the caretaker children and the
     * family's parents. Never generates.
     */
    public function settle(int $petId): bool
    {
        $pet = Pet::find($petId);
        if ($pet === null || $pet->preparation_started_at === null || $pet->preparation_ready_at !== null) {
            return false;
        }

        if (! $this->summary($pet, $pet->media()->get())->ready) {
            return false;
        }

        return DB::transaction(function () use ($petId): bool {
            $locked = Pet::whereKey($petId)->lockForUpdate()->first();
            if ($locked === null || $locked->preparation_started_at === null || $locked->preparation_ready_at !== null) {
                return false;
            }

            $locked->forceFill(['preparation_ready_at' => now()])->saveQuietly();

            Log::info('Pet preparation finished', [
                'pet_id' => $locked->id,
                'seconds' => (int) $locked->preparation_started_at->diffInSeconds($locked->preparation_ready_at, true),
            ]);

            // News only when the preparation was seen and is still fresh (announces());
            // otherwise the readiness change rides on the item's own `video_ready`.
            if ($this->announces($locked)) {
                PetUpdated::afterCommit($locked, self::EVENT_READY);
                app(NotificationService::class)->petReady($locked);
            }

            return true;
        });
    }

    /**
     * How long the pet's media took (admin panel): preparation (birth / purchase →
     * last item stored, what the child waits for) and the pipeline span of the
     * current files (first slot created → last stored; includes the reference image
     * made at pairing, before the contract).
     *
     * @return array{preparation_seconds: int|null, pipeline_seconds: int|null, started_at: CarbonImmutable|null, ready_at: CarbonImmutable|null}
     */
    public function timing(Pet $pet): array
    {
        $started = $pet->preparation_started_at ? CarbonImmutable::instance($pet->preparation_started_at) : null;
        $ready = $pet->preparation_ready_at ? CarbonImmutable::instance($pet->preparation_ready_at) : null;

        $stored = $pet->media()->whereNotNull('storage_path')->whereNotNull('completed_at');
        $first = (clone $stored)->min('created_at');
        $last = (clone $stored)->max('completed_at');

        return [
            'preparation_seconds' => $started !== null && $ready !== null ? (int) $started->diffInSeconds($ready, true) : null,
            'pipeline_seconds' => $first !== null && $last !== null
                ? (int) CarbonImmutable::parse($first)->diffInSeconds(CarbonImmutable::parse($last), true)
                : null,
            'started_at' => $started,
            'ready_at' => $ready,
        ];
    }
}
