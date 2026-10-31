/**
 * What a cleaning shows (GAMES_SPEC §6.2), pure — importable anywhere (no native module).
 *
 * - The open scrub-able events become STAGES in a fixed order: poop first (bag), then the
 *   puddle (cloth). A cat's mess next to the tray (`litter_accident`) is a small pile → the bag,
 *   like a poop. No event info (older server) → one poop stage, as the old game assumed dirt.
 * - Poop pieces are DETERMINISTIC per event: 1–3 from the event id (the same mess looks the
 *   same after a restart or on a sibling's phone), at most 3 on screen. A puddle is one big one.
 * - Display only: the server rules (PRODUCT_SPEC §5 / §11) and the one `POST /clean` at the end
 *   don't change.
 */

import type { BehaviourEvent, BehaviourKind } from '@/modules/behaviour/behaviour';

export type MessStageKind = 'poop' | 'puddle';

export interface MessStage {
  kind: MessStageKind;
  /** Pieces to clean (poop 1–3, puddle 1). */
  pieces: number;
  /** Seed of the layout (the first event's id; 0 without event info). */
  seed: number;
}

/** Events the scrub game never cleans (their own flows: »Pospravi in daj igračo«, »Na praskalnik«). */
const NOT_SCRUBBED: readonly BehaviourKind[] = ['chewing', 'scratching'];
const BAGGED: readonly BehaviourKind[] = ['poop', 'litter_accident'];
export const MAX_POOP_PIECES = 3;

/** A 32-bit integer hash (stable across devices and runs). */
export function hashSeed(n: number): number {
  let h = (Math.trunc(n) | 0) ^ 0x9e3779b9;
  h = Math.imul(h ^ (h >>> 16), 0x85ebca6b);
  h = Math.imul(h ^ (h >>> 13), 0xc2b2ae35);
  h ^= h >>> 16;
  return h >>> 0;
}

/** Poop pieces of one event: 1, 2 or 3, always the same for the same event id. */
export function poopPieceCount(eventId: number): 1 | 2 | 3 {
  return (1 + (hashSeed(eventId) % 3)) as 1 | 2 | 3;
}

/** The stages of one cleaning, in order (poop → puddle); never empty. */
export function messStages(events: readonly Pick<BehaviourEvent, 'id' | 'kind'>[]): MessStage[] {
  const scrub = events.filter((e) => !NOT_SCRUBBED.includes(e.kind));
  const bagged = scrub.filter((e) => BAGGED.includes(e.kind));
  const puddles = scrub.filter((e) => e.kind === 'accident');
  const stages: MessStage[] = [];
  if (bagged.length > 0) {
    const pieces = Math.min(MAX_POOP_PIECES, bagged.reduce((sum, e) => sum + poopPieceCount(e.id), 0));
    stages.push({ kind: 'poop', pieces, seed: bagged[0].id });
  }
  if (puddles.length > 0) stages.push({ kind: 'puddle', pieces: 1, seed: puddles[0].id });
  if (stages.length === 0) stages.push({ kind: 'poop', pieces: poopPieceCount(0), seed: 0 });
  return stages;
}

export interface PiecePlacement {
  /** Centre, as a fraction of the field (0–1). */
  x: number;
  y: number;
  /** Size factor (0.9–1.1). */
  scale: number;
  /** Mirrored (a little variety, still the same swirl). */
  flip: boolean;
}

/** Floor band of the scene (GAMES_SPEC §6.1: the mess lies on the floor, above the hand's dock). */
export const FLOOR_Y = 0.66;
const SLOTS: readonly (readonly number[])[] = [[0.5], [0.34, 0.66], [0.24, 0.5, 0.76]];

/** Where the pieces lie: spread across the floor, with a stable small jitter per seed. */
export function piecePlacements(count: number, seed: number): PiecePlacement[] {
  const n = Math.max(1, Math.min(MAX_POOP_PIECES, Math.trunc(count)));
  return SLOTS[n - 1].map((x, i) => {
    const h = hashSeed(seed * 7 + i + 1);
    return {
      x: x + (((h & 0xff) / 255) - 0.5) * 0.06,
      y: FLOOR_Y + ((((h >>> 8) & 0xff) / 255) - 0.5) * 0.06,
      scale: 0.9 + (((h >>> 16) & 0xff) / 255) * 0.2,
      flip: ((h >>> 24) & 1) === 1,
    };
  });
}

/** One progress dot per piece of every stage (poop pieces, then the puddle). */
export function stageDots(stages: readonly MessStage[], doneStages: number, donePiecesOfCurrent: number): boolean[] {
  const dots: boolean[] = [];
  stages.forEach((stage, i) => {
    for (let p = 0; p < stage.pieces; p++) dots.push(i < doneStages || (i === doneStages && p < donePiecesOfCurrent));
  });
  return dots;
}
