/**
 * »Zvoki in vibracija v igrah« / "Game sounds and vibration" (GAMES_SPEC §7.6, David
 * 2026-10-30 V5 / V11) — a parent switch per child profile, ON by default.
 *
 * Server: `users.game_feedback` (bool, default true) → child state `settings.game_feedback`,
 * dashboard `family.children[].game_feedback`, `PATCH /api/parent/children/{child}/settings
 * {game_feedback}`. Only a setting — no game rule reads it. An older server sends nothing →
 * on (the default), so the sounds and haptics behave as David decided.
 */

/** Any payload value → the switch; only an explicit `false` turns it off. */
export function readGameFeedback(value: unknown): boolean {
  return value !== false;
}

/** The child view's switch (missing → on). */
export function gameFeedbackOf(view: { settings?: { game_feedback?: boolean } } | null | undefined): boolean {
  return view?.settings?.game_feedback !== false;
}
