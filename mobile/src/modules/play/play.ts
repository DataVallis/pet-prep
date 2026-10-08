/**
 * Play & cuddle ("Igra in crkljanje", M5-R05 — PLAY_CUDDLE_SPEC) on the phone.
 *
 * Mood / video only (David 2026-10-07): a finished ball game or cuddle makes the dog
 * "happy" for 30 minutes (`play.mood`); nothing here touches points, routines or metrics.
 * The server decides everything (`can_play`, which invitation shows, `happy_until`,
 * the `playing` scene); this module only reads the loose payloads into real types,
 * explains a disabled entry, keeps the optimistic happy state until the server answers
 * and holds the pure gesture rules of the two mini-games.
 *
 * Sources (backend `PlayPayload`):
 * - child state `play` and `PetUpdated.play` (pet level): null for a pet without play
 *   (free mutt, legacy, unpaid) → no "Igra" on the HUD;
 * - `POST /api/child/pet/play` bodies (`status`, `play`, `state`, 422 `play_not_available`);
 * - parent dashboard `family.pets[].play_today` and timeline rows `played_with_pet` /
 *   `cuddled_pet` (`value` = merged count within the hour).
 *
 * The rule of the feature: a reward without punishment — no text for an ignored
 * invitation, no countdown, no number that drops.
 */

import type { ChildPetState } from '@/api/client';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { familyClock } from '@/modules/childPet/familyTime';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

export type PlayKind = 'play' | 'cuddle';
export const PLAY_KINDS: readonly PlayKind[] = ['play', 'cuddle'];
export type PlaySource = 'invitation' | 'free';
/** The only mood scene the server sends (`mood.scene`). */
export type MoodScene = 'playing';

export interface PlayInvitation {
  id: number;
  kind: PlayKind;
  /** ISO instant (family offset); the invitation quietly disappears then. */
  expires_at: string;
}

/** `play` of the child state / `PetUpdated` (null = the pet has no play). */
export interface ChildPlay {
  /** `POST /api/child/pet/play` would be accepted now. */
  can_play: boolean;
  /** The dog's invitation shown now (server-offered), else null. */
  invitation: PlayInvitation | null;
  mood: {
    /** End of the happy scene (30 min after the last play); null when not happy. */
    happy_until: string | null;
    /** `playing` while happy and nothing more important shows (server rule). */
    scene: MoodScene | null;
  };
}

/** `play_today` of a parent dashboard pet (completed today, all children). */
export interface PlayToday {
  play: number;
  cuddle: number;
}

export type PlayStatus = 'accepted' | 'unchanged';

export interface PlayResponse {
  status: PlayStatus;
  /** What was recorded (null for `unchanged`). */
  play: { kind: PlayKind; source: PlaySource } | null;
  state: ChildPetState;
}

/** Backend `play.happy_minutes` — only for the optimistic state (the server's `happy_until` replaces it). */
export const HAPPY_MINUTES = 30;

// ── Mini-game rules (PLAY_CUDDLE_SPEC §4.2 / §4.3) ─────────────

/** Ball game: three throws = done; every throw counts (no miss, no score). */
export const BALL_THROWS = 3;
/** An upward release this far (pt) … */
export const THROW_MIN_DISTANCE = 60;
/** … or this fast upwards (pt/ms, `PanResponder` `vy`) after at least `THROW_MIN_FLICK` pt is a throw. */
export const THROW_MIN_VELOCITY = 0.5;
export const THROW_MIN_FLICK = 15;
/** How long the dog runs after the ball (ms); with "reduce motion" only a short fade. */
export const FETCH_MS = 1_800;
export const FETCH_MS_REDUCED = 600;

/** Cuddle: five strokes, each at least ~60 pt of finger path, any direction (D). */
export const CUDDLE_STROKES = 5;
export const STROKE_MIN_PT = 60;
/** "Drži in pobožaj": holding the button this long replaces the strokes. */
export const HOLD_MS = 3_000;
/** The "done" card closes by itself after this long (the child can also tap "Končano"). */
export const DONE_AUTO_CLOSE_MS = 4_000;

/** A finger released after moving `dy` (negative = up) with velocity `vy` throws the ball. */
export function isThrowRelease(dy: number, vy: number): boolean {
  if (!Number.isFinite(dy) || !Number.isFinite(vy)) return false;
  return dy <= -THROW_MIN_DISTANCE || (dy <= -THROW_MIN_FLICK && vy <= -THROW_MIN_VELOCITY);
}

