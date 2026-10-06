/**
 * Hard limits on how often the child app may ask `GET /api/child/pet` (hotfix
 * 2026-10-06, TestFlight 1.24.4 incident: 429 from `throttle:api`, 60/min per user,
 * then the dead "Kužka ni bilo mogoče naložiti" screen).
 *
 * Three independent guards, all pure (clock injected) so they can be tested without
 * React or timers:
 *
 * 1. {@link FetchGate} — a token bucket in front of every GET (whatever triggered it:
 *    boundary timer, polling, broadcast, push, media expiry, focus, retry button).
 *    Bursts of {@link FETCH_BURST} calls, then one per {@link FETCH_REFILL_MS} → at most
 *    ~10 GETs per minute sustained, no matter which code path misbehaves. A 429 closes
 *    the gate until its `Retry-After`.
 * 2. {@link BoundaryBackoff} — the time-based boundary refetch (`nextRefreshDelay`)
 *    fires at most once per {@link BOUNDARY_MIN_GAP_MS}; when it keeps firing and the
 *    state does not change (the server still reports the old state), the gap doubles up
 *    to {@link BOUNDARY_MAX_GAP_MS}.
 * 3. {@link errorRetryDelay} / {@link retryDelayFor} — automatic retries after a failed
 *    GET honour `Retry-After` and back off exponentially.
 */

// No runtime imports on purpose: the Jest setup resets the shared gate before every test.
import type { ChildPetView } from '@/modules/childPet/childPetView';

/** Calls allowed back to back (a broadcast + a boundary + a tap within a second). */
export const FETCH_BURST = 3;
/** One more call per this interval → ≤ 10 per minute sustained. */
export const FETCH_REFILL_MS = 6_000;
/** Wait after a 429 without a usable `Retry-After`. */
export const DEFAULT_RETRY_AFTER_MS = 30_000;
/** Never trust a `Retry-After` beyond this (a broken header must not freeze the HUD). */
export const MAX_RETRY_AFTER_MS = 5 * 60_000;

export class FetchAbortedError extends Error {
  constructor() {
    super('Child pet fetch aborted while waiting for the fetch gate.');
    this.name = 'FetchAbortedError';
  }
}

export interface FetchGate {
  /** ms to wait before the next call may start (0 = now). Does not consume a token. */
  waitMs(nowMs: number): number;
  /** Record that a call starts now (consumes a token). */
  take(nowMs: number): void;
  /** A 429: no call before `nowMs + retryAfterMs`. */
  block(nowMs: number, retryAfterMs: number): void;
  /** Forget everything (logout, tests). */
  reset(): void;
}

export function createFetchGate(burst: number = FETCH_BURST, refillMs: number = FETCH_REFILL_MS): FetchGate {
  let tokens = burst;
  let refilledAt = 0;
  let blockedUntil = 0;

  const refill = (nowMs: number): void => {
    if (refilledAt === 0) {
      refilledAt = nowMs;
      return;
    }
    const elapsed = nowMs - refilledAt;
    if (elapsed <= 0) return;
    const gained = Math.floor(elapsed / refillMs);
    if (gained > 0) {
      tokens = Math.min(burst, tokens + gained);
      refilledAt = tokens === burst ? nowMs : refilledAt + gained * refillMs;
    }
  };

  return {
    waitMs(nowMs) {
      refill(nowMs);
      const blocked = Math.max(0, blockedUntil - nowMs);
      const forToken = tokens >= 1 ? 0 : Math.max(0, refilledAt + refillMs - nowMs);
      return Math.max(blocked, forToken);
    },
    take(nowMs) {
      refill(nowMs);
      tokens = Math.max(0, tokens - 1);
    },
    block(nowMs, retryAfterMs) {
      blockedUntil = Math.max(blockedUntil, nowMs + retryAfterMs);
    },
    reset() {
      tokens = burst;
      refilledAt = 0;
      blockedUntil = 0;
    },
  };
}

/** HTTP status of an `ApiError` (duck-typed, see the import note), else null. */
export function errorStatus(error: unknown): number | null {
  if (typeof error !== 'object' || error === null) return null;
  const status = (error as { status?: unknown }).status;
  return typeof status === 'number' ? status : null;
}

/** `Retry-After` of a 429 in ms (clamped), or null for any other error. */
export function retryAfterMs(error: unknown): number | null {
  if (errorStatus(error) !== 429) return null;
  const raw = (error as { retryAfterSeconds?: unknown }).retryAfterSeconds;
  const seconds = typeof raw === 'number' ? raw : null;
  if (seconds === null || !Number.isFinite(seconds) || seconds < 0) return DEFAULT_RETRY_AFTER_MS;
  return Math.min(Math.max(seconds * 1000, 1_000), MAX_RETRY_AFTER_MS);
}

/** Sleep `ms` unless `signal` aborts first (then reject with {@link FetchAbortedError}). */
export function sleep(ms: number, signal?: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) {
      reject(new FetchAbortedError());
      return;
    }
    const onAbort = () => {
      clearTimeout(timer);
      reject(new FetchAbortedError());
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener('abort', onAbort);
      resolve();
    }, ms);
    signal?.addEventListener('abort', onAbort, { once: true });
  });
}

/**
 * Run `call` through `gate`: wait for a token (or the end of a 429 block), take it, call.
 * A 429 answer closes the gate for its `Retry-After`.
 */
