/**
 * Short hints under the HUD dock buttons (device feedback 2026-10-07: "tomorrow at 0…" —
 * the time was ellipsised at 375 pt). A dock hint is at most two short lines and the time
 * is always a line of its own, so it can never be cut:
 *
 * - today:      "ob 17:00" / "at 17:00"            (one line)
 * - tomorrow:   "jutri" / "tomorrow" above "06:00"  (two lines: `day` + `text`)
 * - other text: "Najprej pospravi", "Čisto", "4.857/4.000" (one or two lines)
 *
 * `a11y` keeps the full phrase for screen readers ("jutri ob 06:00"); toasts and refusal
 * texts keep using `whenText` (full sentences).
 */

import { t } from '@/i18n';
import { localParts } from '@/modules/childPet/familyTime';

export interface DockHint {
  /** Small day line above the main line ("jutri" / "tomorrow"); null = today / no day. */
  day: string | null;
  /** Main line: a time ("06:00", "ob 17:00") or a short text. */
  text: string;
  /** Full phrase for the screen reader ("jutri ob 06:00"). */
  a11y: string;
}

/** A plain one-line hint (no day line). */
export function dockText(text: string): DockHint {
  return { day: null, text, a11y: text };
}

/** Accepts a plain string too (callers that only have text, e.g. the walk counter). */
export function toDockHint(hint: string | DockHint | null | undefined): DockHint | null {
  if (hint === null || hint === undefined) return null;
  if (typeof hint === 'string') return hint.length > 0 ? dockText(hint) : null;
  return hint;
}

/**
 * Dock hint for an instant in the family zone: "ob 17:00" today, "jutri" + "06:00" on a
 * later family day (the child only needs the hour — same rule as `whenText`). Null for a
 * missing / broken instant.
 */
export function dockWhen(iso: string | null, nowIso: string | null, timeZone: string | null): DockHint | null {
  if (!iso) return null;
  const target = localParts(iso, timeZone);
  if (!target) return null;
  const now = nowIso ? localParts(nowIso, timeZone) : null;
  if (now && target.date > now.date) {
    return {
      day: t('child:hints.tomorrow'),
      text: target.time,
      a11y: t('child:time.tomorrowAt', { time: target.time }),
    };
  }
  const at = t('child:time.at', { time: target.time });
  return { day: null, text: at, a11y: at };
}
