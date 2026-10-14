/**
 * Shared-phone child sessions (M2-11, David 2026-10-14 "Preklop profilov v aplikaciji").
 *
 * The ACTIVE session token is always `petprep_auth_token` (every request, push and restore
 * use it). Besides it, SecureStore keeps `petprep_child_sessions`:
 * `{ [childId]: { token, parentUserId } }` — each child session a parent opened on this phone
 * with »Igraj kot« (`POST /api/parent/children/{child}/device-session`). The parent's own
 * token is revoked when the child takes over (going back = a real parent login), so only
 * child tokens are stored. A token is offered again only to the parent who opened it; when
 * another account signs in here, the other parents' tokens are revoked and dropped.
 *
 * Nothing else is stored (no e-mail, no child data). Readers validate the shape and treat
 * anything else as missing. Writes report success so a switch can abort before it revokes
 * the parent's session.
 *
 * Legacy (dev builds of the first M2-11 draft): `petprep_parked_parent` and child entries
 * that were plain token strings → revoked and deleted by `takeLegacyTokens()`.
 */

import * as SecureStore from 'expo-secure-store';

import { api } from '@/api/client';

export const CHILD_SESSIONS_KEY = 'petprep_child_sessions';
/** First M2-11 draft only (never in a store build): a parked parent session. */
export const LEGACY_PARKED_PARENT_KEY = 'petprep_parked_parent';

export interface StoredChildSession {
  token: string;
  /** The parent who opened it (only they may reuse it). */
  parentUserId: number;
}

export type ChildSessions = Record<string, StoredChildSession>;

type Store = Pick<typeof SecureStore, 'getItemAsync' | 'setItemAsync' | 'deleteItemAsync'>;

