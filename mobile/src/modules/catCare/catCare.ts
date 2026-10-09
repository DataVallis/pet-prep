/**
 * Cat care in the app (M5-R06-08a — CAT_SPEC Q1 / Q3 / Q8 / Q10, server M5-R06-04 / 05).
 *
 * Four server-led mini-games and one quick action:
 * - wand play "Palica s peresom" (`POST /api/child/pet/wand/start|finish`, ~60 s);
 * - scoop "Počisti pesek" (`POST /api/child/pet/litter/scoop`, a local tap game, then one request);
 * - weekly litter change (`litter-change/start|finish`) and Maine Coon grooming
 *   (`grooming/start|finish`) — 30 s stroke games (60 s / 20 strokes while matted);
 * - "Na praskalnik" (`scratching/start|finish`) — carry, the server says when the cat lands,
 *   praise within 150 ms–3 s.
 *
 * The SERVER is the source of truth: it generates every schedule and judges every finish.
 * The app sends exactly what it scores — feather moves `{t, away}`, strokes `{t}`, the
 * praise offset `praise_ms` — as whole ms since the app's local start of the session.
 *
 * Sources: the child state blocks `wand`, `litter`, `grooming`, `scratching` (backend
 * `WandPayload`, `LitterPayload`, `GroomingPayload`, `ScratchingPayload`; null for a dog)
 * and the start / finish 200 bodies (`PetActivityService::wandSessionPayload`,
 * `CareSessionPayload`). `schema.ts` types the sessions wrongly (Scramble reuses the
 * training shape) and the action bodies loosely, so everything is read here from
 * `unknown` into real types (never throws). A dog, a legacy pet and an older server →
 * `EMPTY_CAT_CARE` → nothing cat-specific on screen. Texts: namespace `cat` (EN + SL,
 * the cat is "muca", feminine; the child is "ti"), never shaming.
 */

import type { ChildPetState } from '@/api/client';
import { localParts } from '@/modules/childPet/familyTime';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

// ── Types ─────────────────────────────────────────────────────

/** The overlays the cat HUD (R06-08b) opens; one game at a time. */
export type CatGameKind = 'wand' | 'scoop' | 'litter_change' | 'grooming' | 'scratching';
export const CAT_GAME_KINDS: readonly CatGameKind[] = ['wand', 'scoop', 'litter_change', 'grooming', 'scratching'];

/** The stroke mini-games (same server scorer, `CatChoreService::score`). */
export type ChoreKind = 'grooming' | 'litter_change';

/** Any session the app plays: id, server instants, length in ms. */
export interface CatSessionBase {
  id: string;
  started_at: string;
  ends_at: string;
  /** Last moment a finish is accepted (TTL). */
  expires_at: string;
  duration_ms: number;
}

/** `session` of `wand/start` (and of `wand.session` in the child state). */
export interface WandSession extends CatSessionBase {
  /** The catch at the end (= duration). */
  catch_at_ms: number;
  /** The cat's pounces (ms since start, sorted): an "away" move must follow each within `pounce_window_ms`. */
  pounces_ms: number[];
  min_away_moves: number;
  segments: number;
  min_move_interval_ms: number;
  pounce_window_ms: number;
}

/** `session` of `grooming/start` / `litter-change/start`. */
export interface ChoreSession extends CatSessionBase {
  kind: ChoreKind;
  min_strokes: number;
  segments: number;
  min_stroke_interval_ms: number;
  /** Grooming that resolves a matted coat (longer, more strokes). */
  matted: boolean;
}

/** `session` of `scratching/start`; `duration_ms` = landing + praise window (= `ends_at`). */
export interface ScratchingSession extends CatSessionBase {
  land_at_ms: number;
  praise_window_ms: number;
  min_reaction_ms: number;
}

export type WandFailReason = 'too_few_moves' | 'not_spread' | 'wrong_technique' | 'missed_pounces' | 'too_uniform';
export type ChoreFailReason = 'too_few_strokes' | 'not_spread' | 'too_uniform';
export type ScratchingFailReason = 'no_praise' | 'too_early' | 'too_late';

