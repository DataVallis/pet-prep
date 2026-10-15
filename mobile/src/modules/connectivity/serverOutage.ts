/**
 * M5-F13 (David 2026-10-14): the app survives a backend deploy.
 *
 * A production deploy puts Laravel into maintenance mode (`artisan down --retry=15` →
 * 503 with `Retry-After: 15`), Caddy / PHP-FPM restart (502 / 504 or a refused
 * connection = a network error) and Reverb restarts. None of that is the user's fault
 * and none of it may look like a broken app: no logout, no dead-end error screens, the
 * last known data stays on screen, queries retry with backoff, and a calm banner says
 * "Updating PetPrep — back in a moment." until a request succeeds again.
 *
 * Pure functions, no runtime imports (the API client and the fetch governor import
 * this module — `ApiError` is duck-typed like in `refetchGovernor`).
 */

/** Why the server can't answer right now. */
export type OutageCause =
  /** 503 without a machine-readable reason — Laravel maintenance mode (deploy). */
  | 'maintenance'
  /** 502 / 504 from Caddy — PHP-FPM restarting or not answering. */
  | 'gateway'
  /** fetch threw (connection refused / reset / no network) — not an HTTP answer at all. */
  | 'network';

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

/**
 * A 503 that carries a machine-readable `reason` / `code` is a business answer of a
 * running server (social sign-in `provider_disabled` / `provider_unavailable`, deletion
 * re-auth) — never "the server is updating".
 */
function hasMachineReason(body: unknown): boolean {
  if (!isRecord(body)) return false;
  return (typeof body.reason === 'string' && body.reason.length > 0) || (typeof body.code === 'string' && body.code.length > 0);
}

/** Outage cause of an HTTP answer, or null when the server answered normally (any 2xx–4xx, other 5xx). */
export function outageCauseForResponse(status: number, body: unknown): OutageCause | null {
  if (status === 503) return hasMachineReason(body) ? null : 'maintenance';
  if (status === 502 || status === 504) return 'gateway';
  return null;
}

/**
 * A thrown fetch error that means "couldn't reach the server": React Native's fetch
 * rejects with `TypeError('Network request failed')`. An abort (`AbortError`, our own
 * timeout / unmount) is not an outage.
 */
export function isNetworkFailure(error: unknown): boolean {
  if (!(error instanceof Error)) return false;
  if (error.name === 'AbortError') return false;
  return error instanceof TypeError;
}

function statusOf(error: unknown): number | null {
  if (!isRecord(error)) return null;
  return typeof error.status === 'number' ? error.status : null;
}

/** Outage cause of any error a query / mutation threw (an `ApiError` or a fetch failure), else null. */
export function outageCauseOfError(error: unknown): OutageCause | null {
  const status = statusOf(error);
  if (status !== null) return outageCauseForResponse(status, isRecord(error) ? error.data : undefined);
  return isNetworkFailure(error) ? 'network' : null;
}

/** Whether a failed request failed because the server is updating / unreachable. */
export function isServerOutageError(error: unknown): boolean {
  return outageCauseOfError(error) !== null;
}

// ── Backoff ─────────────────────────────────────────────────────

/** First retry after an outage error. */
export const OUTAGE_RETRY_BASE_MS = 2_000;
/** Longest wait between two retries — a deploy takes a few minutes, the app must notice the end soon. */
export const OUTAGE_RETRY_MAX_MS = 30_000;
/** Never wait less than this, even when `Retry-After` says 0. */
export const OUTAGE_RETRY_MIN_MS = 1_000;
/**
 * Automatic query retries of an outage error (2 s, 4 s, 8 s, 16 s, 30 s, 30 s ≈ 1.5 min).
 * After that the query keeps its last data and the outage probe / refetch on recovery
 * take over — nothing gives up for good.
 */
export const OUTAGE_QUERY_RETRIES = 6;

/** `Retry-After` of an outage error in ms (clamped to 1 s … 30 s), or null without one. */
export function outageRetryAfterMs(error: unknown): number | null {
  if (!isRecord(error)) return null;
  const raw = error.retryAfterSeconds;
  if (typeof raw !== 'number' || !Number.isFinite(raw) || raw < 0) return null;
  return Math.min(Math.max(raw * 1000, OUTAGE_RETRY_MIN_MS), OUTAGE_RETRY_MAX_MS);
}

/** Exponential backoff for the n-th retry (0-based): 2 s, 4 s, 8 s, 16 s, 30 s … */
export function outageBackoffMs(attempt: number): number {
  return Math.min(OUTAGE_RETRY_BASE_MS * 2 ** Math.max(0, attempt), OUTAGE_RETRY_MAX_MS);
}

/** Delay before retrying after an outage error: the server's `Retry-After` when it sent one, else the backoff. */
export function outageRetryDelayMs(attempt: number, error: unknown): number {
  return outageRetryAfterMs(error) ?? outageBackoffMs(attempt);
}

// ── Banner state ────────────────────────────────────────────────

export interface ServerStatus {
  /** The banner shows ("Updating PetPrep — back in a moment."). */
  outage: boolean;
  /** Cause of the latest failure (null after a success). */
  cause: OutageCause | null;
  /** Outage failures since the last success. */
  failures: number;
  /** Device ms when the banner appeared (null while hidden). */
  since: number | null;
  /** The server's `Retry-After` of the latest failure, in ms (clamped), else null. */
  retryAfterMs: number | null;
}

export const HEALTHY: ServerStatus = { outage: false, cause: null, failures: 0, since: null, retryAfterMs: null };

/**
 * Only an HTTP answer that says so (maintenance 503, gateway 502 / 504) STARTS the banner.
 * A bare network failure can just as well be the phone without internet (lift, tunnel,
 * airplane mode) — that is not "PetPrep is updating", and the screens' own offline hints
 * cover it. During a deploy the 503s come first (`artisan down` before the containers
 * restart), so network failures that follow only KEEP an already shown banner up.
 *
 * State after a request failed with an outage cause.
 */
export function afterOutageFailure(
  state: ServerStatus,
  cause: OutageCause,
  retryAfterMs: number | null,
  nowMs: number,
): ServerStatus {
  const failures = state.failures + 1;
  const outage = state.outage || cause !== 'network';
  return {
    outage,
    cause,
    failures,
    since: outage ? (state.since ?? nowMs) : null,
    retryAfterMs,
  };
}

/** State after the server answered (anything but an outage answer): everything is fine again. */
export function afterServerAnswered(state: ServerStatus): ServerStatus {
  return state.failures === 0 && !state.outage ? state : HEALTHY;
}

/**
 * When the outage probe asks the server again (n = probes since the banner appeared):
 * the server's `Retry-After` (≥ 3 s), else 3 s, 6 s, 12 s, 24 s, 30 s …
 */
export const PROBE_BASE_MS = 3_000;
export function probeDelayMs(attempt: number, retryAfterMs: number | null): number {
  if (retryAfterMs !== null) return Math.min(Math.max(retryAfterMs, PROBE_BASE_MS), OUTAGE_RETRY_MAX_MS);
  return Math.min(PROBE_BASE_MS * 2 ** Math.max(0, attempt), OUTAGE_RETRY_MAX_MS);
}
