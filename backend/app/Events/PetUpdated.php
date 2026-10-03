<?php

namespace App\Events;

use App\Models\Pet;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PetUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The name of the queue connection to use when broadcasting.
     * Broadcasting is synchronous for real-time updates (no queue delay).
     */
    public string $connection = 'sync';

    public function __construct(
        public readonly Pet $pet,
        public readonly ?string $eventType = null,
    ) {}

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'pet.updated';
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * Channel: pet.updated.{petId}
     * The parent dashboard subscribes to this channel to receive
     * real-time updates whenever any pet metric changes or an
     * activity log entry is saved.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("pet.updated.{$this->pet->id}"),
        ];
    }

    /**
     * Get the data to broadcast with the event.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'pet_id' => $this->pet->id,
            'user_id' => $this->pet->user_id,
            'breed_type' => $this->pet->breed_type->value,
            'hunger_level' => $this->pet->displayMetric('hunger_level'),
            'thirst_level' => $this->pet->displayMetric('thirst_level'),
            'energy_level' => $this->pet->displayMetric('energy_level'),
            'hygiene_level' => $this->pet->displayMetric('hygiene_level'),
            'is_active' => $this->pet->is_active,
            'pet_state' => $this->pet->pet_state->value,
            'escalation_level' => $this->pet->escalation_level,
            'is_ill' => $this->pet->isIll(),
            'is_game_over' => $this->pet->is_game_over,
            'is_hard_stopped' => $this->pet->is_hard_stopped,
            'virtual_age_months' => $this->pet->virtualAgeInMonths(),
            'current_video_url' => $this->pet->current_video_url,
            'media_status' => $this->pet->media_status,
            'reference_image_url' => $this->pet->pet_dna['reference_image_url'] ?? null,
            'event_type' => $this->eventType,
            'updated_at' => $this->pet->updated_at?->toIso8601String(),
        ];
    }
}