const WAND_REASONS: readonly WandFailReason[] = ['too_few_moves', 'not_spread', 'wrong_technique', 'missed_pounces', 'too_uniform'];
const CHORE_REASONS: readonly ChoreFailReason[] = ['too_few_strokes', 'not_spread', 'too_uniform'];
const SCRATCHING_REASONS: readonly ScratchingFailReason[] = ['no_praise', 'too_early', 'too_late'];

export interface WandResult {
  session_id: string;
  success: boolean;
  reason: WandFailReason | null;
  away_moves: number;
  toward_moves: number;
  segments_hit: number;
  segments: number;
  pounces_hit: number;
  pounces: number;
}

export interface ChoreResult {
  session_id: string;
  success: boolean;
  reason: ChoreFailReason | null;
  strokes: number;
  min_strokes: number;
  segments_hit: number;
  segments: number;
  /** Grooming: this session resolved a matted coat. */
  matted: boolean;
}

export interface ScratchingResult {
  session_id: string;
  success: boolean;
  reason: ScratchingFailReason | null;
  praise_ms: number | null;
  land_at_ms: number;
  delay_ms: number | null;
  praise_window_ms: number;
}

/** 200 statuses of a finish: `accepted` = counts, `rejected` = didn't (no penalty), `unchanged` = a repeat. */
export type CatFinishStatus = 'accepted' | 'rejected' | 'unchanged';

export interface CatStartResponse<S> {
  status: 'accepted';
  session: S;
  state: ChildPetState;
}

export interface CatFinishResponse<R> {
  status: CatFinishStatus;
  result: R;
  state: ChildPetState;
}

export interface ScoopResponse {
  status: 'accepted' | 'unchanged';
  /** Litter uses scooped now (0 for `unchanged`). */
  scooped: number;
  state: ChildPetState;
}

/** Child state `wand` (the cat's daily play). */
export interface ChildWand {
  goal: number;
  sessions_today: number;
  my_sessions_today: number | null;
  min_gap_minutes: number;
  /** End of the 2 h gap after the last successful game; null when none runs. */
  next_allowed_at: string | null;
  /** Why a start would be refused now (not the lock); null = it may start. */
  blocked_reason: string | null;
  can_start: boolean;
  /** The child's own running game (resumable), else null. */
  session: WandSession | null;
  session_running: boolean;
  missed_yesterday: boolean;
}

export interface LitterUse {
  id: number;
  used_at: string;
  due_at: string | null;
  /** The scoop deadline passed — the mess next to the tray is open. */
  expired: boolean;
}

export interface LitterChange {
  week_started_at: string;
  due_at: string;
  done: boolean;
  overdue: boolean;
  blocked_reason: string | null;
  can_start: boolean;
  session: ChoreSession | null;
  session_running: boolean;
}

/** Child state `litter` (the tray). */
export interface ChildLitter {
  uses_per_day: number;
  open_uses: LitterUse[];
  next_due_at: string | null;
  scoop_deadline_hours: number;
  can_scoop: boolean;
  change: LitterChange | null;
}

/** Child state `grooming` (Maine Coon only). */
export interface ChildGrooming {
  goal_per_week: number;
  done_this_week: number;
  week_started_at: string;
  week_ends_at: string;
  matted: boolean;
  matted_since: string | null;
  session_seconds: number;
  next_allowed_at: string | null;
  blocked_reason: string | null;
  can_start: boolean;
  session: ChoreSession | null;
  session_running: boolean;
}

/** Child state `scratching` ("opraskala je kavč"). */
export interface ChildScratching {
  active: { id: number; started_at: string; due_at: string } | null;
  blocked_reason: string | null;
  can_start: boolean;
  session: ScratchingSession | null;
  session_running: boolean;
}

/** Everything cat-specific of the child state; every block null for a dog. */
export interface ChildCatCare {
  wand: ChildWand | null;
  litter: ChildLitter | null;
  grooming: ChildGrooming | null;
  scratching: ChildScratching | null;
}

export const EMPTY_CAT_CARE: ChildCatCare = { wand: null, litter: null, grooming: null, scratching: null };

