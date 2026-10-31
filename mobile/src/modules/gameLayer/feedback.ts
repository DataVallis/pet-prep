/**
 * FeedbackLayer timing (GAMES_SPEC §3.1, §7.1), pure — the component animates it with
 * reanimated and runs its mask callbacks / promise on these numbers.
 *
 * - `ok` ✓ (mint), `miss` ✗ (amber — never red / raspberry, never shaming), `star` ★,
 *   `round` (round card). Enter 150 ms, hold, exit 250 ms.
 * - `mask: true` dims the stage to ≥ 85 % with a 120 pt glyph: the stage is MASKED from the end
 *   of the enter until the start of the exit — the only time ClipQueue may cut (§3.2 rule 6).
 * - Reduce motion: no scale (fade only); the times stay, so the game logic doesn't change.
 */

export type FeedbackKind = 'ok' | 'miss' | 'star' | 'round';

export const FEEDBACK_ENTER_MS = 150;
export const FEEDBACK_EXIT_MS = 250;
/** Mask opacity (§3.2: a cut happens only under ≥ 85 %). */
export const MASK_OPACITY = 0.88;
/** Default time on screen (enter + hold + exit) per kind. */
export const FEEDBACK_TOTAL_MS: Readonly<Record<FeedbackKind, number>> = { ok: 1_000, miss: 900, star: 1_000, round: 2_500 };

export interface FeedbackOptions {
  /** Short label under the glyph (i18n text, ≤ 32 chars). */
  label?: string;
  /** Dim the stage ≥ 85 % (allows a cut under it). */
  mask?: boolean;
  /** Total time on screen (ms); default per kind. */
  totalMs?: number;
}

export interface FeedbackTimeline {
  enterMs: number;
  holdMs: number;
  exitMs: number;
  /** Masked window (ms from the start), or null without a mask. */
  maskFrom: number | null;
  maskUntil: number | null;
  totalMs: number;
}

export function feedbackTimeline(kind: FeedbackKind, options: FeedbackOptions = {}): FeedbackTimeline {
  const total = Math.max(FEEDBACK_ENTER_MS + FEEDBACK_EXIT_MS, options.totalMs ?? FEEDBACK_TOTAL_MS[kind]);
  const holdMs = total - FEEDBACK_ENTER_MS - FEEDBACK_EXIT_MS;
  const masked = options.mask === true;
  return {
    enterMs: FEEDBACK_ENTER_MS,
    holdMs,
    exitMs: FEEDBACK_EXIT_MS,
    maskFrom: masked ? FEEDBACK_ENTER_MS : null,
    maskUntil: masked ? FEEDBACK_ENTER_MS + holdMs : null,
    totalMs: total,
  };
}

/** Starting scale of the glyph (pops to 1); reduce motion: no scale at all. */
export function feedbackStartScale(kind: FeedbackKind, reduceMotion: boolean): number {
  if (reduceMotion) return 1;
  return kind === 'round' ? 0.92 : 0.6;
}
