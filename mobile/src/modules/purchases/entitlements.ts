/**
 * Server entitlements (M3-07). The server — not the RevenueCat SDK — decides what is
 * unlocked: the RevenueCat webhook (M3-08), the server-side trial (M3-11) and admin
 * grants all land in `GET /api/parent/entitlements`. The SDK's `customerInfo` is only a
 * hint that something changed (→ invalidate this query).
 */

import { api, type Entitlement, type EntitlementsResponse } from '@/api/client';

/** TanStack Query key of the family's server entitlements (shared with the backend agent's contract). */
export const ENTITLEMENTS_KEY = ['parent', 'entitlements'] as const;

function stringOrNull(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 ? value : null;
}

function readEntitlement(raw: unknown): Entitlement | null {
  if (typeof raw !== 'object' || raw === null) return null;
  const item = raw as Record<string, unknown>;
  const key = stringOrNull(item.key);
  if (key === null) return null;
  return {
    key,
    active: item.active === true,
    source: stringOrNull(item.source),
    store: stringOrNull(item.store),
    granted_at: stringOrNull(item.granted_at),
    expires_at: stringOrNull(item.expires_at),
  };
}

/**
 * Loose `GET /api/parent/entitlements` body → cleaned list. Unknown shapes give an
 * empty list (nothing unlocked) rather than throwing: locked is the safe default.
 */
export function readEntitlements(body: unknown): Entitlement[] {
  if (typeof body !== 'object' || body === null) return [];
  const list = (body as Partial<EntitlementsResponse>).entitlements;
  if (!Array.isArray(list)) return [];
  return list.map(readEntitlement).filter((item): item is Entitlement => item !== null);
}

export async function fetchEntitlements(): Promise<Entitlement[]> {
  return readEntitlements(await api.getEntitlements());
}

/** True when the server lists `key` as active (an `expires_at` in the past counts as inactive). */
export function isEntitlementActive(list: readonly Entitlement[] | undefined, key: string, now: number = Date.now()): boolean {
  return (list ?? []).some((item) => {
    if (item.key !== key || !item.active) return false;
    if (item.expires_at === null) return true;
    const expires = Date.parse(item.expires_at);
    return Number.isNaN(expires) || expires > now;
  });
}

/** Any of `keys` active — or, with no keys, any entitlement at all. */
export function anyEntitlementActive(list: readonly Entitlement[], keys: readonly string[], now: number = Date.now()): boolean {
  if (keys.length === 0) return list.some((item) => isEntitlementActive([item], item.key, now));
  return keys.some((key) => isEntitlementActive(list, key, now));
}

/** Bounded wait for the async webhook after a store purchase: 5 reads, 2 s apart (≈ 8 s). */
export const ENTITLEMENT_POLL_ATTEMPTS = 5;
export const ENTITLEMENT_POLL_INTERVAL_MS = 2_000;

export interface PollOptions {
  /** Entitlement keys the purchase should unlock; empty → any active entitlement. */
  keys: readonly string[];
  fetch?: () => Promise<Entitlement[]>;
  /** Called with every successful read (e.g. to write the query cache). */
  onRead?: (list: Entitlement[]) => void;
  /** Checked before every read and wait: false → stop (logout / unmount). */
  shouldContinue?: () => boolean;
  attempts?: number;
  intervalMs?: number;
}

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

/**
 * Read the server entitlements until one of `keys` is active, at most `attempts` times
 * with `intervalMs` between reads. Never loops on its own result, never throws (a failed
 * read just counts as an attempt). → true once confirmed, false when it gave up.
 */
export async function pollServerEntitlement({
  keys,
  fetch = fetchEntitlements,
  onRead,
  shouldContinue = () => true,
  attempts = ENTITLEMENT_POLL_ATTEMPTS,
  intervalMs = ENTITLEMENT_POLL_INTERVAL_MS,
}: PollOptions): Promise<boolean> {
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    if (attempt > 0) await sleep(intervalMs);
    if (!shouldContinue()) return false;
    try {
      const list = await fetch();
      if (!shouldContinue()) return false;
      onRead?.(list);
      if (anyEntitlementActive(list, keys)) return true;
    } catch {
      // Offline / 5xx / 429: counts as an attempt; the bound still holds.
    }
  }
  return false;
}
