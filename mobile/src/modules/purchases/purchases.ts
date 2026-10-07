/**
 * In-app purchases via RevenueCat (`react-native-purchases`, M3-07) — infrastructure only;
 * the paywall screen and the free/paid split come with M3-09 / M3-11.
 *
 * Rules (children are minors; App Store 1.3 / Play Families: purchases only behind the
 * parent's password-protected account):
 * - The SDK is configured **lazily on the first parent session** of an app run (sign-in or
 *   session restore), directly with `appUserID = String(user.id)` — never at process start,
 *   so a child-only device never loads RevenueCat or talks to it. A later parent on the
 *   same install is switched with `Purchases.logIn`.
 * - A child session never configures, identifies, fetches offerings or purchases: every
 *   entry point checks the signed-in role first and answers `not_allowed`.
 * - `logout()` calls {@link resetPurchasesIdentity} → `Purchases.logOut()` (bounded).
 * - Missing public SDK key, Expo Go (no native module) or web → `disabled`: no SDK call,
 *   no network, every action answers `unavailable`.
 * - The server is the source of truth for what is unlocked (`GET /api/parent/entitlements`);
 *   `customerInfo` updates only invalidate that query, deduplicated by content so a burst
 *   of identical SDK events causes at most one refetch (2026-10-06 push-loop lesson: no
 *   effect may loop on state it changes).
 * - Everything that touches the network is single-flight and bounded (identify on demand
 *   only, logOut ≤ 2 s, entitlement polling 5 × 2 s).
 */

import Constants, { ExecutionEnvironment } from 'expo-constants';
import { Platform } from 'react-native';
import Purchases, {
  PURCHASES_ERROR_CODE,
  type CustomerInfo,
  type PurchasesOfferings,
  type PurchasesPackage,
} from 'react-native-purchases';

import type { Entitlement } from '@/api/client';
import { queryClient } from '@/api/queryClient';
import { ENV } from '@/config/env';
import { useAppStore } from '@/store/appStore';
import { ENTITLEMENTS_KEY, pollServerEntitlement } from './entitlements';

// ──────────────────────────────────────────────────────────────
//  Status (observable, for hooks)
// ──────────────────────────────────────────────────────────────

/**
 * - `disabled` — no key / unsupported runtime: purchases off for this app run.
 * - `idle` — available, nobody identified (signed out, child, or not configured yet).
 * - `identifying` — configure / logIn in flight.
 * - `ready` — a parent is identified; offerings and purchases may run.
 * - `error` — the last identify failed (offline); the next purchase action retries once.
 */
export type PurchasesStatus = 'disabled' | 'idle' | 'identifying' | 'ready' | 'error';

let status: PurchasesStatus = 'idle';
const statusListeners = new Set<() => void>();

function setStatus(next: PurchasesStatus): void {
  if (status === next) return;
  status = next;
  statusListeners.forEach((listener) => listener());
}

export function getPurchasesStatus(): PurchasesStatus {
  return status;
}

export function subscribePurchasesStatus(listener: () => void): () => void {
  statusListeners.add(listener);
  return () => {
    statusListeners.delete(listener);
  };
}

// ──────────────────────────────────────────────────────────────
//  Configuration
// ──────────────────────────────────────────────────────────────

export interface PurchasesKeys {
  REVENUECAT_IOS_KEY: string;
  REVENUECAT_ANDROID_KEY: string;
}

export interface ConfigureOptions {
  platform?: string;
  keys?: PurchasesKeys;
  executionEnvironment?: string;
}

/** The platform's public SDK key, or null when purchases can't run here. */
export function resolvePurchasesKey({
  platform = Platform.OS,
  keys = ENV,
  executionEnvironment = Constants.executionEnvironment,
}: ConfigureOptions = {}): string | null {
  // Expo Go has no RNPurchases native module (the SDK would fall back to a preview mode).
  if (executionEnvironment === ExecutionEnvironment.StoreClient) return null;
  const key =
    platform === 'ios' ? keys.REVENUECAT_IOS_KEY : platform === 'android' ? keys.REVENUECAT_ANDROID_KEY : '';
  const trimmed = key.trim();
  return trimmed.length > 0 ? trimmed : null;
}

