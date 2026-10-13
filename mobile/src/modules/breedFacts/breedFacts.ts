/**
 * M5-R09c »Ali si vedel?« — the child's breed facts (DID_YOU_KNOW_SPEC §3). Pure rules only:
 *
 * - which pets get anything at all (`breedFactsBreed`: a known pedigree breed; never the mutt or
 *   the domestic cat — and the HUD still hides every entry while the list is empty);
 * - the **calm moment** (§3.2): the server's `may_auto_show` (week cap, ≥ 2 days apart, not day 1,
 *   not in quiet hours, not locked, picture, unread fact) combined with the live UI state the
 *   server cannot see — a care action just succeeded, every displayed metric ≥ 50 %, no open mess,
 *   no lock, no game / overlay open, not quiet hours, not in the first 10 s after the app opened;
 * - the chip (§3.1): on a day the server allows the sheet but it has not opened by itself;
 * - which finished mutations count as "care just done" (`careKindOfMutation`);
 * - the guide's search / category filter (diacritic-insensitive, `foldForSearch`).
 *
 * Server state (lists, fact of the day) lives in TanStack Query (`useBreedFacts.ts`); what is
 * open lives in `appStore` (UI state).
 */

import type { BreedFact, FactOfTheDayResponse } from '@/api/client';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { familyCalendar } from '@/modules/childPet/familyTime';
import { foldForSearch, isKnownBreed } from '@/modules/species/species';
import type { BreedType } from '@/types';

export type FactCategory = BreedFact['category'];

/** Display order of the categories (spec §5); the child never gets `family` / `health`. */
export const FACT_CATEGORIES: readonly FactCategory[] = [
  'exercise',
  'temperament',
  'body',
  'origin',
  'diet',
  'grooming',
  'training',
  'family',
  'health',
];

/** Breeds without a pedigree never get facts (David 2026-10-09, spec §0.5). */
const NON_PEDIGREE: readonly string[] = ['mutt', 'domestic_cat'];

/** The breed whose facts the child may read, or null (mutt, domestic cat, a breed this build doesn't know). */
export function breedFactsBreed(breed: string | null | undefined): BreedType | null {
  if (!isKnownBreed(breed) || NON_PEDIGREE.includes(breed)) return null;
  return breed;
}

// ── Calm moment (§3.2) ─────────────────────────────────────────

/** No automatic sheet within this long after the app opened / came back from the background. */
export const APP_OPEN_QUIET_MS = 10_000;
/**
 * The sheet waits this long after the newest care success: the HUD's 3 s toast (`TOAST_MS`) and
 * the filling meter come first. Every newer success restarts the wait.
 */
export const CARE_SETTLE_MS = 3_500;
/** A care success older than this no longer makes the moment calm (the child moved on). */
export const CARE_WINDOW_MS = 120_000;
/** Metric threshold: every displayed meter at or above it (spec "vse metrike ≥ 50 %"). */
export const CALM_METRIC_MIN = 50;

export type CalmBlocker =
  | 'server'
  | 'no_fact'
  | 'busy'
  | 'no_care'
  | 'settling'
  | 'app_just_opened'
  | 'metric_low'
  | 'mess'
  | 'locked'
  | 'overlay'
  | 'quiet_hours'
  | 'handled_today';

export interface CalmMomentInput {
  /** `GET /api/child/fact-of-the-day` (null while loading / failed — never calm then). */
  fact: { fact: object | null; may_auto_show: boolean } | null;
  /** Clock ms (one clock for all three times) of the last successful care action, null when none since the app opened. */
  careDoneAtMs: number | null;
  /** Clock ms when the app (HUD) opened or came back to the foreground. */
  appOpenedAtMs: number;
  nowMs: number;
  /** The displayed meters (hunger, thirst, energy / play, hygiene), 0–100. */
  metrics: readonly number[];
  /** An open mess / behaviour event (poop, accident, chewed slipper, scratched sofa …). */
  openMess: boolean;
  locked: boolean;
  /** Any game, layer or sheet over the HUD (walk, cleaning, album, school, play, cat game, sheets, the guide). */
  overlayOpen: boolean;
  /** Quiet hours as the live state shows them (the server's `sleeping` pet state). */
  quietHours: boolean;
  /** The child already read a fact today, or this device already asked the server today. */
  handledToday: boolean;
  /** A care request is still running (`isMutating(['child','pet']) > 0`) — never a card mid-care. */
  careInFlight: boolean;
}