/** Ball game progress: how many throws are done and whether the dog is still fetching. */
export interface BallState {
  throws: number;
  fetching: boolean;
}

export const BALL_START: BallState = { throws: 0, fetching: false };

/** A throw while the dog is still out (or after the third) is ignored — never an error. */
export function throwBall(state: BallState): BallState {
  if (state.fetching || state.throws >= BALL_THROWS) return state;
  return { throws: state.throws + 1, fetching: true };
}

export function ballFetched(state: BallState): BallState {
  return state.fetching ? { ...state, fetching: false } : state;
}

export function isBallDone(state: BallState): boolean {
  return state.throws >= BALL_THROWS && !state.fetching;
}

/** Finger path of one touch: accumulated distance over the move samples. */
export interface StrokeTracker {
  lastX: number;
  lastY: number;
  path: number;
}

export const STROKE_START: StrokeTracker = { lastX: 0, lastY: 0, path: 0 };

/** `dx` / `dy` = the gesture's cumulative offset (PanResponder), so segments are diffs. */
export function trackStroke(tracker: StrokeTracker, dx: number, dy: number): StrokeTracker {
  if (!Number.isFinite(dx) || !Number.isFinite(dy)) return tracker;
  const segment = Math.hypot(dx - tracker.lastX, dy - tracker.lastY);
  return { lastX: dx, lastY: dy, path: tracker.path + segment };
}

export function isStroke(tracker: StrokeTracker): boolean {
  return tracker.path >= STROKE_MIN_PT;
}

// ── Readers (unknown → typed, never throw) ────────────────────

type Obj = Record<string, unknown>;

