/**
 * The cat's child HUD (M5-R06-08b — CAT_SPEC §3–§7, §9, M5-R06_PLAN R06-08).
 *
 * What differs from the dog, as pure functions the HUD renders:
 * - the dock: food, water, "Pesek" (scoop), "Igra" (feather wand), clean — no walk (no steps,
 *   no Health card, no step permission), no "Pelji ven", no "Šola";
 * - chips above the dock: "Počeši" (Maine Coon), the weekly litter change, and "Crkljanje"
 *   (M5-R05 play & cuddle — a cat only cuddles, PLAY_CUDDLE_SPEC / CAT_SPEC §5.5);
 * - "Na praskalnik" on the scratched-sofa card (`BehaviourPanel`);
 * - the "first aid" note while the cat is hungry (C24, educational only, no number of days).
 *
 * The server decides every rule (`can_*`, `blocked_reason`, `next_allowed_at`); this module only
 * reads and words it. A dog never reaches any cat branch (`isCatView` is false) — its HUD is
 * exactly as before.
 */

import type { ChildPetView } from '@/modules/childPet/childPetView';
import { dockText, dockWhen, type DockHint } from '@/modules/childPet/dockHint';
import { hasOpenScratching, onlyScratchingEventsOpen } from '@/modules/behaviour/behaviour';
import { onlyScratchingOpen, type CatGameKind, type ChildGrooming, type LitterChange } from '@/modules/catCare/catCare';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

/** `cat:hud` texts (dock, chips, scratcher, first aid). */
export const CAT_HUD_STRINGS = strings('cat', 'hud', {
  hints: {
    wandCount: (done: number, goal: number) => t('cat:hud.hints.wandCount', { done, goal }),
  },
  chips: {
    groomingA11y: (done: number, goal: number) => t('cat:hud.chips.groomingA11y', { done, goal }),
  },
});

/** The pet is a cat (species from the server; an unknown species is never a cat). */
export function isCatView(view: Pick<ChildPetView, 'pet'>): boolean {
  return view.pet.species === 'cat';
}

// ── Dock ──────────────────────────────────────────────────────

export type DockKind = 'feed' | 'water' | 'take_out' | 'walk' | 'clean' | 'scoop' | 'wand';

/**
 * Buttons of the dock, left to right. Dog: food, water, ["Pelji ven" for a puppy], walk,
 * clean (unchanged). Cat: food, water, "Pesek", "Igra", clean — the scoop / wand only when the
 * server sends their block (an older server: food, water, clean).
 */
export function dockKinds(view: ChildPetView): DockKind[] {
  if (!isCatView(view)) {
    return view.behaviour.take_out !== null ? ['feed', 'water', 'take_out', 'walk', 'clean'] : ['feed', 'water', 'walk', 'clean'];
  }
  const kinds: DockKind[] = ['feed', 'water'];
  if (view.cat.litter !== null) kinds.push('scoop');
  if (view.cat.wand !== null) kinds.push('wand');
  kinds.push('clean');
  return kinds;
}

/** A dock button's state. */
export interface CatDockState {
  disabled: boolean;
  /** The one care that is due now (solid mint). */
  due: boolean;
  hint: DockHint | null;
}

/**
 * "Pesek": enabled while there is something to scoop (`can_scoop`); the hint names the
 * earliest deadline ("ob 15:00") or says the tray is clean. Due while a use waits.
 */
export function scoopDock(view: ChildPetView): CatDockState {
  const litter = view.cat.litter;
  if (litter === null || view.lock.is_locked) return { disabled: true, due: false, hint: null };
  if (!litter.can_scoop) return { disabled: true, due: false, hint: dockText(CAT_HUD_STRINGS.hints.litterClean) };
  return { disabled: false, due: true, hint: dockWhen(litter.next_due_at, view.server_time, view.timezone) };
}

/** `blocked_reason`s whose `next_allowed_at` is the end of the block (server `WandPayload`). */
export const TIMED_WAND_REASONS: readonly string[] = ['wand_too_soon', 'wand_quiet_hours', 'wand_session_active', 'care_session_active', 'wand_day_ending'];

/**
 * "Igra" (feather wand): enabled unless locked or not available — the game screen explains a
 * block with its time (the server sends `next_allowed_at` for every timed reason, QA 08a m5).
 * Hint: "Opravljeno ✓" once today's goal is reached, the time a block ends, "Spi" in quiet
 * hours without a time, else "1/2".
 */