/** Every reason the sheet may not open by itself right now (empty = a calm moment). */
export function calmMomentBlockers(input: CalmMomentInput): CalmBlocker[] {
  const blockers: CalmBlocker[] = [];
  if (input.fact === null || !input.fact.may_auto_show) blockers.push('server');
  if (input.fact !== null && input.fact.fact === null) blockers.push('no_fact');
  if (input.handledToday) blockers.push('handled_today');
  if (input.careInFlight) blockers.push('busy');
  if (input.careDoneAtMs === null || input.nowMs - input.careDoneAtMs > CARE_WINDOW_MS) blockers.push('no_care');
  else if (input.nowMs - input.careDoneAtMs < CARE_SETTLE_MS) blockers.push('settling');
  if (input.nowMs - input.appOpenedAtMs < APP_OPEN_QUIET_MS) blockers.push('app_just_opened');
  if (input.metrics.some((m) => !(m >= CALM_METRIC_MIN))) blockers.push('metric_low');
  if (input.openMess) blockers.push('mess');
  if (input.locked) blockers.push('locked');
  if (input.overlayOpen) blockers.push('overlay');
  if (input.quietHours) blockers.push('quiet_hours');
  return blockers;
}

export function isCalmMoment(input: CalmMomentInput): boolean {
  return calmMomentBlockers(input).length === 0;
}

/**
 * When to look again while only the clock blocks (`settling`, `app_just_opened`): the instant
 * both are over, or null when something else blocks too (that change re-renders anyway) or the
 * care success will have expired by then.
 */
export function nextCalmCheckAt(input: CalmMomentInput): number | null {
  const blockers = calmMomentBlockers(input);
  if (blockers.length === 0) return null;
  const timed = blockers.every((b) => b === 'settling' || b === 'app_just_opened');
  if (!timed || input.careDoneAtMs === null) return null;
  const at = Math.max(input.careDoneAtMs + CARE_SETTLE_MS, input.appOpenedAtMs + APP_OPEN_QUIET_MS);
  return at - input.careDoneAtMs > CARE_WINDOW_MS ? null : at;
}

/** Live parts of the calm moment read from the child state. */
export function calmStateOf(view: ChildPetView): Pick<CalmMomentInput, 'metrics' | 'openMess' | 'locked' | 'quietHours'> {
  const { pet } = view;
  return {
    metrics: [pet.hunger_level, pet.thirst_level, pet.energy_level, pet.hygiene_level],
    openMess: pet.needs_cleaning || view.behaviour.active_events.length > 0,
    locked: view.lock.is_locked,
    // The server's quiet hours put the pet to sleep (`PetDecayService`); a lock is checked apart.
    quietHours: pet.pet_state === 'sleeping',
  };
}

// ── Care actions that count ("pravkar uspešno opravil skrb") ───

export type CareKind =
  | 'feed'
  | 'water'
  | 'clean'
  | 'take_out'
  | 'resolve_chewing'
  | 'play'
  | 'socialisation'
  | 'scoop'
  | 'wand'
  | 'grooming'
  | 'litter_change'
  | 'scratching'
  | 'training'
  | 'walk';

/** `['child','pet', …rest]` mutation keys of the care actions (`useChildActions`, `useCatCare`, `usePlay`, `useTraining`, `useSocialise`). */
const CARE_MUTATIONS: ReadonlyArray<readonly [CareKind, readonly string[]]> = [
  ['feed', ['feed']],
  ['water', ['water']],
  ['clean', ['clean']],
  ['take_out', ['take_out']],
  ['resolve_chewing', ['resolve_chewing']],
  ['play', ['play']],
  ['socialisation', ['socialisation']],
  ['scoop', ['litter', 'scoop']],
  ['wand', ['wand', 'finish']],
  ['grooming', ['grooming', 'finish']],
  ['litter_change', ['litter_change', 'finish']],
  ['scratching', ['scratching', 'finish']],
  ['training', ['training', 'finish']],
];

/**
 * The care a finished mutation stands for, or null. Only a server `accepted` counts — never an
 * `unchanged` (nothing done), a `rejected` game or a refusal. Step syncs are not here: the walk
 * counts when the day's goal is reached (`stepGoalReached`).
 */
export function careKindOfMutation(mutationKey: readonly unknown[] | undefined, data: unknown): CareKind | null {
  if (!mutationKey || mutationKey[0] !== 'child' || mutationKey[1] !== 'pet') return null;
  const rest = mutationKey.slice(2);
  const match = CARE_MUTATIONS.find(([, key]) => key.length === rest.length && key.every((part, i) => part === rest[i]));
  if (!match) return null;
  const status = typeof data === 'object' && data !== null ? (data as { status?: unknown }).status : undefined;
  return status === 'accepted' ? match[0] : null;
}

/** Today's walk is done: the pet's steps reached the day's goal (a dog with a goal only). */
export function stepGoalReached(view: ChildPetView): boolean {
  return view.steps.goal > 0 && view.steps.steps_today >= view.steps.goal;
}

// ── Day, chip, progress ────────────────────────────────────────

