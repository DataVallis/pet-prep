<?php

namespace App\Services;

use App\Enums\PetStateEnum;
use App\Models\Pet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The `play` object of a pet (M5-R05, PLAY_CUDDLE_SPEC §12.5) in the child
 * state and PetUpdated; null for a pet without play (free mutt, legacy pet,
 * waiting for payment, unborn, game over). Typed so Scramble documents it.
 * Pet facts only — no personal data.
 *
 * - `can_play`: POST /api/child/pet/play would be accepted now (for the
 *   viewing child: also their own contract; pet level in the broadcast).
 * - `invitation`: the dog's invitation shown now (§12.3), else null.
 * - `mood.happy_until`: end of the 30-minute happy scene after a play
 *   (null when not happy now); `mood.scene` = `playing` while happy and
 *   nothing more important shows — not locked, `pet_state` idle or playing
 *   (a need, sleep or a mess always wins; a behaviour scene implies a mess,
 *   i.e. `sick`). `pet_state` and `behaviour.scene` are unchanged.
 *
 * Instants are ISO 8601 in the family timezone.
 */
final class PlayPayload
{
    /**
     * @param  array{id: int, kind: 'play'|'cuddle', expires_at: string}|null  $invitation
     */
    public function __construct(
        public readonly bool $canPlay,
        public readonly ?array $invitation,
        public readonly ?string $happyUntil,
        public readonly ?string $scene,
    ) {}

    public static function for(Pet $pet, ?User $viewer = null, ?CarbonInterface $now = null): ?self
    {
        $service = app(PlayService::class);
        $now = CarbonImmutable::instance($now ?? now());
        if (! $service->eligible($pet, $now)) {
            return null;
        }

        $tz = $pet->familyTimezone();
        $iso = fn (CarbonInterface $at): string => CarbonImmutable::instance($at)->setTimezone($tz)->toIso8601String();

        $locked = $pet->actionLockReasonFor($viewer) !== null;
        $canPlay = ! $locked && $service->canPlayNow($pet, $now);
        $offered = $canPlay ? $service->offeredInvitation($pet, $now) : null;

        $happy = $pet->happy_until !== null && $pet->happy_until->greaterThan($now);
        $scene = $happy
            && $pet->actionLockReason() === null
            && in_array($pet->pet_state, [PetStateEnum::Idle, PetStateEnum::Playing], true)
            ? 'playing'
            : null;

        return new self(
            $canPlay,
            $offered === null ? null : [
                'id' => $offered->id,
                'kind' => $offered->kind->value,
                'expires_at' => $iso($offered->expires_at),
            ],
            $happy ? $iso($pet->happy_until) : null,
            $scene,
        );
    }

    /**
     * @return array{can_play: bool, invitation: array{id: int, kind: 'play'|'cuddle', expires_at: string}|null, mood: array{happy_until: string|null, scene: 'playing'|null}}
     */
    public function toArray(): array
    {
        return [
            // POST /api/child/pet/play would be accepted now.
            'can_play' => $this->canPlay,
            /**
             * The dog's invitation shown now (kind: play | cuddle), else null.
             *
             * @var array{id: int, kind: 'play'|'cuddle', expires_at: string}|null
             */
            'invitation' => $this->invitation,
            'mood' => [
                // End of the happy scene (30 min after the last play); null when not happy.
                'happy_until' => $this->happyUntil,
                /**
                 * Video scene: `playing` while happy and no lock / need / sleep shows.
                 *
                 * @var 'playing'|null
                 */
                'scene' => $this->scene,
            ],
        ];
    }
}