let configured = false;
/** The RevenueCat app user id currently identified (a parent's `String(user.id)`). */
let identifiedUserId: string | null = null;
/** Bumped by every logout: in-flight work of an older session must not apply. */
let generation = 0;
let identifyInFlight: { userId: string; promise: Promise<boolean> } | null = null;
/** Content signature of the last `customerInfo` seen for this identity (dedupe). */
let lastInfoSignature: string | null = null;
/** The running purchase (double-tap guard). */
let purchaseInFlight: Promise<PurchaseOutcome> | null = null;

/**
 * Configure the SDK once per app run with the parent's id. Missing key / unsupported
 * runtime → `disabled` (no SDK call). Returns whether the SDK is configured.
 * Called by {@link ensureParentIdentified}; never call it for a child session.
 */
export function configurePurchases(appUserID: string, options: ConfigureOptions = {}): boolean {
  if (configured) return true;
  if (status === 'disabled') return false;
  const apiKey = resolvePurchasesKey(options);
  if (apiKey === null) {
    setStatus('disabled');
    return false;
  }
  try {
    Purchases.configure({ apiKey, appUserID });
    Purchases.addCustomerInfoUpdateListener(handleCustomerInfo);
    configured = true;
    return true;
  } catch {
    // Native module missing (not rebuilt) or invalid config: run without purchases.
    setStatus('disabled');
    return false;
  }
}

function activeEntitlementIds(info: CustomerInfo): string[] {
  return Object.keys(info.entitlements?.active ?? {}).sort();
}

function infoSignature(info: CustomerInfo): string {
  return JSON.stringify([
    activeEntitlementIds(info),
    [...(info.allPurchasedProductIdentifiers ?? [])].sort(),
    info.nonSubscriptionTransactions?.length ?? 0,
    info.latestExpirationDate ?? null,
  ]);
}

/**
 * SDK `customerInfo` listener: the store state changed → re-read the server entitlements.
 * Identical updates (the SDK repeats them on foreground, logIn, …) are ignored; the very
 * first one of an identity only matters if it already carries something active.
 */
export function handleCustomerInfo(info: CustomerInfo): void {
  if (identifiedUserId === null) return;
  const signature = infoSignature(info);
  const previous = lastInfoSignature;
  if (signature === previous) return;
  lastInfoSignature = signature;
  if (previous === null && activeEntitlementIds(info).length === 0) return;
  void queryClient.invalidateQueries({ queryKey: ENTITLEMENTS_KEY });
}

function signedInParentId(): string | null {
  const { user, authToken } = useAppStore.getState();
  if (!user || authToken === null || user.role !== 'parent') return null;
  return String(user.id);
}

/**
 * Make sure the signed-in **parent** is the RevenueCat app user. No-op (false) for a
 * child or signed-out session and when purchases are disabled. Single-flight per user;
 * one attempt per call (no retry loop) — a failure leaves `error` and the next explicit
 * purchase / offerings request tries once more.
 */
export function ensureParentIdentified(options: ConfigureOptions = {}): Promise<boolean> {
  const userId = signedInParentId();
  if (userId === null || status === 'disabled') return Promise.resolve(false);
  if (identifiedUserId === userId) return Promise.resolve(true);
  if (identifyInFlight?.userId === userId) return identifyInFlight.promise;

  const gen = generation;
  // Registered before the body runs: the configure path finishes synchronously.
  const entry: { userId: string; promise: Promise<boolean> } = { userId, promise: Promise.resolve(false) };
  identifyInFlight = entry;
  entry.promise = (async (): Promise<boolean> => {
    setStatus('identifying');
    try {
      if (!configured) {
        if (!configurePurchases(userId, options)) return false;
      } else {
        await Purchases.logIn(userId);
        if (gen !== generation) {
          // Logged out while logIn was in flight: don't leave the parent identified.
          Purchases.logOut().catch(() => undefined);
          return false;
        }
      }
      identifiedUserId = userId;
      lastInfoSignature = null;
      setStatus('ready');
      return true;
    } catch {
      if (gen === generation) setStatus('error');
      return false;
    } finally {
      if (identifyInFlight === entry) identifyInFlight = null;
    }
  })();
  return entry.promise;
}