export function wandDock(view: ChildPetView): CatDockState {
  const wand = view.cat.wand;
  if (wand === null || view.lock.is_locked) return { disabled: true, due: false, hint: null };
  const disabled = wand.goal <= 0 || wand.blocked_reason === 'wand_not_available';
  const hints = CAT_HUD_STRINGS.hints;
  if (wand.goal > 0 && wand.sessions_today >= wand.goal) return { disabled, due: false, hint: dockText(hints.wandDone) };
  if (!wand.can_start && wand.session === null && wand.blocked_reason !== null) {
    // Only a timed reason names a time (QA 08b m2) — e.g. never for `needs_cleaning`.
    const at = TIMED_WAND_REASONS.includes(wand.blocked_reason) ? dockWhen(wand.next_allowed_at, view.server_time, view.timezone) : null;
    if (at !== null) return { disabled, due: false, hint: at };
    if (wand.blocked_reason === 'wand_quiet_hours') return { disabled, due: false, hint: dockText(hints.asleep) };
  }
  return { disabled, due: false, hint: dockText(hints.wandCount(wand.sessions_today, wand.goal)) };
}

/** Only the scratched sofa is open: cleaning has nothing to do, "Na praskalnik" first. */
export function scratcherFirst(view: ChildPetView): boolean {
  return view.pet.needs_cleaning && (onlyScratchingOpen(view.cat) || onlyScratchingEventsOpen(view.behaviour));
}

// ── Scratcher ("Na praskalnik") ───────────────────────────────

/** The scratched-sofa card offers "Na praskalnik" (an open scratching, or the server's block). */
export function showScratcherButton(view: ChildPetView): boolean {
  return isCatView(view) && view.cat.scratching !== null && (view.cat.scratching.active !== null || hasOpenScratching(view.behaviour));
}

// ── Chips above the dock ──────────────────────────────────────

export interface CatChip {
  kind: 'grooming' | 'litter_change';
  label: string;
  a11y: string;
  /** Amber dot: can be done now and still open this week (or matted / overdue). */
  pending: boolean;
  /** One-line note under the chips (matted coat / smelly tray); null = none. */
  note: string | null;
}

/** The amber dot is read out too (QA 08b n2). */
function withPending(a11y: string, pending: boolean): string {
  return pending ? `${a11y} ${CAT_HUD_STRINGS.chips.pendingA11y}` : a11y;
}

function groomingChip(g: ChildGrooming): CatChip | null {
  const open = g.done_this_week < g.goal_per_week || g.matted;
  if (!open && g.session === null) return null;
  const c = CAT_HUD_STRINGS.chips;
  return {
    kind: 'grooming',
    label: c.grooming,
    a11y: withPending(c.groomingA11y(g.done_this_week, g.goal_per_week), g.can_start || g.session !== null),
    pending: g.can_start || g.session !== null,
    note: g.matted ? c.groomingMatted : null,
  };
}

function litterChangeChip(change: LitterChange): CatChip | null {
  if (change.done && change.session === null) return null;
  const c = CAT_HUD_STRINGS.chips;
  return {
    kind: 'litter_change',
    label: c.litterChange,
    a11y: withPending(c.litterChangeA11y, change.can_start || change.session !== null),
    pending: change.can_start || change.session !== null,
    note: change.overdue ? c.litterChangeOverdue : null,
  };
}

/** "Počeši" (Maine Coon, while this week's brushing is open) and the weekly litter change (until done). */
export function catChips(view: ChildPetView): CatChip[] {
  if (!isCatView(view) || view.lock.is_locked) return [];
  const chips: CatChip[] = [];
  const grooming = view.cat.grooming !== null ? groomingChip(view.cat.grooming) : null;
  if (grooming !== null) chips.push(grooming);
  const change = view.cat.litter?.change ?? null;
  const litter = change !== null ? litterChangeChip(change) : null;
  if (litter !== null) chips.push(litter);
  return chips;
}

// ── "First aid" (C24) ─────────────────────────────────────────

/** Hunger at or below which the cat HUD shows the educational note (phase 1 of the ladder, PRODUCT_SPEC §6). */
export const FIRST_AID_HUNGER = 30;

/**
 * CAT_SPEC §3 [C24, C25]: for a real cat not eating is serious (hepatic lipidosis) — an
 * educational note for the child while the virtual cat is hungry; never a number of days,
 * never a rule of the game.
 */
export function showFirstAid(view: ChildPetView): boolean {
  return isCatView(view) && !view.lock.is_locked && view.pet.hunger_level <= FIRST_AID_HUNGER;
}

// ── Resume ────────────────────────────────────────────────────

/**
 * The child's own running cat game (e.g. the app was restarted mid-game) → the HUD opens it
 * once so it resumes / is saved, like "Šola" (M5-R03). Null when none runs.
 */
export function ownCatGame(view: ChildPetView): { kind: CatGameKind; id: string } | null {
  if (!isCatView(view)) return null;
  const { wand, grooming, litter, scratching } = view.cat;
  if (wand?.session) return { kind: 'wand', id: wand.session.id };
  if (grooming?.session) return { kind: 'grooming', id: grooming.session.id };
  if (litter?.change?.session) return { kind: 'litter_change', id: litter.change.session.id };
  if (scratching?.session) return { kind: 'scratching', id: scratching.session.id };
  return null;
}
