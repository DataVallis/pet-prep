<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\HygieneEventKind;
use App\Models\Pet;
use App\Models\PetHygieneEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The `behaviour` object of a pet (M5-R02, David 2026-10-06) in the child
 * state, the parent dashboard and PetUpdated. Typed so Scramble documents
 * it (mobile/src/api/schema.ts). Pet facts only — no personal data.
 *
 * - `take_out`: the puppy's bladder clock ("Pelji ven"); null for a pet
 *   without one (legacy profile, unborn, past the puppy stage).
 * - `active_events`: every open mess (poop, puppy accident, chewing) with
 *   its 2-hour deadline (counted outside quiet hours); empty while hygiene
 *   shows more than 0 %.
 * - `scene`: the behaviour video to show now — the newest open accident,
 *   chewing or (cat, M5-R06-05) scratching event; null otherwise (a cat's
 *   litter accident has no video). `pet_state` stays the six classic
 *   states (a dirty dog is `sick`), so old app builds are unaffected; the
 *   app plays `media.videos[scene]` when it exists and falls back to the
 *   `pet_state` video (free mutt: icons only).
 *
 * Instants are ISO 8601 in the family timezone.
 */
final class BehaviourPayload
{
    /**
     * @param  array{hold_hours: int, clock_started_at: string, next_due_at: string, last_taken_out_at: string|null}|null  $takeOut
     * @param  list<array{id: int, kind: 'poop'|'accident'|'chewing'|'litter_accident'|'scratching', started_at: string, due_at: string}>  $activeEvents
     */
    public function __construct(
        public readonly ?array $takeOut,
        public readonly array $activeEvents,
        public readonly ?string $scene,
    ) {}

    public static function for(Pet $pet, ?CarbonInterface $now = null): self
    {
        $now = CarbonImmutable::instance($now ?? now());
        $behaviour = app(BehaviourEventService::class);
        $ledger = app(RoutineLedgerService::class);
        $tz = $pet->familyTimezone();
        $quiet = $pet->quietHours();
        $iso = fn (?CarbonInterface $at): ?string => $at === null ? null : CarbonImmutable::instance($at)->setTimezone($tz)->toIso8601String();

        $takeOut = null;
        $clock = $behaviour->pottyClock($pet, $quiet);
        if ($clock !== null) {
            $last = $pet->activities()
                ->where('activity_type', ActivityType::TookOutPet->value)
                ->latest('created_at')
                ->value('created_at');

            $takeOut = [
                // Hours the puppy holds today (~1 h per month of age, S30 / S31).
                'hold_hours' => $clock['hold_hours'],
                // When the clock (re)started: take-out, accident, birth, local midnight or end of a freeze.
                'clock_started_at' => $iso($clock['started_at']),
                // Accident due at (counted only outside quiet hours; never inside them).
                'next_due_at' => $iso($clock['due_at']),
                'last_taken_out_at' => $iso($last !== null ? CarbonImmutable::parse($last, 'UTC') : null),
            ];
        }

        $events = [];
        $scene = null;
        if ($pet->displayMetric('hygiene_level') <= 0 && ! $pet->isUnborn()) {
            foreach (app(HygieneEventService::class)->openEvents($pet) as $event) {
                /** @var PetHygieneEvent $event */
                $started = CarbonImmutable::instance($event->scheduled_at)->utc();
                $events[] = [
                    'id' => $event->id,
                    'kind' => $event->kind->value,
                    'started_at' => $iso($started),
                    'due_at' => $iso($ledger->addSecondsOutsideQuiet($quiet, $started, RoutineLedgerService::CLEAN_WITHIN_SECONDS)),
                ];
                // M5-R06-05: the cat's litter accident has no video (the app shows an icon).
                if (! in_array($event->kind, [HygieneEventKind::Poop, HygieneEventKind::LitterAccident], true)) {
                    $scene = $event->kind->value; // the newest wins (ordered oldest first)
                }
            }
        }

        return new self($takeOut, $events, $scene);
    }

    /**
     * @return array{take_out: array{hold_hours: int, clock_started_at: string, next_due_at: string, last_taken_out_at: string|null}|null, active_events: list<array{id: int, kind: 'poop'|'accident'|'chewing'|'litter_accident'|'scratching', started_at: string, due_at: string}>, scene: 'accident'|'chewing'|'scratching'|null}
     */
    public function toArray(): array
    {
        return [
            // Puppy bladder clock ("Pelji ven"); null when the pet has none.
            'take_out' => $this->takeOut,
            /**
             * Open messes, oldest first. kind: poop | accident | chewing (dog);
             * litter_accident | scratching (cat, M5-R06-05).
             *
             * @var list<array{id: int, kind: 'poop'|'accident'|'chewing'|'litter_accident'|'scratching', started_at: string, due_at: string}>
             */
            'active_events' => $this->activeEvents,
            /**
             * Behaviour video to show now (newest open accident / chewing), else null.
             *
             * @var 'accident'|'chewing'|'scratching'|null
             */
            'scene' => $this->scene,
        ];
    }
}
