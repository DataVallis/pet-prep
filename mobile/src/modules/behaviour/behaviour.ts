/**
 * Behaviour events on the phone (M5-R02, David 2026-10-06): the puppy's bladder clock
 * ("Pelji ven"), open messes (poop / puppy accident / chewing) and the behaviour scene.
 *
 * Source: the `behaviour` object of `GET /api/child/pet` (+ `can_take_out`,
 * `can_resolve_chewing`), `pet.updated` and the parent dashboard (`family.pets[]`,
 * without the `can_*` flags) — backend `BehaviourPayload`. Older servers / legacy pets
 * send no `behaviour` (or `take_out: null` and no events): every reader here falls back
 * to "nothing new", so the HUD looks exactly as before.
 *
 * The server decides everything (when the accident happens, quiet hours, 2-hour
 * deadline); this module only reads, words and — for the optimistic HUD — predicts the
 * obvious effect of the child's own action until the server's answer replaces it.
 *
 * Pure functions only; texts (`behaviour` namespace, M1-18) are calm and kind — a child
 * must never feel alarmed or shamed by a puppy that needs to go out.
 */

import { familyClock, whenText } from '@/modules/childPet/familyTime';
import { dockWhen, type DockHint } from '@/modules/childPet/dockHint';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

export type BehaviourKind = 'poop' | 'accident' | 'chewing';
/**
 * Behaviour video / graphic shown over the pet (newest open accident or chewing; for a cat
 * the scratched sofa — M5-R06-05 server `scene`, read since M5-R06-08a so the cat's
 * `scratching` video can play; the cat HUD panel follows in R06-08b).
 */
export type BehaviourScene = 'accident' | 'chewing' | 'scratching';

export const BEHAVIOUR_SCENES: readonly BehaviourScene[] = ['accident', 'chewing', 'scratching'];
const KINDS: readonly BehaviourKind[] = ['poop', 'accident', 'chewing'];

/** The puppy's bladder clock; null when the pet has none (legacy, unborn, past the puppy stage). */
export interface TakeOutClock {
  /** Hours the puppy holds today (~1 h per month of age). */
  hold_hours: number;
  clock_started_at: string;
  /** When the accident would happen (counted only outside quiet hours, never inside them). */
  next_due_at: string;
  last_taken_out_at: string | null;
}

export interface BehaviourEvent {
  id: number;
  kind: BehaviourKind;
  started_at: string;
  /** 2-hour deadline (outside quiet hours) — after it the clean routine is missed. */
  due_at: string;
}

/** `behaviour` of the parent dashboard and `pet.updated`. */
export interface PetBehaviour {
  take_out: TakeOutClock | null;
  /** Open messes, oldest first. */
  active_events: BehaviourEvent[];
  scene: BehaviourScene | null;
}

/** `behaviour` of the child state (with what the child may do now). */
export interface ChildBehaviour extends PetBehaviour {
  can_take_out: boolean;
  can_resolve_chewing: boolean;
}

export const EMPTY_BEHAVIOUR: ChildBehaviour = {
  take_out: null,
  active_events: [],
  scene: null,
  can_take_out: false,
  can_resolve_chewing: false,
};

// ── Readers (unknown → typed, never throw) ────────────────────

type Obj = Record<string, unknown>;