/** Budget for `Purchases.logOut()` inside the app's logout (never delays it longer). */
export const PURCHASES_LOGOUT_TIMEOUT_MS = 2_000;

/**
 * Logout hook: forget the parent identity (also cancels in-flight identify / polling).
 * Calls `Purchases.logOut()` only when a parent was identified (logging out an anonymous
 * user is an SDK error) — bounded, never throws, clears its timer.
 */
export async function resetPurchasesIdentity({ timeoutMs = PURCHASES_LOGOUT_TIMEOUT_MS }: { timeoutMs?: number } = {}): Promise<void> {
  generation += 1;
  const wasIdentified = identifiedUserId !== null;
  identifyInFlight = null;
  identifiedUserId = null;
  lastInfoSignature = null;
  purchaseInFlight = null;
  if (status !== 'disabled') setStatus('idle');
  if (!configured || !wasIdentified) return;
  let timer: ReturnType<typeof setTimeout> | undefined;
  try {
    await Promise.race([
      Purchases.logOut(),
      new Promise<void>((resolve) => {
        timer = setTimeout(resolve, timeoutMs);
      }),
    ]);
  } catch {
    // Offline / already anonymous: the local logout must still happen.
  } finally {
    if (timer !== undefined) clearTimeout(timer);
  }
}

// ──────────────────────────────────────────────────────────────
//  Offerings, purchase, restore
// ──────────────────────────────────────────────────────────────

export type PurchaseFailure = 'cancelled' | 'pending' | 'already_owned' | 'network' | 'store_problem' | 'not_allowed' | 'unavailable';

export type PurchaseOutcome =
  /** `serverConfirmed` false = the store charged, the webhook hasn't reached the server yet. */
  | { status: 'success'; serverConfirmed: boolean }
  | { status: PurchaseFailure };

export type RestoreOutcome = { status: 'restored' | 'nothing' } | { status: Exclude<PurchaseFailure, 'cancelled' | 'pending' | 'already_owned'> };

const ERROR_MAP: Partial<Record<PURCHASES_ERROR_CODE, PurchaseFailure>> = {
  [PURCHASES_ERROR_CODE.PURCHASE_CANCELLED_ERROR]: 'cancelled',
  [PURCHASES_ERROR_CODE.PAYMENT_PENDING_ERROR]: 'pending',
  [PURCHASES_ERROR_CODE.PRODUCT_ALREADY_PURCHASED_ERROR]: 'already_owned',
  [PURCHASES_ERROR_CODE.RECEIPT_ALREADY_IN_USE_ERROR]: 'already_owned',
  [PURCHASES_ERROR_CODE.RECEIPT_IN_USE_BY_OTHER_SUBSCRIBER_ERROR]: 'already_owned',
  [PURCHASES_ERROR_CODE.NETWORK_ERROR]: 'network',
  [PURCHASES_ERROR_CODE.OFFLINE_CONNECTION_ERROR]: 'network',
  [PURCHASES_ERROR_CODE.PRODUCT_REQUEST_TIMED_OUT_ERROR]: 'network',
  [PURCHASES_ERROR_CODE.PURCHASE_NOT_ALLOWED_ERROR]: 'not_allowed',
  [PURCHASES_ERROR_CODE.INSUFFICIENT_PERMISSIONS_ERROR]: 'not_allowed',
  [PURCHASES_ERROR_CODE.INELIGIBLE_ERROR]: 'not_allowed',
  [PURCHASES_ERROR_CODE.CONFIGURATION_ERROR]: 'unavailable',
  [PURCHASES_ERROR_CODE.INVALID_CREDENTIALS_ERROR]: 'unavailable',
  [PURCHASES_ERROR_CODE.UNSUPPORTED_ERROR]: 'unavailable',
};