function isObj(value: unknown): value is Obj {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function iso(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 && !Number.isNaN(Date.parse(value)) ? value : null;
}

export function isPlayKind(value: unknown): value is PlayKind {
  return value === 'play' || value === 'cuddle';
}

function readInvitation(value: unknown): PlayInvitation | null {
  if (!isObj(value) || !isPlayKind(value.kind)) return null;
  const id = typeof value.id === 'number' ? value.id : typeof value.id === 'string' ? Number(value.id) : Number.NaN;
  const expires = iso(value.expires_at);
  if (!Number.isFinite(id) || expires === null) return null;
  return { id, kind: value.kind, expires_at: expires };
}

/** Child state / broadcast `play`; anything that isn't an object → null (no play, older server). */
export function readChildPlay(raw: unknown): ChildPlay | null {
  if (!isObj(raw)) return null;
  const mood = isObj(raw.mood) ? raw.mood : {};
  const happyUntil = iso(mood.happy_until);
  return {
    can_play: raw.can_play === true,
    invitation: readInvitation(raw.invitation),
    mood: {
      happy_until: happyUntil,
      scene: happyUntil !== null && mood.scene === 'playing' ? 'playing' : null,
    },
  };
}

/** Dashboard `play_today`; null for a pet without play (free, legacy) or an older server. */
export function readPlayToday(raw: unknown): PlayToday | null {
  if (!isObj(raw)) return null;
  const count = (value: unknown): number => {
    const n = typeof value === 'number' ? value : typeof value === 'string' && value !== '' ? Number(value) : Number.NaN;
    return Number.isFinite(n) ? Math.max(0, Math.round(n)) : 0;
  };
  return { play: count(raw.play), cuddle: count(raw.cuddle) };
}

/** `POST /api/child/pet/play` 200 body; null when it isn't one (→ the state is refetched). */
export function readPlayResponse(raw: unknown): PlayResponse | null {
  if (!isObj(raw)) return null;
  const status = raw.status === 'accepted' || raw.status === 'unchanged' ? raw.status : null;
  const state = isObj(raw.state) && isObj(raw.state.pet) ? (raw.state as unknown as ChildPetState) : null;
  if (status === null || state === null) return null;
  const played = isObj(raw.play) && isPlayKind(raw.play.kind)
    ? { kind: raw.play.kind, source: raw.play.source === 'invitation' ? ('invitation' as const) : ('free' as const) }
    : null;
  return { status, play: played, state };
}

/**
 * `play` after a `PetUpdated` (pet level): `can_play` there ignores this child's own
 * contract, so a lock always turns it off. A broadcast without the key (older server)
 * keeps what the view has; an explicit null means the pet lost play (e.g. payment lock).
 */
export function broadcastPlay(current: ChildPlay | null, raw: unknown, locked: boolean): ChildPlay | null {
  if (raw === undefined) return current && locked ? { ...current, can_play: false, invitation: null } : current;
  const next = readChildPlay(raw);
  if (next === null) return null;
  return locked ? { ...next, can_play: false, invitation: null } : next;
}

// ── Questions the HUD asks ────────────────────────────────────

function ms(isoValue: string | null): number {
  if (isoValue === null) return Number.NaN;
  return Date.parse(isoValue);
}

/** The dog is happy at `serverNowMs` (the 30 minutes after a play). */
export function isHappyAt(play: ChildPlay | null, serverNowMs: number): boolean {
  if (play === null) return false;
  const until = ms(play.mood.happy_until);
  return Number.isFinite(until) && until > serverNowMs;
}

/**
 * The mood scene the HUD plays now, or null. The server already applies the priority
 * (lock › behaviour scene › sick / sleeping / hungry / tired › happy); the client
 * re-checks it on the live view so a broadcast tick that brings a need wins at once,
 * and drops the scene when `happy_until` passes between snapshots.
 */
export function moodSceneAt(view: ChildPetView, serverNowMs: number): MoodScene | null {
  const play = view.play;
  if (play === null || play.mood.scene !== 'playing' || !isHappyAt(play, serverNowMs)) return null;
  // An open mess (QA PR #86 M1): the dirty screen wins, never a happy dog over it.
  if (view.lock.is_locked || view.behaviour.scene !== null || view.pet.needs_cleaning) return null;
  return view.pet.pet_state === 'idle' || view.pet.pet_state === 'playing' ? 'playing' : null;
}

/** The invitation to show now: offered by the server, not expired, not "Mogoče kasneje". */
export function visibleInvitation(
  play: ChildPlay | null,
  serverNowMs: number,
  dismissed: ReadonlySet<number> = new Set(),
): PlayInvitation | null {
  const invitation = play?.invitation ?? null;
  if (play === null || invitation === null || !play.can_play) return null;
  if (dismissed.has(invitation.id)) return null;
  const expires = ms(invitation.expires_at);
  return Number.isFinite(expires) && expires > serverNowMs ? invitation : null;
}

/** Why "Igra" is disabled (null = it isn't). An explanation only — the server decided. */
export type PlayBlock = 'sleeping' | 'dirty' | 'other';

export function playBlock(view: ChildPetView): PlayBlock | null {
  if (view.play === null || view.play.can_play) return null;
  if (view.pet.needs_cleaning || view.pet.hygiene_level <= 0) return 'dirty';
  if (view.pet.pet_state === 'sleeping') return 'sleeping';
  return 'other';
}

export type PlayEntry =
  | { kind: 'hidden' }
  | { kind: 'invitation'; invitation: PlayInvitation }
  | { kind: 'button'; block: PlayBlock | null };

/**
 * What sits above the dock: nothing (no play, locked), the dog's invitation, or the
 * "Igra" chip (disabled with its reason while the server says no).
 */
export function playEntry(view: ChildPetView, serverNowMs: number, dismissed: ReadonlySet<number> = new Set()): PlayEntry {
  if (view.play === null || view.lock.is_locked) return { kind: 'hidden' };
  const invitation = visibleInvitation(view.play, serverNowMs, dismissed);
  if (invitation !== null) return { kind: 'invitation', invitation };
  return { kind: 'button', block: playBlock(view) };
}

/**
 * ms until the mood or the invitation changes by the clock alone (happy scene ends,
 * invitation expires), for one re-render timer; null when nothing is pending.
 */
export function nextPlayChangeMs(view: ChildPetView, serverNowMs: number): number | null {
  const play = view.play;
  if (play === null) return null;
  const candidates = [ms(play.mood.happy_until), ms(play.invitation?.expires_at ?? null)].filter(
    (at) => Number.isFinite(at) && at > serverNowMs,
  );
  return candidates.length > 0 ? Math.min(...candidates) - serverNowMs : null;
}

/**
 * What the HUD shows while `POST /api/child/pet/play` is in flight: happy for 30 minutes
 * (the scene only when nothing more important shows), the invitation of that kind done.
 * The server's `state` replaces it.
 */
export function optimisticPlay(view: ChildPetView, kind: PlayKind, serverNowMs: number): ChildPetView {
  if (view.play === null) return view;
  const happyUntil = new Date(serverNowMs + HAPPY_MINUTES * 60_000).toISOString();
  const calm =
    !view.lock.is_locked &&
    view.behaviour.scene === null &&
    !view.pet.needs_cleaning &&
    (view.pet.pet_state === 'idle' || view.pet.pet_state === 'playing');
  return {
    ...view,
    play: {
      ...view.play,
      invitation: view.play.invitation?.kind === kind ? null : view.play.invitation,
      mood: { happy_until: happyUntil, scene: calm ? 'playing' : null },
    },
  };
}

// ── Text (`play` namespace, M1-18) ────────────────────────────

export const PLAY_STRINGS = strings('play', 'play', {
  progress: (n: number) => t('play:play.progress', { n }),
});
export const CUDDLE_STRINGS = strings('play', 'cuddle', {
  progress: (n: number) => t('play:cuddle.progress', { n }),
});
export const MOOD_STRINGS = strings('play', 'mood');
export const MOMENT_STRINGS = strings('play', 'moment');
export const PLAY_BLOCK_STRINGS = strings('play', 'unavailable', {
  sleepingUntil: (clock: string) => t('play:unavailable.sleepingUntil', { clock }),
});

/** Every block has its text (compile-time check). */
const _blockTexts: Record<PlayBlock, string> = PLAY_BLOCK_STRINGS;
void _blockTexts;

/** The invitation card: "Kuža ti prinaša žogo. Se igrava?" + "Igraj se" / "Pobožaj". */
export function invitationTexts(kind: PlayKind): { line: string; action: string } {
  return kind === 'play'
    ? { line: PLAY_STRINGS.invite, action: PLAY_STRINGS.start }
    : { line: CUDDLE_STRINGS.invite, action: CUDDLE_STRINGS.start };
}

/** "Hvala za igro! …" / "Kuža uživa. …". */
export function doneText(kind: PlayKind): string {
  return kind === 'play' ? PLAY_STRINGS.done : CUDDLE_STRINGS.done;
}

/**
 * Text for a 422 of the play endpoint: "Kuža spi do 07:00. …" when the server names the
 * time (quiet hours), else the reason read from the server state that came with it
 * ("Najprej počisti …"), else the neutral "Zdaj se ne moreta igrati.".
 */
export function playRefusalMessage(nextAllowedAt: string | null, timezone: string | null, view: ChildPetView | null = null): string {
  const clock = familyClock(nextAllowedAt, timezone);
  if (clock) return PLAY_BLOCK_STRINGS.sleepingUntil(clock);
  const block = view ? playBlock(view) : null;
  return block !== null ? PLAY_BLOCK_STRINGS[block] : PLAY_BLOCK_STRINGS.other;
}

/**
 * The note under a disabled "Igra". The child state carries no end of quiet hours, so the
 * time ("Kuža spi do 07:00.") is shown only when a 422 of this session named it
 * (`sleepsUntil` = its `next_allowed_at`) and it is still ahead; otherwise the timeless text.
 */
export function playBlockText(block: PlayBlock, sleepsUntil: string | null, timezone: string | null, serverNowMs: number): string {
  if (block === 'sleeping' && sleepsUntil !== null) {
    const until = Date.parse(sleepsUntil);
    const clock = Number.isFinite(until) && until > serverNowMs ? familyClock(sleepsUntil, timezone) : null;
    if (clock) return PLAY_BLOCK_STRINGS.sleepingUntil(clock);
  }
  return PLAY_BLOCK_STRINGS[block];
}

// ── Parent (`play:parent`) ────────────────────────────────────

export function playKindOfActivity(type: string): PlayKind | null {
  return type === 'played_with_pet' ? 'play' : type === 'cuddled_pet' ? 'cuddle' : null;
}

/**
 * Timeline row "Igra z žogo · Ana" / "Crkljanje ×3 · Ana" (`value` = plays merged into the
 * row within the hour); null for any other activity type.
 */
export function playTimelineText(type: string, nickname: string | null, value: number | null): string | null {
  const kind = playKindOfActivity(type);
  if (kind === null) return null;
  const n = value !== null && Number.isFinite(value) ? Math.round(value) : 1;
  const counted = n > 1;
  if (nickname) {
    return counted
      ? t(`play:parent.timeline.${kind}Count`, { n, child: nickname })
      : t(`play:parent.timeline.${kind}`, { child: nickname });
  }
  return counted ? t(`play:parent.timeline.${kind}AnonCount`, { n }) : t(`play:parent.timeline.${kind}Anon`);
}

/** "Danes: 3× igra z žogo, 2× crkljanje"; null for a pet without play. */
export function playTodayLine(today: PlayToday | null): string | null {
  if (today === null) return null;
  return t('play:parent.today', { play: today.play, cuddle: today.cuddle });
}