function isObj(value: unknown): value is Obj {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function iso(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 && !Number.isNaN(Date.parse(value)) ? value : null;
}

export function isBehaviourScene(value: unknown): value is BehaviourScene {
  return typeof value === 'string' && (BEHAVIOUR_SCENES as readonly string[]).includes(value);
}

function isKind(value: unknown): value is BehaviourKind {
  return typeof value === 'string' && (KINDS as readonly string[]).includes(value);
}

function readTakeOut(value: unknown): TakeOutClock | null {
  if (!isObj(value)) return null;
  const hold = typeof value.hold_hours === 'number' ? value.hold_hours : Number(value.hold_hours);
  const started = iso(value.clock_started_at);
  const due = iso(value.next_due_at);
  if (!Number.isFinite(hold) || hold <= 0 || started === null || due === null) return null;
  return { hold_hours: hold, clock_started_at: started, next_due_at: due, last_taken_out_at: iso(value.last_taken_out_at) };
}

function readEvents(value: unknown): BehaviourEvent[] {
  if (!Array.isArray(value)) return [];
  return value.flatMap((item): BehaviourEvent[] => {
    if (!isObj(item) || !isKind(item.kind)) return [];
    const id = typeof item.id === 'number' ? item.id : Number(item.id);
    const started = iso(item.started_at);
    const due = iso(item.due_at);
    if (!Number.isFinite(id) || started === null || due === null) return [];
    return [{ id, kind: item.kind, started_at: started, due_at: due }];
  });
}

/** Parent dashboard / broadcast `behaviour`; missing or malformed → nothing open. */
export function readPetBehaviour(raw: unknown): PetBehaviour {
  if (!isObj(raw)) return { take_out: null, active_events: [], scene: null };
  return {
    take_out: readTakeOut(raw.take_out),
    active_events: readEvents(raw.active_events),
    scene: isBehaviourScene(raw.scene) ? raw.scene : null,
  };
}

/** Child state `behaviour`; missing (older server, legacy pet) → `EMPTY_BEHAVIOUR`. */
export function readChildBehaviour(raw: unknown): ChildBehaviour {
  if (!isObj(raw)) return EMPTY_BEHAVIOUR;
  return {
    ...readPetBehaviour(raw),
    can_take_out: raw.can_take_out === true,
    can_resolve_chewing: raw.can_resolve_chewing === true,
  };
}

// ── Questions the UI asks ─────────────────────────────────────

export function hasOpenChewing(b: Pick<PetBehaviour, 'active_events'>): boolean {
  return b.active_events.some((e) => e.kind === 'chewing');
}

/**
 * The scrub mini-game is the right tool: hygiene is 0 and something other than a chewed
 * slipper is open. Only chewing open → no scrubbing (the child tidies up with
 * "Pospravi in daj igračo"). A dirty dog without any event info (older server) → scrub
 * as before.
 */
export function needsScrubbing(needsCleaning: boolean, b: Pick<PetBehaviour, 'active_events'>): boolean {
  if (!needsCleaning) return false;
  if (b.active_events.length === 0) return true;
  return b.active_events.some((e) => e.kind !== 'chewing');
}

/** Only a chewed item is open: the scrub button has nothing to do. */
export function onlyChewingOpen(b: Pick<PetBehaviour, 'active_events'>): boolean {
  return b.active_events.length > 0 && b.active_events.every((e) => e.kind === 'chewing');
}

/**
 * Which cleaning game to show: puddles when every scrub-able mess is a puppy accident,
 * dirt otherwise (poop, a mix, or no event info from an older server).
 */
export function cleaningMess(b: Pick<PetBehaviour, 'active_events'>): 'poop' | 'accident' {
  const scrub = b.active_events.filter((e) => e.kind !== 'chewing');
  return scrub.length > 0 && scrub.every((e) => e.kind === 'accident') ? 'accident' : 'poop';
}

/**
 * The scene the HUD panel shows: the server's `scene`, or chewing when a chewed slipper is
 * open but the scene is null (defensive — the server always sets it). Null = no panel card.
 */
export function panelScene(b: PetBehaviour): BehaviourScene | null {
  return b.scene ?? (hasOpenChewing(b) ? 'chewing' : null);
}

/** The server's rule for `scene`: the newest open accident / chewing (events are oldest first). */
export function sceneOf(events: readonly BehaviourEvent[]): BehaviourScene | null {
  for (let i = events.length - 1; i >= 0; i--) {
    const kind = events[i].kind;
    if (isBehaviourScene(kind)) return kind;
  }
  return null;
}

// ── Optimistic effects of the child's own action ──────────────

/** "Pelji ven": the clock restarts now; the next accident ~hold_hours later (server corrects quiet hours). */
export function afterTakeOut(b: ChildBehaviour, nowMs: number): ChildBehaviour {
  if (b.take_out === null) return b;
  const now = new Date(nowMs).toISOString();
  return {
    ...b,
    take_out: {
      ...b.take_out,
      clock_started_at: now,
      last_taken_out_at: now,
      next_due_at: new Date(nowMs + b.take_out.hold_hours * 3_600_000).toISOString(),
    },
  };
}

function withEvents(b: ChildBehaviour, events: BehaviourEvent[]): ChildBehaviour {
  return { ...b, active_events: events, scene: sceneOf(events), can_resolve_chewing: b.can_resolve_chewing && hasOpenChewing({ active_events: events }) };
}

/** Cleaning game done: every poop / accident is gone, a chewed slipper stays. */
export function afterClean(b: ChildBehaviour): ChildBehaviour {
  return withEvents(b, b.active_events.filter((e) => e.kind === 'chewing'));
}

/** "Pospravi in daj igračo": every chewing event is gone, poop / accident stay. */
export function afterResolveChewing(b: ChildBehaviour): ChildBehaviour {
  return { ...withEvents(b, b.active_events.filter((e) => e.kind !== 'chewing')), can_resolve_chewing: false };
}

// ── Texts (child) ─────────────────────────────────────────────

/**
 * Child texts (`behaviour:child`, M1-18): `takeOutIn` / `takeOutAt` are the calm countdown
 * above the dock, `hintNow` the short hint under the button, `cleanChewing` the hint under
 * the disabled "Očisti" when only a slipper is open.
 */
export const BEHAVIOUR_STRINGS = strings('behaviour', 'child', {
  takeOutIn: (duration: string) => t('behaviour:child.takeOutIn', { duration }),
  takeOutAt: (when: string) => t('behaviour:child.takeOutAt', { when }),
});

/** Every scene has a caption and a screen-reader label (compile-time check). */
const _sceneTexts: { scene: Record<BehaviourScene, string>; sceneA11y: Record<BehaviourScene, string> } = BEHAVIOUR_STRINGS;
void _sceneTexts;

/** Above this the countdown shows a clock time ("ob 14:30") instead of a duration. */
export const TAKE_OUT_DURATION_MAX_MIN = 180;

/**
 * "~1 h 20 min", "~45 min", "~2 h", "~5 min". Minutes are rounded DOWN (to 5 above
 * 10 min) — the puppy may be taken out a little early, never later than said.
 */
export function durationText(minutes: number): string {
  const m = Math.max(1, Math.floor(minutes));
  const rounded = m <= 10 ? m : Math.floor(m / 5) * 5;
  const h = Math.floor(rounded / 60);
  const rest = rounded % 60;
  if (h === 0) return `~${rest} min`;
  return rest === 0 ? `~${h} h` : `~${h} h ${rest} min`;
}

export interface TakeOutCountdown {
  /** Line above the dock. */
  line: string;
  /**
   * Short hint under the "Pelji ven" button; a later family day is a two-line
   * {@link DockHint} ("jutri" above "07:10") so the time is never cut off.
   */
  hint: string | DockHint;
  /** The puppy should go out now (the accident is due / overdue). */
  due: boolean;
}

/**
 * Countdown to the next accident in SERVER time (`serverNowMs` = device clock + skew).
 * Under 3 h: "čez ~1 h 20 min"; later (quiet hours in between, a long hold): "ob 14:30"
 * / "jutri ob 07:10" in the family clock; due: a calm "Kuža bi rad šel ven."
 */
export function takeOutCountdown(clock: TakeOutClock, serverNowMs: number, timezone: string | null): TakeOutCountdown | null {
  const dueMs = Date.parse(clock.next_due_at);
  if (Number.isNaN(dueMs)) return null;
  const minutes = (dueMs - serverNowMs) / 60_000;
  // Under a minute left: "~1 min" would be a race — the puppy simply wants out now.
  if (minutes < 1) {
    return { line: BEHAVIOUR_STRINGS.takeOutNow, hint: BEHAVIOUR_STRINGS.hintNow, due: true };
  }
  if (minutes <= TAKE_OUT_DURATION_MAX_MIN) {
    const d = durationText(minutes);
    return { line: BEHAVIOUR_STRINGS.takeOutIn(d), hint: d, due: false };
  }
  const nowIso = new Date(serverNowMs).toISOString();
  const when = whenText(clock.next_due_at, nowIso, timezone);
  const dock = dockWhen(clock.next_due_at, nowIso, timezone);
  if (when === null || dock === null) return null;
  return { line: BEHAVIOUR_STRINGS.takeOutAt(when), hint: dock.day === null ? dock.text : dock, due: false };
}

// ── Texts (parent) ────────────────────────────────────────────

/** Parent texts (`behaviour:parent`, M1-18). */
export const PARENT_BEHAVIOUR_STRINGS = strings('behaviour', 'parent', {
  openEvent: (label: string, due: string | null) => (due ? t('behaviour:parent.openEvent', { label, due }) : label),
  /** `when` from `whenText`: "ob 13:00" / "jutri ob 07:10". */
  takeOut: (when: string, hold: number) => t('behaviour:parent.takeOut', { when, hold }),
  lastTakenOut: (at: string) => t('behaviour:parent.lastTakenOut', { at }),
  /** "Zadnjih 7 dni" (CLDR plurals); exactly one day is just "Zadnji dan" / "Last day". */
  lastDays: (n: number) => (n === 1 ? t('behaviour:parent.lastDay') : t('behaviour:parent.lastDays', { count: n })),
  stats: (takenOut: number, chewing: number) =>
    [
      takenOut > 0 ? t('behaviour:parent.statsTakenOut', { n: takenOut }) : null,
      chewing > 0 ? t('behaviour:parent.statsChewing', { n: chewing }) : null,
    ]
      .filter((x): x is string => x !== null)
      .join(' · '),
});

/** Every behaviour kind has a parent label (compile-time check). */
const _kindTexts: { kinds: Record<BehaviourKind, string> } = PARENT_BEHAVIOUR_STRINGS;
void _kindTexts;

/**
 * Lines for the parent's pet block: the bladder clock ("Mladiček mora ven ob 13:00" /
 * "jutri ob 07:10") and each open mess with its deadline (omitted when unreadable), family
 * clock. Empty for a pet without behaviour.
 */
export function parentBehaviourLines(
  b: PetBehaviour,
  timezone: string | null,
  nowIso: string = new Date().toISOString(),
): string[] {
  const lines: string[] = [];
  if (b.take_out) {
    const when = whenText(b.take_out.next_due_at, nowIso, timezone);
    if (when) lines.push(PARENT_BEHAVIOUR_STRINGS.takeOut(when, b.take_out.hold_hours));
    const last = familyClock(b.take_out.last_taken_out_at, timezone);
    if (last) lines.push(PARENT_BEHAVIOUR_STRINGS.lastTakenOut(last));
  }
  for (const e of b.active_events) {
    const due = familyClock(e.due_at, timezone);
    lines.push(PARENT_BEHAVIOUR_STRINGS.openEvent(PARENT_BEHAVIOUR_STRINGS.kinds[e.kind], due));
  }
  return lines;
}
