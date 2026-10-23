/**
 * M5-T17 »Lažje kretnje« — pure helpers of the hold alternatives that are not brushing
 * (brushing / the litter scrub reuse `catCare/brushing.ts`).
 *
 * Every helper only produces the SAME inputs the swipe produced before:
 * - wand (cat): `{t, away: true}` moves — scored by the unchanged `WandPlayService::score`
 *   (≥ 300 ms apart, spread over the quarters, an answer to every pounce, not machine-regular);
 * - ball (dog): one throw per release — the game still has 3 throws and reports one finished
 *   "play"; the power only changes how far the drawn ball flies, never the timing.
 */

import { WAND_UNIFORM_MAX_SPREAD_MS } from '@/modules/catCare/catGames';

// ── Wand: the feather runs from the cat while a finger is held ──

/**
 * Gaps between the feather's moves while held. Irregular on purpose (the server refuses
 * ≥ 6 gaps within 60 ms of each other), all ≥ the server's 300 ms move interval and well
 * under its 2 s pounce window, so a child who keeps holding answers every pounce.
 */
export const WAND_HOLD_GAPS_MS: readonly number[] = [560, 720, 610, 840, 680, 770, 590, 900];
/** The first move comes this soon after the finger goes down (a tap on the button = 1 move). */
export const WAND_HOLD_FIRST_MS = 380;

// The gaps must never look machine-regular to the server.
if (Math.max(...WAND_HOLD_GAPS_MS) - Math.min(...WAND_HOLD_GAPS_MS) <= WAND_UNIFORM_MAX_SPREAD_MS) {
  throw new Error('WAND_HOLD_GAPS_MS must vary more than the server uniform spread');
}

/** The gap after the `n`-th held move (0-based). */
export function wandHoldGap(n: number): number {
  const len = WAND_HOLD_GAPS_MS.length;
  const i = ((Math.floor(n) % len) + len) % len;
  return WAND_HOLD_GAPS_MS[i];
}

/**
 * Where the feather goes after the `n`-th held move (normalised 0…1 of the stage): it zigzags
 * left / right and climbs away from the cat (who sits at the bottom centre), then starts again
 * low — so every move looks like an escape.
 */
export function wandHoldFeatherAt(n: number): { x: number; y: number } {
  const k = ((Math.floor(n) % 6) + 6) % 6;
  const side = Math.floor(n) % 2 === 0 ? -1 : 1;
  return { x: 0.5 + side * (0.18 + 0.04 * (k % 3)), y: 0.5 - 0.07 * k };
}

// ── Ball: press-and-hold charges the throw, release throws ──

/** Holding this long charges a full-power throw. */
export const BALL_CHARGE_MS = 1_200;

/** Throw power 0…1 for a hold of `heldMs` (a tap / screen-reader activate = the weakest throw). */
export function chargePower(heldMs: number): number {
  if (!Number.isFinite(heldMs) || heldMs <= 0) return 0;
  return Math.min(1, heldMs / BALL_CHARGE_MS);
}

/** How high the drawn ball flies (pt) for a power — only the picture, never the timing. */
export function ballFlightHeight(power: number): number {
  const p = Number.isFinite(power) ? Math.min(1, Math.max(0, power)) : 1;
  return Math.round(120 + 100 * p);
}
