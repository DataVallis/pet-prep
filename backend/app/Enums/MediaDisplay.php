<?php

namespace App\Enums;

/**
 * How the child app shows the pet (M5-T11, APP_REFINEMENT_SPEC §1 »Prikaz: Video / Slika«).
 * Stored per child profile (`users.media_display`), mirrored by the
 * users_media_display_check constraint.
 *
 * A display preference, not personal data: the parent or the child may change it.
 */
enum MediaDisplay: string
{
    /** Default: the state loops and transitions play. */
    case Video = 'video';

    /** The key image of the current state only — no video is downloaded (saves data). */
    case Picture = 'picture';
}
