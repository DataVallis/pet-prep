/**
 * Cuddles v2 — the stage (GAMES_SPEC §4.4, §8.1 »Crkljanje: hub = zanka playing / idle«), pure:
 * ONE role, the pet's `playing` state loop (else `idle`, the usual fallback chain). Without a
 * video — or in »Slika« (M5-T11) — the reference image; the game needs no clock gating.
 */

import type { ClipSpec } from '@/modules/gameLayer/clipQueue';
import type { DeckPlan } from '@/modules/gameLayer/deck';
import { selectMediaSource, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import type { MediaMode } from '@/modules/petMedia/statePlayer';

export function cuddleDeck(media: PetMediaInfo, mode: MediaMode): DeckPlan {
  const source = selectMediaSource(media, 'playing');
  const url = mode === 'video' && source.kind === 'video' ? source.url : null;
  const still = typeof media.referenceImageUrl === 'string' && media.referenceImageUrl.length > 0 ? media.referenceImageUrl : null;
  const spec: ClipSpec = { role: 'hub', loop: true, startPose: 'stand', endPose: 'stand', durationMs: 0, endGraceMs: 0 };
  return { clips: [{ role: 'hub', url, loop: true, startStill: still, picture: still }], specs: [spec], initial: 'hub', mode };
}

/** Whether the stage has anything to show (else the game draws the pet's emoji). */
export function cuddleHasPicture(media: PetMediaInfo): boolean {
  return selectMediaSource(media, 'playing').kind !== 'placeholder';
}
