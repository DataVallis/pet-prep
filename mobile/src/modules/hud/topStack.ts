/**
 * Child HUD top stack (David 2026-10-25: »pes mora biti viden«) — pure, unit-tested in
 * `__tests__/topStack.test.ts`.
 *
 * Every message card of the HUD (the puppy's take-out deadline, the open-mess card with its
 * button, the "getting ready" notice, play invitation, hints / notes) sits in one compact column
 * directly under the header, left of the metric column — the centre and the lower half of the
 * screen stay free for the dog. Only the action dock and, directly above it, the »Obroki danes«
 * card (David 2026-10-31 — not a stack card, never collapsed) are at the bottom.
 *
 * The column is bounded: the most urgent cards come first and at most
 * {@link TOP_STACK_MAX_VISIBLE} are shown; the rest collapse into a "+N" pill that expands them
 * (and a "Manj" pill collapses them again). The chip row ("Šola", "Igra", breed care, …) is
 * one line of buttons, always visible, under the cards.
 *
 * David 2026-10-26 (the stack still covered the dog's head): the whole stack can be COLLAPSED —
 * with the round "×" in line with the first card or a swipe up on the stack. Collapsed, it is one
 * slim row under the header: a pill with the most urgent card's icon + very short text
 * ({@link collapsedSummary}), "+N" for the other cards and ▾, then the chips as small icon-only
 * buttons. Tapping the pill (or a swipe down on it) expands it again. An urgent top card makes the
 * pill red ({@link isUrgentPriority}); a NEW urgent item that was not there before
 * ({@link reconcileAlerts}) expands the stack once. The choice is remembered on the device
 * (`topStackPrefs.ts`).
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
} as const;

export interface StackEntry {
  key: string;
  priority: number;
  /**
   * Identity of an urgent item for the auto-expand (e.g. the mess event's id). Two renders of the
   * same urgent card must give the same id; a new mess gives a new one. Default: `key`.
   */
  alertId?: string;
}

/**
 * Urgent enough for the collapsed pill's red style and the one-time auto-expand: the take-out
 * clock in red or orange, an open mess and the cat's first aid.
 */
export function isUrgentPriority(priority: number): boolean {
  return priority <= STACK_PRIORITY.firstAid;
}

/** Sorted most urgent first; equal priorities keep the given order. */
function sortStack<T extends StackEntry>(entries: readonly T[]): T[] {
  return entries
    .map((entry, index) => ({ entry, index }))
    .sort((a, b) => a.entry.priority - b.entry.priority || a.index - b.index)
    .map(({ entry }) => entry);
}

export interface CollapsedSummary<T extends StackEntry> {
  /** The card shown in the pill (most urgent), null = no cards (only the icon chips). */
  top: T | null;
  /** The other cards, for the pill's "+N" badge. */
  others: number;
  /** The pill takes the red / urgent style. */
  urgent: boolean;
}

/** What the collapsed pill shows. */
export function collapsedSummary<T extends StackEntry>(entries: readonly T[]): CollapsedSummary<T> {
  const top = sortStack(entries)[0] ?? null;
  return { top, others: Math.max(0, entries.length - 1), urgent: top !== null && isUrgentPriority(top.priority) };
}

/**
 * Tokens of the urgent items on screen now. The priority is part of the token, so a take-out
 * clock that turns from orange to red counts as a new alert; calm cards never give a token.
 */
export function alertTokens(entries: readonly StackEntry[]): string[] {
  return entries.filter((e) => isUrgentPriority(e.priority)).map((e) => `${e.alertId ?? e.key}:${e.priority}`);
}

/**
 * Auto-expand bookkeeping: `fresh` = an urgent token that was not seen before. Every current
 * token counts as seen afterwards (`seen`), and tokens that went away are forgotten — so the
 * same items never expand the stack twice, but a clock that turns red AGAIN after a reset does.
 */
export function reconcileAlerts(seen: readonly string[], current: readonly string[]): { fresh: boolean; seen: string[] } {
  const known = new Set(seen);
  return { fresh: current.some((token) => !known.has(token)), seen: [...new Set(current)] };
}

/** Vertical drag (pt) that starts the stack's swipe — a tap on a card button never moves this far. */
export const TOP_STACK_SWIPE_START = 10;
/** Released drag (pt) or speed (pt/ms) that collapses (up) / expands (down) the stack. */
export const TOP_STACK_SWIPE_DISTANCE = 32;
export const TOP_STACK_SWIPE_VELOCITY = 0.5;

/** Does a move start a vertical swipe in `direction`? Mostly vertical, past the start distance. */
export function startsStackSwipe(direction: 'up' | 'down', dx: number, dy: number): boolean {
  const along = direction === 'up' ? -dy : dy;
  return along > TOP_STACK_SWIPE_START && Math.abs(dy) > Math.abs(dx);
}

/** Does a released swipe in `direction` toggle the stack? */
export function completesStackSwipe(direction: 'up' | 'down', dy: number, vy: number): boolean {
  const sign = direction === 'up' ? -1 : 1;
  return sign * dy > TOP_STACK_SWIPE_DISTANCE || sign * vy > TOP_STACK_SWIPE_VELOCITY;
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
  const sorted = sortStack(entries);
  const limit = Math.max(1, Math.floor(max));
  const collapsible = sorted.length > limit;
  if (!collapsible || expanded) return { visible: sorted, hiddenCount: 0, collapsible };
  return { visible: sorted.slice(0, limit), hiddenCount: sorted.length - limit, collapsible };
}
