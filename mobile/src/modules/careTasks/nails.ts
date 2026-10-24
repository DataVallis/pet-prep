/**
 * »Pristriži kremplje« (M5-R19, David 2026-10-24: nails = tap each claw) — pure logic.
 *
 * The server's rule is the brushing check (`CatChoreService::score`): ≥ `min_strokes` taps
 * (8 = the claws of both front paws) ≥ `min_stroke_interval_ms` apart, one segment, not
 * machine-regular. Each claw can be trimmed once; a tap closer than the interval to the last
 * counted one is ignored (the server would count it once anyway), so "all claws trimmed"
 * always means the server's count is reached. The game finishes up to 2 s before the end
 * once every claw is trimmed (inside the server's 3 s tolerance), else at the end.
 */

import { CARE_EARLY_FINISH_LEAD_MS } from '@/modules/careTasks/careTasks';
import type { ChoreRules } from '@/modules/catCare/catGames';

export interface NailsState {
  /** ms of the trim per claw (index = claw), null = not yet. */
  trimmed: readonly (number | null)[];
  lastMs: number | null;
}

export function clawCount(rules: Pick<ChoreRules, 'min_strokes'>): number {
  return Math.max(1, Math.min(20, Math.round(rules.min_strokes)));
}

export function initialNails(rules: Pick<ChoreRules, 'min_strokes'>): NailsState {
  return { trimmed: Array.from({ length: clawCount(rules) }, () => null), lastMs: null };
}

/** Trim claw `index` at `ms`; unchanged when it is already trimmed, out of time or too soon. */
export function trimClaw(state: NailsState, index: number, ms: number, rules: ChoreRules): { state: NailsState; stroke: number | null } {
  const t = Math.round(ms);
  if (index < 0 || index >= state.trimmed.length || state.trimmed[index] !== null) return { state, stroke: null };
  if (!Number.isFinite(t) || t < 0 || t > rules.duration_ms) return { state, stroke: null };
  if (state.lastMs !== null && t - state.lastMs < rules.min_stroke_interval_ms) return { state, stroke: null };
  const trimmed = state.trimmed.slice();
  trimmed[index] = t;
  return { state: { trimmed, lastMs: t }, stroke: t };
}

/** The first claw that is still long (for the hold / screen-reader button); -1 = all done. */
export function nextClaw(state: NailsState): number {
  return state.trimmed.findIndex((t) => t === null);
}

export function allTrimmed(state: NailsState): boolean {
  return nextClaw(state) === -1;
}

/** Taps (the server's input) as the game recorded them. */
export function nailTaps(state: NailsState): number[] {
  return state.trimmed.filter((t): t is number => t !== null).sort((a, b) => a - b);
}

/** When the game finishes: up to 2 s early once every claw (= `min_strokes` taps) is in, else at the end. */
export function nailsFinishAt(rules: ChoreRules, taps: readonly number[]): number {
  return taps.length >= clawCount(rules) ? Math.max(0, rules.duration_ms - CARE_EARLY_FINISH_LEAD_MS) : rules.duration_ms;
}