async function readJson(key: string, store: Store): Promise<unknown> {
  try {
    const raw = await store.getItemAsync(key);
    return raw ? (JSON.parse(raw) as unknown) : null;
  } catch {
    return null;
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function readEntry(value: unknown): StoredChildSession | null {
  if (!isRecord(value)) return null;
  const { token, parentUserId } = value;
  if (typeof token !== 'string' || token.length === 0 || typeof parentUserId !== 'number') return null;
  return { token, parentUserId };
}

export async function loadChildSessions(store: Store = SecureStore): Promise<ChildSessions> {
  const value = await readJson(CHILD_SESSIONS_KEY, store);
  if (!isRecord(value)) return {};
  const sessions: ChildSessions = {};
  for (const [id, raw] of Object.entries(value)) {
    const entry = readEntry(raw);
    if (/^\d+$/.test(id) && entry) sessions[id] = entry;
  }
  return sessions;
}

/** Write the whole map; false when SecureStore refused (the caller aborts). */
export async function writeChildSessions(sessions: ChildSessions, store: Store = SecureStore): Promise<boolean> {
  try {
    if (Object.keys(sessions).length === 0) await store.deleteItemAsync(CHILD_SESSIONS_KEY);
    else await store.setItemAsync(CHILD_SESSIONS_KEY, JSON.stringify(sessions));
    return true;
  } catch {
    return false;
  }
}

/** The stored session of `childId` opened by `parentUserId`, or null. */
export async function childSessionFor(childId: number, parentUserId: number, store: Store = SecureStore): Promise<StoredChildSession | null> {
  const entry = (await loadChildSessions(store))[String(childId)];
  return entry && entry.parentUserId === parentUserId ? entry : null;
}

export async function saveChildSession(childId: number, session: StoredChildSession, store: Store = SecureStore): Promise<boolean> {
  const sessions = await loadChildSessions(store);
  sessions[String(childId)] = session;
  return writeChildSessions(sessions, store);
}

/** Forget the stored session(s) holding this token (revoked / rejected). */
export async function forgetChildToken(token: string, store: Store = SecureStore): Promise<void> {
  const sessions = await loadChildSessions(store);
  const kept = Object.fromEntries(Object.entries(sessions).filter(([, s]) => s.token !== token));
  if (Object.keys(kept).length !== Object.keys(sessions).length) await writeChildSessions(kept, store);
}

/** Is this the token of a child session opened on this phone (a shared session)? */
export async function isStoredChildToken(token: string, store: Store = SecureStore): Promise<boolean> {
  return Object.values(await loadChildSessions(store)).some((s) => s.token === token);
}

/**
 * Remove the stored sessions `drop` selects and return their tokens (for revoking).
 * `drop` = every session → all of them.
 */
export async function takeChildSessions(
  drop: (session: StoredChildSession) => boolean = () => true,
  store: Store = SecureStore,
): Promise<string[]> {
  const sessions = await loadChildSessions(store);
  const taken = Object.values(sessions).filter(drop).map((s) => s.token);
  if (taken.length > 0) {
    await writeChildSessions(Object.fromEntries(Object.entries(sessions).filter(([, s]) => !drop(s))), store);
  }
  return taken;
}

/**
 * Tokens left by the first M2-11 draft (a parked parent; child entries stored as bare
 * strings). They are deleted locally and returned for revoking.
 */
export async function takeLegacyTokens(store: Store = SecureStore): Promise<string[]> {
  const tokens: string[] = [];
  const parked = await readJson(LEGACY_PARKED_PARENT_KEY, store);
  if (isRecord(parked) && typeof parked.token === 'string' && parked.token.length > 0) tokens.push(parked.token);
  if (parked !== null) {
    try {
      await store.deleteItemAsync(LEGACY_PARKED_PARENT_KEY);
    } catch {
      // Tried again on the next start.
    }
  }
  const raw = await readJson(CHILD_SESSIONS_KEY, store);
  if (isRecord(raw)) {
    const legacy = Object.entries(raw).filter(([, v]) => typeof v === 'string');
    if (legacy.length > 0) {
      tokens.push(...legacy.map(([, v]) => v as string));
      await writeChildSessions(await loadChildSessions(store), store);
    }
  }
  return tokens;
}

/** Each revoke of a token that is not the active one gets this long. */
export const REVOKE_STORED_TIMEOUT_MS = 3_000;

/**
 * Tokens the app has let go of but could not revoke yet (offline during a switch / logout,
 * QA n1): retried at app start and on every return to the foreground until the server
 * confirms (or says the token is already gone, 401). Only the bare tokens, capped.
 */
export const PENDING_REVOKES_KEY = 'petprep_pending_revokes';
export const MAX_PENDING_REVOKES = 20;

async function loadPendingRevokes(store: Store): Promise<string[]> {
  const value = await readJson(PENDING_REVOKES_KEY, store);
  return Array.isArray(value) ? value.filter((t): t is string => typeof t === 'string' && t.length > 0) : [];
}

async function writePendingRevokes(tokens: string[], store: Store): Promise<void> {
  try {
    if (tokens.length === 0) await store.deleteItemAsync(PENDING_REVOKES_KEY);
    else await store.setItemAsync(PENDING_REVOKES_KEY, JSON.stringify(tokens.slice(-MAX_PENDING_REVOKES)));
  } catch {
    // Best effort: the token still expires / can be signed out from the parent's "Odjavi vse naprave".
  }
}

/** One revoke attempt: true when the token is gone on the server (revoked now, or 401 = already gone). */
async function tryRevoke(token: string): Promise<boolean> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), REVOKE_STORED_TIMEOUT_MS);
  try {
    // The server deletes that session's push registration with the token (FK cascade).
    await api.logoutToken(token, controller.signal);
    return true;
  } catch (error) {
    return typeof error === 'object' && error !== null && 'status' in error && (error as { status: unknown }).status === 401;
  } finally {
    clearTimeout(timer);
  }
}

/** Revoke a token that is not (any more) the active session; on failure it is queued. Never throws. */
export async function revokeStoredToken(token: string, store: Store = SecureStore): Promise<void> {
  if (await tryRevoke(token)) return;
  const pending = await loadPendingRevokes(store);
  if (!pending.includes(token)) await writePendingRevokes([...pending, token], store);
}

let retrying: Promise<void> | null = null;

/** Retry the queued revokes (app start, foreground). One run at a time. Never throws. */
export function retryPendingRevokes(store: Store = SecureStore): Promise<void> {
  if (retrying) return retrying;
  retrying = (async () => {
    const pending = await loadPendingRevokes(store);
    if (pending.length === 0) return;
    const results = await Promise.all(pending.map(tryRevoke));
    // Re-read: a revoke queued meanwhile must not be lost.
    const latest = await loadPendingRevokes(store);
    const done = new Set(pending.filter((_, i) => results[i]));
    await writePendingRevokes(latest.filter((t) => !done.has(t)), store);
  })()
    .catch(() => undefined)
    .finally(() => {
      retrying = null;
    });
  return retrying;
}
