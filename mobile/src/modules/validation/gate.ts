/**
 * M5-08 admin switch »Validation signals« (David 2026-11-22: Off by default / test families / All) —
 * lets David test the app part in production before the privacy policy names it. Pure rules; the
 * dashboard, the auth screens and the H6 hook only wire them up.
 *
 *  - Dashboard features (acquisition card, »Dobili smo pravo žival« row, H6 signal): only when the
 *    family's `validation_signals` is true. The dashboard's own field wins when present, else
 *    `GET /api/user`; missing / not a boolean = off (hidden, nothing sent).
 *  - Exception (GDPR): a parent who left an e-mail (`phase2_interest.notify` true) always gets the row
 *    in its thank-you state, so »Ne obveščajte me več« stays reachable with the switch off.
 *  - Sign-up picker: only when the public `validation_signals_signup` is true (mode »All«); absent,
 *    false or a failed request = hidden — never blocks sign-up.
 */

import type { Phase2InterestState } from './phase2';

/**
 * `GET /api/parent/dashboard` → top-level `validation_signals`; null = the payload has no boolean.
 * HAND-TYPED read (feat/M5-08-validation-signals-switch, draft) — switch to the generated type once merged.
 */
export function readDashboardValidationFlag(dashboard: unknown): boolean | null {
  if (typeof dashboard !== 'object' || dashboard === null || !('validation_signals' in dashboard)) return null;
  const value = (dashboard as { validation_signals: unknown }).validation_signals;
  return typeof value === 'boolean' ? value : null;
}

/** `GET /api/user` → `validation_signals`; null = missing (older server / not loaded). */
export function readUserValidationFlag(user: unknown): boolean | null {
  if (typeof user !== 'object' || user === null || !('validation_signals' in user)) return null;
  const value = (user as { validation_signals: unknown }).validation_signals;
  return typeof value === 'boolean' ? value : null;
}

/** Should the app ask `GET /api/user` for the flag? Only once the dashboard loaded without one. */
export function needsUserValidationFlag(dashboardLoaded: boolean, dashboardFlag: boolean | null): boolean {
  return dashboardLoaded && dashboardFlag === null;
}

/** The family's switch: dashboard field first, then `GET /api/user`, else off. */
export function validationSignalsOn(dashboardFlag: boolean | null, userFlag: boolean | null): boolean {
  return (dashboardFlag ?? userFlag) === true;
}

export type Phase2RowMode = 'hidden' | 'full' | 'withdrawOnly';

/**
 * The »Dobili smo pravo žival« row: `full` with the switch on (needs `phase2_interest` and a child —
 * the H7 entry); `withdrawOnly` with the switch off while the parent still has a live consent (the
 * screen then only shows the thanks + »Ne obveščajte me več«, no »viewed« signal); else hidden.
 */
export function phase2RowMode(on: boolean, state: Phase2InterestState | null, hasChildren: boolean): Phase2RowMode {
  if (state === null) return 'hidden';
  if (on && hasChildren) return 'full';
  if (state.submitted && state.notify === true) return 'withdrawOnly';
  return 'hidden';
}

/** Public flags → show the sign-up picker? Only an explicit `validation_signals_signup: true`. */
export function signupPickerVisible(features: unknown): boolean {
  if (typeof features !== 'object' || features === null || !('validation_signals_signup' in features)) return false;
  return (features as { validation_signals_signup: unknown }).validation_signals_signup === true;
}
