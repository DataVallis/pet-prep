/**
 * Server billing state (M3-09 / M3-11, replaces the M3-07 `/api/parent/entitlements`).
 * The server — not the RevenueCat SDK — decides what is paid: the RevenueCat webhook
 * (M3-08) turns each store purchase into one **challenge credit** for the family, the app
 * assigns it to a pet (`POST /api/parent/pets/{pet}/challenge/activate`), the server runs
 * the 7-day trial clock. `GET /api/parent/billing` returns the unassigned credits and the
 * plan + status of every family pet. The SDK's `customerInfo` is only a hint that
 * something changed (→ invalidate this query).
 */

import { api, type BillingPet, type BillingResponse, type ChallengeStatus, type PetPlanType } from '@/api/client';

/** TanStack Query key of the family's billing state (shared contract with the backend agent). */
export const BILLING_KEY = ['parent', 'billing'] as const;

export type Billing = BillingResponse;

export const EMPTY_BILLING: Billing = { credits_available: 0, pets: [] };

const PLAN_TYPES: readonly PetPlanType[] = ['free', 'challenge'];
const STATUSES: readonly ChallengeStatus[] = ['trial', 'payment_required', 'paid'];

function strOrNull(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 ? value : null;
}

function readBillingPet(raw: unknown): BillingPet | null {
  if (typeof raw !== 'object' || raw === null) return null;
  const o = raw as Record<string, unknown>;
  if (typeof o.pet_id !== 'number' || !Number.isFinite(o.pet_id)) return null;
  const plan = PLAN_TYPES.find((p) => p === o.plan) ?? 'challenge';
  return {
    pet_id: o.pet_id,
    plan,
    status: plan === 'free' ? null : (STATUSES.find((s) => s === o.status) ?? null),
    trial_ends_at: plan === 'free' ? null : strOrNull(o.trial_ends_at),
    paid_at: plan === 'free' ? null : strOrNull(o.paid_at),
  };
}

/**
 * Loose `GET /api/parent/billing` body → cleaned. Unknown shapes give no credits and no
 * pets (never throws): with nothing listed the paywall offers nothing to buy.
 */
export function readBilling(body: unknown): Billing {
  if (typeof body !== 'object' || body === null) return EMPTY_BILLING;
  const o = body as Record<string, unknown>;
  const credits = typeof o.credits_available === 'number' && Number.isFinite(o.credits_available) ? Math.max(0, Math.floor(o.credits_available)) : 0;
  const pets = Array.isArray(o.pets) ? o.pets.map(readBillingPet).filter((p): p is BillingPet => p !== null) : [];
  return { credits_available: credits, pets };
}

export async function fetchBilling(): Promise<Billing> {
  return readBilling(await api.getBilling());
}

export function billingPet(billing: Billing | undefined, petId: number): BillingPet | null {
  return billing?.pets.find((p) => p.pet_id === petId) ?? null;
}

/** What a store purchase is for: the pet being bought for (if any) and the credits seen before it. */
export interface PurchaseTarget {
  petId: number | null;
  /** `credits_available` before the purchase; the webhook adds one. */
  baselineCredits: number;
}

/** The server has the purchase: the pet is paid (webhook auto-assigned) or a new credit exists. */
export function purchaseLanded(billing: Billing, target: PurchaseTarget): boolean {
  if (target.petId !== null && billingPet(billing, target.petId)?.status === 'paid') return true;
  return billing.credits_available > target.baselineCredits;
}

/** Bounded wait for the async webhook after a store purchase: 5 reads, 2 s apart (≈ 8 s). */
export const BILLING_POLL_ATTEMPTS = 5;
export const BILLING_POLL_INTERVAL_MS = 2_000;

export interface PollOptions {
  target: PurchaseTarget;
  fetch?: () => Promise<Billing>;
  /** Called with every successful read (e.g. to write the query cache). */
  onRead?: (billing: Billing) => void;
  /** Checked before every read and wait: false → stop (logout / unmount). */
  shouldContinue?: () => boolean;
  attempts?: number;
  intervalMs?: number;
}

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

/**
 * Read the billing state until the purchase has landed ({@link purchaseLanded}), at most
 * `attempts` times with `intervalMs` between reads. Never loops on its own result, never
 * throws (a failed read just counts as an attempt). → true once confirmed, false when it gave up.
 */
export async function pollBilling({
  target,
  fetch = fetchBilling,
  onRead,
  shouldContinue = () => true,
  attempts = BILLING_POLL_ATTEMPTS,
  intervalMs = BILLING_POLL_INTERVAL_MS,
}: PollOptions): Promise<boolean> {
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    if (attempt > 0) await sleep(intervalMs);
    if (!shouldContinue()) return false;
    try {
      const billing = await fetch();
      if (!shouldContinue()) return false;
      onRead?.(billing);
      if (purchaseLanded(billing, target)) return true;
    } catch {
      // Offline / 5xx / 429: counts as an attempt; the bound still holds.
    }
  }
  return false;
}