export async function gatedCall<T>(
  gate: FetchGate,
  call: () => Promise<T>,
  options: { signal?: AbortSignal; now?: () => number } = {},
): Promise<T> {
  const now = options.now ?? Date.now;
  // Loop: another caller may have taken the token while we slept.
  for (let wait = gate.waitMs(now()); wait > 0; wait = gate.waitMs(now())) {
    await sleep(wait, options.signal);
  }
  gate.take(now());
  try {
    return await call();
  } catch (error) {
    const after = retryAfterMs(error);
    if (after !== null) gate.block(now(), after);
    throw error;
  }
}

/** The one gate for `GET /api/child/pet` (reset on logout and before every Jest test). */
export const childPetFetchGate: FetchGate = createFetchGate();

/**
 * `POST /api/broadcasting/auth` shares the per-user `throttle:api` bucket with the state
 * GET. pusher-js re-authorizes every channel on each reconnect, so a flapping socket
 * could drain the bucket on its own: burst 6 (a parent subscribes one channel per pet),
 * then one per 10 s.
 */
export const CHANNEL_AUTH_BURST = 6;
export const CHANNEL_AUTH_REFILL_MS = 10_000;
export const channelAuthGate: FetchGate = createFetchGate(CHANNEL_AUTH_BURST, CHANNEL_AUTH_REFILL_MS);

// ── Boundary refetch backoff ────────────────────────────────────

/** Never two boundary refetches closer than this. */
export const BOUNDARY_MIN_GAP_MS = 30_000;
/** Backoff ceiling while the server keeps answering the same state. */
export const BOUNDARY_MAX_GAP_MS = 5 * 60_000;

export interface BoundaryBackoff {
  /** Device ms of the last boundary refetch; 0 = none yet. */
  lastFireMs: number;
  /** Consecutive boundary refetches that brought no change. */
  unchangedStreak: number;
  /** {@link viewSignature} when the last boundary refetch fired. */
  signatureAtFire: string | null;
}

export const NO_BOUNDARY_BACKOFF: BoundaryBackoff = { lastFireMs: 0, unchangedStreak: 0, signatureAtFire: null };

/**
 * What the child can see and do — everything except clocks and re-signed media URLs
 * (those change with every answer even when nothing else does).
 */
export function viewSignature(view: ChildPetView): string {
  const { media: _media, current_video_url: _video, reference_image_url: _image, ...pet } = view.pet;
  return JSON.stringify([pet, view.lock, view.feeding, view.water, view.steps, view.contract, view.behaviour]);
}

/** Minimum gap after the last boundary refetch for the current streak (30 s, 60 s, 120 s … 5 min). */
export function boundaryGapMs(streak: number): number {
  return Math.min(BOUNDARY_MIN_GAP_MS * 2 ** Math.max(0, streak), BOUNDARY_MAX_GAP_MS);
}

/**
 * Delay for the next boundary refetch: the boundary's own delay, but never sooner than
 * the backoff gap after the previous boundary refetch.
 */
export function boundaryDelayMs(rawDelayMs: number, backoff: BoundaryBackoff, nowMs: number): number {
  if (backoff.lastFireMs === 0) return Math.max(0, rawDelayMs);
  const earliest = backoff.lastFireMs + boundaryGapMs(backoff.unchangedStreak) - nowMs;
  return Math.max(0, rawDelayMs, earliest);
}

/** A new state arrived: a changed one ends the streak. */
export function noteViewForBackoff(backoff: BoundaryBackoff, signature: string): BoundaryBackoff {
  if (backoff.signatureAtFire !== null && backoff.signatureAtFire !== signature) {
    return { ...backoff, unchangedStreak: 0, signatureAtFire: null };
  }
  return backoff;
}

/** The boundary timer fires now. Unchanged since the previous fire → longer gap next time. */
export function noteBoundaryFire(backoff: BoundaryBackoff, signature: string, nowMs: number): BoundaryBackoff {
  const unchanged = backoff.signatureAtFire !== null && backoff.signatureAtFire === signature;
  return {
    lastFireMs: nowMs,
    unchangedStreak: unchanged ? backoff.unchangedStreak + 1 : 0,
    signatureAtFire: signature,
  };
}

// ── Retries after a failed GET ──────────────────────────────────

/** Automatic retries of a failed GET: `Retry-After` for a 429, else 2 s, 4 s, 8 s … */
export function retryDelayFor(failureCount: number, error: unknown): number {
  const after = retryAfterMs(error);
  if (after !== null) return after;
  return Math.min(2_000 * 2 ** Math.max(0, failureCount), 30_000);
}

/** First / max delay of the HUD's "try again by itself" loop after the query gave up. */
export const ERROR_RETRY_BASE_MS = 15_000;
export const ERROR_RETRY_MAX_MS = 2 * 60_000;

/**
 * When the HUD retries on its own after `errorCount` failed attempts (the query is in
 * the error state, with or without a cached view): `Retry-After` for a 429, else
 * 15 s, 30 s, 60 s, 120 s.
 */
export function errorRetryDelay(errorCount: number, error: unknown): number {
  const backoff = Math.min(ERROR_RETRY_BASE_MS * 2 ** Math.max(0, errorCount - 1), ERROR_RETRY_MAX_MS);
  const after = retryAfterMs(error);
  return after !== null ? Math.max(after, ERROR_RETRY_BASE_MS) : backoff;
}
