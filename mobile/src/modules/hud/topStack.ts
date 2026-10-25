/**
 * Child HUD top stack (David 2026-10-25: »pes mora biti viden«) — pure, unit-tested in
 * `__tests__/topStack.test.ts`.
 *
 * Every message card of the HUD (the puppy's take-out deadline, the open-mess card with its
 * button, the "getting ready" notice, play invitation, hints / notes, "Obroki danes") sits in
 * one compact column directly under the header, left of the metric column — the centre and the
 * lower half of the screen stay free for the dog; only the action dock is at the bottom.
 *
 * The column is bounded: the most urgent cards come first and at most
 * {@link TOP_STACK_MAX_VISIBLE} are shown; the rest collapse into a "+N" pill that expands them
 * (and a "Manj" pill collapses them again). The chip row ("Šola", "Igra", breed care, …) is
 * one line of buttons, always visible, under the cards.
 */

import type { TakeOutLevel } from '@/modules/behaviour/behaviour';

/** Cards shown before the rest collapse into "+N". */
export const TOP_STACK_MAX_VISIBLE = 2;

/**
 * Order of the cards (lower = more urgent = higher in the stack). Equal priorities keep the
 * order they were passed in.
 */
export const STACK_PRIORITY = {
  /** Last 30 min of the take-out clock / overdue (red). */
  takeOutUrgent: 0,
  /** Take-out clock in its orange phase. */
  takeOutSoon: 1,
  /** Open mess (accident / chewed slipper / scratched sofa) with its button. */
  scene: 2,
  /** Cat: "first aid" while hungry (C24). */
  firstAid: 3,
  /** Calm (green) take-out clock. */
  takeOutCalm: 4,
  /** The dog's play invitation (accept / maybe later). */
  invitation: 5,
  /** "Your pup is getting ready …" (AI media pending). */
  notice: 6,
  /** Summer midday heat (flat-faced breeds) / digestion after a meal — calm advice. */
  advice: 7,
  /** Why a chip is off ("Kuža spi do 7:00", "Najprej pospravi, potem se igraj", cat notes). */
  hint: 8,
  /** "Kuža je vesel" (mood only). */
  happy: 9,
  /** »Nekaj novega o tvoji pasmi«. */
  fact: 10,
  /** "Obroki danes" — informative, least urgent. */
  meals: 11,
} as const;

export interface StackEntry {
  key: string;
  priority: number;
}

export interface ArrangedStack<T extends StackEntry> {
  /** Cards to render, most urgent first. */
  visible: T[];
  /** Cards collapsed into "+N" (0 when expanded or when everything fits). */
  hiddenCount: number;
  /** True when a toggle ("+N" / "Manj") belongs under the cards. */
  collapsible: boolean;
}

/** Stack priority of the take-out clock by its colour level. */
export function takeOutPriority(level: TakeOutLevel): number {
  switch (level) {
    case 'urgent':
      return STACK_PRIORITY.takeOutUrgent;
    case 'soon':
      return STACK_PRIORITY.takeOutSoon;
    case 'calm':
      return STACK_PRIORITY.takeOutCalm;
  }
}

/**
 * Most urgent first (stable), at most `max` unless `expanded`. Invariant (tested):
 * `visible.length + hiddenCount === entries.length`.
 */
export function arrangeTopStack<T extends StackEntry>(
  entries: readonly T[],
  expanded: boolean,
  max: number = TOP_STACK_MAX_VISIBLE,
): ArrangedStack<T> {
  const sorted = entries
    .map((entry, index) => ({ entry, index }))
    .sort((a, b) => a.entry.priority - b.entry.priority || a.index - b.index)
    .map(({ entry }) => entry);
  const limit = Math.max(1, Math.floor(max));
  const collapsible = sorted.length > limit;
  if (!collapsible || expanded) return { visible: sorted, hiddenCount: 0, collapsible };
  return { visible: sorted.slice(0, limit), hiddenCount: sorted.length - limit, collapsible };
}
