<?php

namespace App\Events;

use App\Models\Pet;
use App\Services\BehaviourPayload;
use App\Services\Media\PetMediaPayload;
use App\Services\PetProfilePayload;
use App\Services\TrainingPayload;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Real-time pet state for the child HUD and the parent dashboard (M1-08/09).
 *
 * - Channel: `PrivateChannel('pet.{id}')` (wire name `private-pet.{id}`),
 *   authorized in routes/channels.php for the owning child and that child's
 *   parent only, via `POST /api/broadcasting/auth` (Sanctum bearer token).
 * - Queued (ShouldBroadcast, never ShouldBroadcastNow) on the `broadcasts`
 *   queue: the scheduler tick and HTTP requests only push a job; a Reverb
 *   outage fails the job in the worker, not the tick.
 * - The payload is a snapshot taken when the change committed (no model is
 *   serialized into the job, so the worker needs no DB read and a deleted pet
 *   can't fail the job). It carries pet state only — no child name, email
 *   or user id.
 *
 * Emit through {@see PetUpdated::afterCommit()} only: exactly one event per
 * meaningful state change, from the service that made the change. There are
 * no model observers that broadcast (DECISIONS 2026-10-04).
 */
class PetUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public const QUEUE = 'broadcasts';

    /**
     * Queue for the BroadcastEvent job (BroadcastManager::queue reads it).
     */
    public string $broadcastQueue = self::QUEUE;

    /**
     * Worker retries: a short Reverb hiccup is retried, a long outage is not
     * worth replaying (stale state; the apps poll as a fallback).
     */
    public int $tries = 3;

    public int $backoff = 2;

    public int $timeout = 15;

    /**
     * @param  array<string, mixed>  $payload  Snapshot from {@see PetUpdated::payloadFor()}.
     */
    public function __construct(
        public readonly int $petId,
        public readonly ?string $eventType,
        public readonly array $payload,
    ) {}

    /**
     * Build the event from a pet's current (committed) state.
     */
    public static function fromPet(Pet $pet, ?string $eventType = null): self
    {
        return new self($pet->id, $eventType, self::payloadFor($pet, $eventType));
    }

    /**
     * The single emission point: queue one PetUpdated once the surrounding
     * transaction commits (immediately when there is none). The snapshot is
     * taken at commit time, so it shows what was written.
     *
     * Never throws: a failing queue / broadcaster is logged and reported, so
     * the decay tick keeps processing the remaining pets and a child action
     * that already committed still returns 200 (M1-09).
     */
    public static function afterCommit(Pet $pet, ?string $eventType = null): void
    {
        DB::afterCommit(function () use ($pet, $eventType): void {
            try {
                broadcast(self::fromPet($pet, $eventType));
            } catch (Throwable $e) {
                Log::error('PetUpdated: broadcast could not be queued', [
                    'pet_id' => $pet->id,
                    'event_type' => $eventType,
                    'error' => $e->getMessage(),
                ]);
                report($e);
            }
        });
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'pet.updated';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(self::channelName($this->petId)),
        ];
    }

    /**
     * Channel name without the `private-` prefix (routes/channels.php).
     */
    public static function channelName(int $petId): string
    {
        return "pet.{$petId}";
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }

    /**
     * Pet state the apps need — displayed (rounded) metrics, flags, media.
     * Documented in ARCHITECTURE.md §4. No child PII.
     *
     * @return array<string, mixed>
     */
    public static function payloadFor(Pet $pet, ?string $eventType = null): array
    {
        $media = PetMediaPayload::for($pet);

        return [
            'pet_id' => $pet->id,
            'breed_type' => $pet->breed_type->value,
            'hunger_level' => $pet->displayMetric('hunger_level'),
            'thirst_level' => $pet->displayMetric('thirst_level'),
            'energy_level' => $pet->displayMetric('energy_level'),
            'hygiene_level' => $pet->displayMetric('hygiene_level'),
            'is_active' => (bool) $pet->is_active,
            'pet_state' => $pet->pet_state->value,
            'escalation_level' => (int) $pet->escalation_level,
            'is_ill' => $pet->isIll(),
            'illness_until' => $pet->illness_until?->toIso8601String(),
            'is_game_over' => (bool) $pet->is_game_over,
            'is_hard_stopped' => (bool) $pet->is_hard_stopped,
            'virtual_age_months' => $pet->virtualAgeInMonths(),
            // M5-R01: the dog's age, origin and life stage (full profile via GET).
            ...PetProfilePayload::brief($pet),
            // Contract before birth (M1-07b): null / true until the child signs.
            'born_at' => $pet->born_at?->toIso8601String(),
            'awaiting_contract' => $pet->isUnborn(),
            // M5-R02: puppy bladder clock, open messes (poop / accident / chewing), behaviour video.
            'behaviour' => BehaviourPayload::for($pet)->toArray(),
            // M5-R03: training progress per command, today's routine, a session running.
            'training' => TrainingPayload::summaryFor($pet)->summary(),
            // AI media (M4-05): signed URLs (≤ 90 min) to our copies — the channel is
            // private to the pet's caretakers and family parents; legacy fields mirror it.
            'current_video_url' => $media->currentVideoUrl,
            'media_status' => $pet->media_status,
            'reference_image_url' => $media->referenceImageUrl,
            'media' => $media->toArray(),
            'event_type' => $eventType,
            'updated_at' => $pet->updated_at?->toIso8601String(),
            'emitted_at' => now()->toRfc3339String(true),
        ];
    }
}
