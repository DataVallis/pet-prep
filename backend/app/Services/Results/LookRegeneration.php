<?php

namespace App\Services\Results;

/**
 * What "Regenerate media" of a shared look did (M5-R21,
 * PetMediaService::regenerateLook) — the admin sees why nothing happened.
 */
final class LookRegeneration
{
    public const QUEUED = 'queued';

    /** fal is disabled on the server (no key / profile). */
    public const AI_OFF = 'ai_off';

    /** No active pet uses the look — nothing to show the new files on, nothing reset. */
    public const NO_ACTIVE_PETS = 'no_active_pets';

    /** A file of the look is being generated right now (running / submitted to fal). */
    public const BUSY = 'busy';

    /**
     * @param  self::QUEUED|self::AI_OFF|self::NO_ACTIVE_PETS|self::BUSY  $outcome
     * @param  list<string>  $stages  life stages whose look files were reset
     */
    public function __construct(
        public readonly string $outcome,
        public readonly int $pets = 0,
        public readonly array $stages = [],
    ) {}

    public function queued(): bool
    {
        return $this->outcome === self::QUEUED;
    }
}