/** SDK error (`PurchasesError`) → one of our outcomes; anything unknown is a store problem. */
export function mapPurchaseError(error: unknown): PurchaseFailure {
  if (typeof error !== 'object' || error === null) return 'store_problem';
  const { code, userCancelled } = error as { code?: unknown; userCancelled?: unknown };
  if (userCancelled === true) return 'cancelled';
  if (typeof code === 'string' || typeof code === 'number') {
    const mapped = ERROR_MAP[String(code) as PURCHASES_ERROR_CODE];
    if (mapped) return mapped;
  }
  return 'store_problem';
}

/** Why a purchase action can't start: child / signed out, disabled, or identify failed. */
async function blockedReason(): Promise<'not_allowed' | 'unavailable' | 'network' | null> {
  if (signedInParentId() === null) return 'not_allowed';
  if (status === 'disabled') return 'unavailable';
  if (await ensureParentIdentified()) return null;
  return getPurchasesStatus() === 'disabled' ? 'unavailable' : signedInParentId() === null ? 'not_allowed' : 'network';
}

export class PurchasesUnavailableError extends Error {
  constructor(public readonly reason: 'not_allowed' | 'unavailable' | 'network') {
    super(`Purchases unavailable: ${reason}`);
    this.name = 'PurchasesUnavailableError';
  }
}

/** Store offerings for the identified parent (throws {@link PurchasesUnavailableError} otherwise). */
export async function fetchOfferings(): Promise<PurchasesOfferings> {
  const blocked = await blockedReason();
  if (blocked !== null) throw new PurchasesUnavailableError(blocked);
  return Purchases.getOfferings();
}

function writeEntitlementsCache(list: Entitlement[]): void {
  queryClient.setQueryData(ENTITLEMENTS_KEY, list);
}

/**
 * Buy one package (parent only). On success the server entitlement is polled a bounded
 * number of times, because the RevenueCat webhook reaches the server asynchronously.
 * A second call while one is running returns the same promise (double tap).
 */
export function purchasePackage(pkg: PurchasesPackage): Promise<PurchaseOutcome> {
  if (purchaseInFlight) return purchaseInFlight;
  const promise = (async (): Promise<PurchaseOutcome> => {
    const blocked = await blockedReason();
    if (blocked !== null) return { status: blocked };
    const gen = generation;
    let customerInfo: CustomerInfo;
    try {
      ({ customerInfo } = await Purchases.purchasePackage(pkg));
    } catch (error) {
      return { status: mapPurchaseError(error) };
    }
    if (gen !== generation) return { status: 'success', serverConfirmed: false };
    const serverConfirmed = await pollServerEntitlement({
      keys: activeEntitlementIds(customerInfo),
      onRead: writeEntitlementsCache,
      shouldContinue: () => gen === generation,
    });
    if (gen === generation && !serverConfirmed) void queryClient.invalidateQueries({ queryKey: ENTITLEMENTS_KEY });
    return { status: 'success', serverConfirmed };
  })().finally(() => {
    if (purchaseInFlight === promise) purchaseInFlight = null;
  });
  purchaseInFlight = promise;
  return promise;
}

/** Restore the store account's purchases onto the signed-in parent (parent only). */
export async function restorePurchases(): Promise<RestoreOutcome> {
  const blocked = await blockedReason();
  if (blocked !== null) return { status: blocked };
  const gen = generation;
  let info: CustomerInfo;
  try {
    info = await Purchases.restorePurchases();
  } catch (error) {
    const mapped = mapPurchaseError(error);
    return { status: mapped === 'network' || mapped === 'not_allowed' || mapped === 'unavailable' ? mapped : 'store_problem' };
  }
  if (gen === generation) void queryClient.invalidateQueries({ queryKey: ENTITLEMENTS_KEY });
  return { status: activeEntitlementIds(info).length > 0 ? 'restored' : 'nothing' };
}

/** Test hook: back to a fresh app run. */
export function resetPurchasesForTests(): void {
  status = 'idle';
  configured = false;
  identifiedUserId = null;
  generation = 0;
  identifyInFlight = null;
  lastInfoSignature = null;
  purchaseInFlight = null;
  statusListeners.clear();
}