// ── Readers (unknown → typed, never throw) ────────────────────

type Obj = Record<string, unknown>;

function isObj(value: unknown): value is Obj {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function iso(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 && !Number.isNaN(Date.parse(value)) ? value : null;
}

function int(value: unknown): number | null {
  const n = typeof value === 'number' ? value : typeof value === 'string' && value !== '' ? Number(value) : Number.NaN;
  return Number.isFinite(n) ? Math.round(n) : null;
}

function num(value: unknown, fallback = 0): number {
  const n = typeof value === 'number' ? value : typeof value === 'string' && value !== '' ? Number(value) : Number.NaN;
  return Number.isFinite(n) ? n : fallback;
}

function nonNeg(value: unknown, fallback = 0): number {
  return Math.max(0, int(value) ?? fallback);
}

function str(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null;
}

/** id + the three instants every session has; null when any is missing. */
function readBase(raw: Obj, durationMs: number | null): CatSessionBase | null {
  const id = str(raw.id);
  const started = iso(raw.started_at);
  const ends = iso(raw.ends_at);
  const expires = iso(raw.expires_at);
  if (id === null || started === null || ends === null || expires === null || durationMs === null || durationMs <= 0) return null;
  return { id, started_at: started, ends_at: ends, expires_at: expires, duration_ms: durationMs };
}

/** A playable wand schedule, or null (never guessed). */
export function readWandSession(raw: unknown): WandSession | null {
  if (!isObj(raw)) return null;
  const base = readBase(raw, int(raw.duration_ms));
  if (base === null || !Array.isArray(raw.pounces_ms)) return null;
  const pounces: number[] = [];
  for (const p of raw.pounces_ms) {
    const ms = int(p);
    if (ms === null || ms < 0 || ms > base.duration_ms) return null;
    pounces.push(ms);
  }
  pounces.sort((a, b) => a - b);
  const catchAt = int(raw.catch_at_ms);
  return {
    ...base,
    catch_at_ms: catchAt !== null && catchAt > 0 && catchAt <= base.duration_ms ? catchAt : base.duration_ms,
    pounces_ms: pounces,
    min_away_moves: Math.max(1, int(raw.min_away_moves) ?? 8),
    segments: Math.max(1, int(raw.segments) ?? 4),
    min_move_interval_ms: nonNeg(raw.min_move_interval_ms, 300),
    pounce_window_ms: nonNeg(raw.pounce_window_ms, 2000),
  };
}

function isChoreKind(value: unknown): value is ChoreKind {
  return value === 'grooming' || value === 'litter_change';
}

/** A grooming / litter change schedule, or null. `expected` rejects a session of the other kind. */
export function readChoreSession(raw: unknown, expected?: ChoreKind): ChoreSession | null {
  if (!isObj(raw) || !isChoreKind(raw.kind) || (expected !== undefined && raw.kind !== expected)) return null;
  const base = readBase(raw, int(raw.duration_ms));
  if (base === null) return null;
  return {
    ...base,
    kind: raw.kind,
    min_strokes: Math.max(1, int(raw.min_strokes) ?? 10),
    segments: Math.max(1, int(raw.segments) ?? 3),
    min_stroke_interval_ms: nonNeg(raw.min_stroke_interval_ms, 150),
    matted: raw.matted === true,
  };
}

/** A scratching carry, or null. Its length is landing + praise window. */
export function readScratchingSession(raw: unknown): ScratchingSession | null {
  if (!isObj(raw)) return null;
  const land = int(raw.land_at_ms);
  const window = int(raw.praise_window_ms);
  if (land === null || land < 0 || window === null || window <= 0) return null;
  const base = readBase(raw, land + window);
  if (base === null) return null;
  const minReaction = int(raw.min_reaction_ms);
  return {
    ...base,
    land_at_ms: land,
    praise_window_ms: window,
    min_reaction_ms: minReaction !== null && minReaction >= 0 && minReaction < window ? minReaction : 150,
  };
}

export function readWandResult(raw: unknown): WandResult | null {
  if (!isObj(raw) || str(raw.session_id) === null || typeof raw.success !== 'boolean') return null;
  const reason = typeof raw.reason === 'string' && (WAND_REASONS as readonly string[]).includes(raw.reason) ? (raw.reason as WandFailReason) : null;
  return {
    session_id: raw.session_id as string,
    success: raw.success,
    reason: raw.success ? null : reason,
    away_moves: nonNeg(raw.away_moves),
    toward_moves: nonNeg(raw.toward_moves),
    segments_hit: nonNeg(raw.segments_hit),
    segments: nonNeg(raw.segments),
    pounces_hit: nonNeg(raw.pounces_hit),
    pounces: nonNeg(raw.pounces),
  };
}

export function readChoreResult(raw: unknown): ChoreResult | null {
  if (!isObj(raw) || str(raw.session_id) === null || typeof raw.success !== 'boolean') return null;
  const reason = typeof raw.reason === 'string' && (CHORE_REASONS as readonly string[]).includes(raw.reason) ? (raw.reason as ChoreFailReason) : null;
  return {
    session_id: raw.session_id as string,
    success: raw.success,
    reason: raw.success ? null : reason,
    strokes: nonNeg(raw.strokes),
    min_strokes: nonNeg(raw.min_strokes),
    segments_hit: nonNeg(raw.segments_hit),
    segments: nonNeg(raw.segments),
    matted: raw.matted === true,
  };
}

export function readScratchingResult(raw: unknown): ScratchingResult | null {
  if (!isObj(raw) || str(raw.session_id) === null || typeof raw.success !== 'boolean') return null;
  const reason =
    typeof raw.reason === 'string' && (SCRATCHING_REASONS as readonly string[]).includes(raw.reason) ? (raw.reason as ScratchingFailReason) : null;
  return {
    session_id: raw.session_id as string,
    success: raw.success,
    reason: raw.success ? null : reason,
    praise_ms: int(raw.praise_ms),
    land_at_ms: nonNeg(raw.land_at_ms),
    delay_ms: int(raw.delay_ms),
    praise_window_ms: nonNeg(raw.praise_window_ms),
  };
}

/** A child state object (the caller normalises it); just "an object with a pet". */
function stateOf(value: unknown): ChildPetState | null {
  return isObj(value) && isObj(value.pet) ? (value as unknown as ChildPetState) : null;
}

/** The `state` of any action body (also of a malformed 200), or null. */
export function stateOfBody(raw: unknown): ChildPetState | null {
  return isObj(raw) ? stateOf(raw.state) : null;
}

/** A start 200 body with a playable session, or null (→ treated as a failure, never guessed). */
export function readCatStartResponse<S>(raw: unknown, readSession: (value: unknown) => S | null): CatStartResponse<S> | null {
  if (!isObj(raw) || raw.status !== 'accepted') return null;
  const session = readSession(raw.session);
  const state = stateOf(raw.state);
  return session !== null && state !== null ? { status: 'accepted', session, state } : null;
}

/** A finish 200 body (`accepted` / `rejected` / `unchanged` with the server's verdict), or null. */
export function readCatFinishResponse<R>(raw: unknown, readResult: (value: unknown) => R | null): CatFinishResponse<R> | null {
  if (!isObj(raw)) return null;
  const status = raw.status === 'accepted' || raw.status === 'rejected' || raw.status === 'unchanged' ? raw.status : null;
  const result = readResult(raw.result);
  const state = stateOf(raw.state);
  return status !== null && result !== null && state !== null ? { status, result, state } : null;
}

/** `litter/scoop` 200 body, or null. */
export function readScoopResponse(raw: unknown): ScoopResponse | null {
  if (!isObj(raw)) return null;
  const status = raw.status === 'accepted' || raw.status === 'unchanged' ? raw.status : null;
  const state = stateOf(raw.state);
  if (status === null || state === null) return null;
  return { status, scooped: status === 'accepted' ? Math.max(1, nonNeg(raw.scooped, 1)) : 0, state };
}

export function readChildWand(raw: unknown): ChildWand | null {
  if (!isObj(raw)) return null;
  const goal = nonNeg(raw.goal);
  return {
    goal,
    sessions_today: nonNeg(raw.sessions_today),
    my_sessions_today: raw.my_sessions_today === null || raw.my_sessions_today === undefined ? null : nonNeg(raw.my_sessions_today),
    min_gap_minutes: nonNeg(raw.min_gap_minutes),
    next_allowed_at: iso(raw.next_allowed_at),
    blocked_reason: str(raw.blocked_reason),
    can_start: raw.can_start === true,
    session: readWandSession(raw.session),
    session_running: raw.session_running === true,
    missed_yesterday: raw.missed_yesterday === true,
  };
}

function readLitterUses(value: unknown): LitterUse[] {
  if (!Array.isArray(value)) return [];
  return value.flatMap((item): LitterUse[] => {
    if (!isObj(item)) return [];
    const id = int(item.id);
    const used = iso(item.used_at);
    if (id === null || used === null) return [];
    return [{ id, used_at: used, due_at: iso(item.due_at), expired: item.expired === true }];
  });
}

function readLitterChange(value: unknown): LitterChange | null {
  if (!isObj(value)) return null;
  const started = iso(value.week_started_at);
  const due = iso(value.due_at);
  if (started === null || due === null) return null;
  return {
    week_started_at: started,
    due_at: due,
    done: value.done === true,
    overdue: value.overdue === true,
    blocked_reason: str(value.blocked_reason),
    can_start: value.can_start === true,
    session: readChoreSession(value.session, 'litter_change'),
    session_running: value.session_running === true,
  };
}

export function readChildLitter(raw: unknown): ChildLitter | null {
  if (!isObj(raw)) return null;
  return {
    uses_per_day: nonNeg(raw.uses_per_day),
    open_uses: readLitterUses(raw.open_uses),
    next_due_at: iso(raw.next_due_at),
    scoop_deadline_hours: Math.max(0, num(raw.scoop_deadline_hours, 4)),
    can_scoop: raw.can_scoop === true,
    change: readLitterChange(raw.change),
  };
}

export function readChildGrooming(raw: unknown): ChildGrooming | null {
  if (!isObj(raw)) return null;
  const weekStart = iso(raw.week_started_at);
  const weekEnd = iso(raw.week_ends_at);
  if (weekStart === null || weekEnd === null) return null;
  return {
    goal_per_week: nonNeg(raw.goal_per_week),
    done_this_week: nonNeg(raw.done_this_week),
    week_started_at: weekStart,
    week_ends_at: weekEnd,
    matted: raw.matted === true,
    matted_since: iso(raw.matted_since),
    session_seconds: Math.max(1, int(raw.session_seconds) ?? 30),
    next_allowed_at: iso(raw.next_allowed_at),
    blocked_reason: str(raw.blocked_reason),
    can_start: raw.can_start === true,
    session: readChoreSession(raw.session, 'grooming'),
    session_running: raw.session_running === true,
  };
}

export function readChildScratching(raw: unknown): ChildScratching | null {
  if (!isObj(raw)) return null;
  let active: ChildScratching['active'] = null;
  if (isObj(raw.active)) {
    const id = int(raw.active.id);
    const started = iso(raw.active.started_at);
    const due = iso(raw.active.due_at);
    if (id !== null && started !== null && due !== null) active = { id, started_at: started, due_at: due };
  }
  return {
    active,
    blocked_reason: str(raw.blocked_reason),
    can_start: raw.can_start === true,
    session: readScratchingSession(raw.session),
    session_running: raw.session_running === true,
  };
}

/** The four cat blocks of a child state (a dog / older server → `EMPTY_CAT_CARE`). */
export function readChildCatCare(raw: unknown): ChildCatCare {
  if (!isObj(raw)) return EMPTY_CAT_CARE;
  const care: ChildCatCare = {
    wand: readChildWand(raw.wand),
    litter: readChildLitter(raw.litter),
    grooming: readChildGrooming(raw.grooming),
    scratching: readChildScratching(raw.scratching),
  };
  return care.wand === null && care.litter === null && care.grooming === null && care.scratching === null ? EMPTY_CAT_CARE : care;
}

// ── Questions the UI asks ─────────────────────────────────────

/** The child's own running session of a game kind (to resume after an app restart). */
export function ownSession(care: ChildCatCare, kind: 'wand'): WandSession | null;
export function ownSession(care: ChildCatCare, kind: ChoreKind): ChoreSession | null;
export function ownSession(care: ChildCatCare, kind: 'scratching'): ScratchingSession | null;
export function ownSession(care: ChildCatCare, kind: 'wand' | ChoreKind | 'scratching'): CatSessionBase | null {
  switch (kind) {
    case 'wand':
      return care.wand?.session ?? null;
    case 'grooming':
      return care.grooming?.session ?? null;
    case 'litter_change':
      return care.litter?.change?.session ?? null;
    case 'scratching':
      return care.scratching?.session ?? null;
  }
}

/**
 * Only the scratched sofa is open (no mess next to the tray): a `needs_cleaning` refusal
 * then means "first the scratcher" — cleaning doesn't resolve a scratching (HANDOFF
 * R06-06b: the API `message` still says "Clean up the mess first.").
 */
export function onlyScratchingOpen(care: ChildCatCare): boolean {
  return care.scratching?.active != null && !(care.litter?.open_uses.some((u) => u.expired) ?? false);
}

// ── Times (family timezone) ───────────────────────────────────

const WEEKDAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as const;

function addDays(date: string, days: number): string {
  const ms = Date.parse(`${date}T00:00:00Z`) + days * 86_400_000;
  return new Date(ms).toISOString().slice(0, 10);
}

/**
 * When something is allowed again, in the family timezone: "at 17:00" (today),
 * "tomorrow at 06:00", "on Tuesday at 14:05" (within the next week — the weekly litter
 * change / grooming) — Slovenian "ob 17:00", "jutri ob 06:00", "v torek ob 14:05".
 * null for a missing / broken instant.
 */
export function catWhen(isoAt: string | null, nowIso: string | null, timeZone: string | null): string | null {
  if (isoAt === null) return null;
  const at = localParts(isoAt, timeZone);
  if (at === null) return null;
  const now = nowIso !== null ? localParts(nowIso, timeZone) : null;
  if (now === null || at.date <= now.date) return t('cat:time.at', { time: at.time });
  if (at.date === addDays(now.date, 1)) return t('cat:time.tomorrowAt', { time: at.time });
  const weekday = WEEKDAYS[new Date(`${at.date}T00:00:00Z`).getUTCDay()];
  return t('cat:time.weekdayAt', { weekday: t(`cat:time.weekdays.${weekday}`), time: at.time });
}

// ── Refusals (422) → child text ───────────────────────────────

/** Every refusal code of the cat endpoints (backend `CareRefusal`) — also `blocked_reason` values. */
export const CAT_REFUSALS = [
  'needs_cleaning',
  'wand_not_available',
  'wand_quiet_hours',
  'wand_too_soon',
  'wand_session_active',
  'wand_day_ending',
  'wand_session_invalid',
  'wand_session_not_over',
  'wand_session_expired',
  'wand_session_interrupted',
  'wand_invalid_moves',
  'litter_not_available',
  'litter_change_done',
  'litter_change_session_active',
  'grooming_not_available',
  'grooming_done_today',
  'grooming_week_done',
  'grooming_quiet_hours',
  'grooming_session_active',
  'scratching_not_needed',
  'scratching_session_active',
  'care_session_active',
  'care_session_invalid',
  'care_session_not_over',
  'care_session_expired',
  'care_session_interrupted',
  'care_session_invalid_input',
] as const;
export type CatRefusal = (typeof CAT_REFUSALS)[number];

/** Refusals whose text names a time (`next_allowed_at`); each has a `_no_time` variant. */
const TIMED: ReadonlySet<CatRefusal> = new Set([
  'wand_quiet_hours',
  'wand_too_soon',
  'wand_session_active',
  'litter_change_done',
  'litter_change_session_active',
  'grooming_done_today',
  'grooming_week_done',
  'grooming_quiet_hours',
  'grooming_session_active',
  'scratching_session_active',
  'care_session_active',
]);

export function isCatRefusal(value: unknown): value is CatRefusal {
  return typeof value === 'string' && (CAT_REFUSALS as readonly string[]).includes(value);
}

export interface RefusalContext {
  /** Server time of the latest state (for "today" / "tomorrow"). */
  nowIso: string | null;
  timezone: string | null;
  /** `onlyScratchingOpen(care)` — `needs_cleaning` then asks for the scratcher first. */
  scratchingOnly?: boolean;
}

/** Child text for a refusal code (422 or a `blocked_reason`), with the family-local time when known. */
export function catRefusalMessage(reason: string | null, nextAllowedAt: string | null, ctx: RefusalContext): string {
  if (!isCatRefusal(reason)) return t('cat:errors.unknown');
  if (reason === 'needs_cleaning') return ctx.scratchingOnly ? t('cat:errors.needs_scratcher_first') : t('cat:errors.needs_cleaning');
  if (TIMED.has(reason)) {
    const when = catWhen(nextAllowedAt, ctx.nowIso, ctx.timezone);
    // The keys are checked by the parity test; the typed `t` can't see template keys.
    const key = when !== null ? `cat:errors.${reason}` : `cat:errors.${reason}_no_time`;
    return (t as unknown as (k: string, o?: Record<string, string>) => string)(key, when !== null ? { when } : undefined);
  }
  return t(`cat:errors.${reason}`);
}

// ── Texts ─────────────────────────────────────────────────────

export const CAT_COMMON_STRINGS = strings('cat', 'common', {
  secondsLeft: (seconds: number) => t('cat:common.secondsLeft', { seconds }),
});

/** Lock text for a cat (vet / shelter say "muca"); other locks are species-neutral. */
export const CAT_LOCK_STRINGS = strings('cat', 'locked', {
  ill: (until: string) => t('cat:locked.ill', { until }),
});

/** "Palica s peresom" texts (`cat:wand`). */
export const WAND_STRINGS = strings('cat', 'wand', {
  today: (done: number, goal: number) => t('cat:wand.today', { done, goal }),
  length: (seconds: number) => t('cat:wand.length', { seconds }),
  quarterA11y: (n: number) => t('cat:wand.quarterA11y', { n }),
  result: {
    escapes: (away: number) => t('cat:wand.result.escapes', { away }),
  },
});

/** "Počisti pesek" texts (`cat:scoop`). */
export const SCOOP_STRINGS = strings('cat', 'scoop', {
  progress: (done: number, total: number) => t('cat:scoop.progress', { done, total }),
  dueAt: (when: string) => t('cat:scoop.dueAt', { when }),
});

/** Grooming / weekly litter change texts (`cat:chore`). */
export const CHORE_STRINGS = strings('cat', 'chore', {
  progress: (done: number, min: number) => t('cat:chore.progress', { done, min }),
  stepA11y: (n: number) => t('cat:chore.stepA11y', { n }),
  count: (strokes: number) => t('cat:chore.count', { strokes }),
  grooming: {
    matted: (seconds: number) => t('cat:chore.grooming.matted', { seconds }),
    week: (done: number, goal: number) => t('cat:chore.grooming.week', { done, goal }),
  },
  litter_change: {
    weekOpen: (when: string) => t('cat:chore.litter_change.weekOpen', { when }),
  },
});

/** "Na praskalnik" texts (`cat:scratching`). */
export const SCRATCHING_STRINGS = strings('cat', 'scratching', {
  dueAt: (when: string) => t('cat:scratching.dueAt', { when }),
});

/** Every failure reason has a text (compile-time check). */
const _reasonTexts: {
  wand: Record<WandFailReason | 'unknown', string>;
  chore: Record<ChoreFailReason | 'unknown', string>;
  scratching: Record<ScratchingFailReason | 'unknown', string>;
} = { wand: WAND_STRINGS.result.reasons, chore: CHORE_STRINGS.reasons, scratching: SCRATCHING_STRINGS.result.reasons };
void _reasonTexts;
