/**
 * M5-T11 »Prikaz: Video / Slika« (APP_REFINEMENT_SPEC §1) — how the child app shows the pet.
 *
 * - `video` (default): the state loops and transitions play (`PetMediaView mode="video"`).
 * - `picture`: the KEY IMAGE of the current state (§7, M5-T08 `media.keyframes`; idle = the
 *   reference image; a pet without key images — older set / v1 — shows its reference image).
 *   No video is mounted, so none is downloaded (saves mobile data). Transitions are skipped.
 *
 * Stored per child profile on the server (`users.media_display`): the child changes it in the
 * HUD settings sheet (`PATCH /api/child/settings`), a parent for the child in the family card
 * (`PATCH /api/parent/children/{child}/settings`). Read it from the child state
 * (`view.settings.media_display`) — never keep a local copy.
 *
 * Claude's proposal (M5-T11, waits for David): in picture mode school and games show the
 * pose image of the phase (school: `playing` key image while the dog obeys, else idle; play /
 * cat games: the `playing` key image) with the app-animated layers (ball, wand, cue) on top —
 * no clips there either.
 */

import type { MediaMode } from '@/modules/petMedia/statePlayer';

export type MediaDisplay = MediaMode;

export const MEDIA_DISPLAYS: readonly MediaDisplay[] = ['video', 'picture'];

/** Any payload value → a display; unknown / missing (older server) → `video`. */
export function readMediaDisplay(value: unknown): MediaDisplay {
  return value === 'picture' ? 'picture' : 'video';
}

/** The `PetMediaView` mode for a child view (null / missing view → video). */
export function mediaModeOf(view: { settings?: { media_display?: MediaDisplay } } | null | undefined): MediaMode {
  return view?.settings?.media_display === 'picture' ? 'picture' : 'video';
}