/** Family-local `YYYY-MM-DD` of a server-time instant. */
export function familyDay(view: Pick<ChildPetView, 'timezone' | 'server_time'>, nowMs: number): string {
  return familyCalendar(view.timezone, view.server_time).dateOf(nowMs);
}

/** A fact read today (family-local day) — the day's »Ali si vedel?« is done (chip off, no sheet). */
export function readOnDay(facts: readonly Pick<BreedFact, 'read_at'>[], day: string, view: Pick<ChildPetView, 'timezone' | 'server_time'>): boolean {
  const calendar = familyCalendar(view.timezone, view.server_time);
  return facts.some((f) => {
    if (f.read_at === null) return false;
    const ms = Date.parse(f.read_at);
    return Number.isFinite(ms) && calendar.dateOf(ms) === day;
  });
}

export interface ChipInput {
  fact: { fact: object | null; may_auto_show: boolean; shown_today: boolean } | null;
  /**
   * This device recorded today's card but the moment had passed before it opened (a game, a lock):
   * the card is used for the week (DECISIONS 2026-10-13), so the chip keeps it reachable today.
   */
  pendingToday: boolean;
  /** A fact was read today. */
  readToday: boolean;
  locked: boolean;
  /** Something covers the dock (a game / layer); the chip waits. */
  covered: boolean;
}

/**
 * »Nekaj novega o tvoji pasmi« above the care buttons (David 2026-10-13, option A): only on a day
 * the server says is a card day and today's card hasn't been used (`may_auto_show && !shown_today`)
 * — the chip IS today's card (tapping it records `shown`), so chip + automatic card stay ≤ 2 a week.
 * Gone once a fact is read today, at family midnight (new state) and under a lock / layer.
 */
export function showFactChip(input: ChipInput): boolean {
  if (input.fact === null || input.fact.fact === null) return false;
  if (input.readToday || input.locked || input.covered) return false;
  if (input.pendingToday) return true;
  return input.fact.may_auto_show && !input.fact.shown_today;
}

type FactReason = FactOfTheDayResponse['reason'];

/** Reasons that may change by themselves later the same day — re-ask the server then. */
const TIMED_REASONS: readonly NonNullable<FactReason>[] = ['quiet_hours', 'locked', 'first_day', 'no_image'];

export function isTimedReason(reason: FactReason): boolean {
  return reason !== null && TIMED_REASONS.includes(reason);
}

/** Categories a child never sees (spec §2: Družina / Zdravje are parent-only). */
const PARENT_ONLY: readonly FactCategory[] = ['family', 'health'];

/** Client-side second guard: only `audience=child` facts outside the parent-only categories. */
export function isChildFact(f: Pick<BreedFact, 'audience' | 'category'>): boolean {
  return f.audience === 'child' && !PARENT_ONLY.includes(f.category);
}

export function childFactsOnly<T extends { facts: BreedFact[]; read_count: number; total_count: number }>(list: T): T {
  if (!Array.isArray(list.facts) || list.facts.every(isChildFact)) return list;
  const facts = list.facts.filter(isChildFact);
  return { ...list, facts, read_count: facts.filter((f) => f.read).length, total_count: facts.length };
}

/** The book's raspberry dot: an unread approved fact exists. */
export function hasUnreadFacts(list: Pick<BreedFact, 'read'>[]): boolean {
  return list.some((f) => !f.read);
}

/** List after reading one fact (optimistic; the server's answer replaces it). */
export function markReadInList<T extends { facts: BreedFact[]; read_count: number }>(list: T, factId: number, nowIso: string): T {
  let changed = false;
  const facts = list.facts.map((f) => {
    if (f.id !== factId || f.read) return f;
    changed = true;
    return { ...f, read: true, read_at: nowIso };
  });
  return changed ? { ...list, facts, read_count: list.read_count + 1 } : list;
}

// ── Guide: search + categories (§3.4) ─────────────────────────

/** Categories present in the list, in display order (chips: »Vse« + these). */
export function presentCategories(facts: readonly Pick<BreedFact, 'category'>[]): FactCategory[] {
  return FACT_CATEGORIES.filter((c) => facts.some((f) => f.category === c));
}

/** Facts matching the query (diacritic-insensitive, every word in title / why / source) and the category. */
export function filterFacts<T extends Pick<BreedFact, 'title' | 'why' | 'source_name' | 'category'>>(
  facts: readonly T[],
  query: string,
  category: FactCategory | null,
): T[] {
  const words = foldForSearch(query).split(' ').filter((w) => w.length > 0);
  return facts.filter((f) => {
    if (category !== null && f.category !== category) return false;
    if (words.length === 0) return true;
    const haystack = foldForSearch(`${f.title} ${f.why ?? ''} ${f.source_name}`);
    return words.every((w) => haystack.includes(w));
  });
}
