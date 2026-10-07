/**
 * "Obroki danes" line of the child HUD (M5-R04) — pure, tested in
 * `__tests__/mealWindows.test.ts`.
 *
 * Today's feed windows of the pet's stage (`feeding.today`, family-local "HH:MM") as a
 * calm chip row near the feed button: "7–9 ✓ · 11–13 · 15–17 nahrani starš · 19–21".
 * - current window (server `feeding.current_window`) highlighted;
 * - ✓ per window from the server's `fed` (a child meal or the parent's quiet-hours meal
 *   logged inside it today); the current window also ticks from `fed_in_current_window`,
 *   which the optimistic feed sets before the state refreshes;
 * - an ended window without a meal is only dimmed ("past"), never shown as missed;
 * - windows in quiet hours are labelled "nahrani starš" (the parent feeds them).
 * Times are family-local (PRODUCT_SPEC §4) — the windows already are; instants
 * (`server_time`, `current_window`) go through `localParts` with the family zone.
 */

import { localParts } from '@/modules/childPet/familyTime';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { strings } from '@/i18n/strings';

/** User-visible strings (`child:meals`, M1-18); `done` / `now` / `past` are screen-reader words. */
export const MEAL_STRINGS = strings('child', 'meals');

export interface MealWindowItem {
  /** "07:00-09:00" (stable key). */
  key: string;
  /** "7–9", "7:30–9". */
  label: string;
  done: boolean;
  current: boolean;
  /** Ended without a meal (dimmed, never "missed"). */
  past: boolean;
  byParent: boolean;
}

/** "07:00" → "7", "07:30" → "7:30". */
export function shortClock(hhmm: string): string {
  const [h, m] = hhmm.split(':');
  const hour = String(Number(h));
  return m === '00' ? hour : `${hour}:${m}`;
}

export function buildMealWindows(view: ChildPetView): MealWindowItem[] {
  const tz = view.timezone;
  const now = localParts(view.server_time, tz);
  // Today's windows all START today (family-local): a window that opened yesterday (22–02
  // at 01:00) is not tonight's chip, so the start must be on today's date as well.
  const currentParts = view.feeding.current_window ? localParts(view.feeding.current_window.start, tz) : null;
  const currentStart = currentParts !== null && now !== null && currentParts.date === now.date ? currentParts.time : null;

  return view.feeding.today.map((w) => {
    const current = currentStart !== null && currentStart === w.start;
    // `fed_in_current_window` covers the optimistic feed until the refreshed state arrives.
    const done = w.fed || (current && view.feeding.fed_in_current_window);
    const past = !current && !done && now !== null && w.end > w.start && now.time >= w.end;
    return {
      key: `${w.start}-${w.end}`,
      label: `${shortClock(w.start)}–${shortClock(w.end)}`,
      done,
      current,
      past,
      byParent: w.parent_covered,
    };
  });
}

/** One sentence for the screen reader: "Obroki danes: 7–9 nahranjen, 11–13 nahrani starš, 15–17 zdaj, 19–21". */
export function mealWindowsA11y(items: readonly MealWindowItem[]): string {
  const parts = items.map((item) => {
    const words = [
      item.label,
      item.done ? MEAL_STRINGS.done : null,
      item.byParent ? MEAL_STRINGS.byParent : null,
      item.current && !item.done ? MEAL_STRINGS.now : null,
      item.past ? MEAL_STRINGS.past : null,
    ].filter((x): x is string => x !== null);
    return words.join(' ');
  });
  return `${MEAL_STRINGS.title}: ${parts.join(', ')}`;
}
