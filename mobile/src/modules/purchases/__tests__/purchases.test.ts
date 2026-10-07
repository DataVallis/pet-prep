import Purchases from 'react-native-purchases';

import { api } from '@/api/client';
import { queryClient } from '@/api/queryClient';
import { i18n } from '@/i18n';
import { logout } from '@/modules/session/logout';
import { useAppStore } from '@/store/appStore';
import { ENTITLEMENTS_KEY, isEntitlementActive, pollServerEntitlement, readEntitlements } from '../entitlements';
import { purchaseOutcomeMessage, restoreOutcomeMessage } from '../messages';
import {
  ensureParentIdentified,
  fetchOfferings,
  getPurchasesStatus,
  handleCustomerInfo,
  mapPurchaseError,
  purchasePackage,
  PURCHASES_LOGOUT_TIMEOUT_MS,
  resetPurchasesForTests,
  resetPurchasesIdentity,
  resolvePurchasesKey,
  restorePurchases,
  type ConfigureOptions,
} from '../purchases';

// The manual mock's fixtures (not part of the real package's types).
const { makeCustomerInfo, makePurchasesError, PURCHASES_ERROR_CODE } = jest.requireMock<{
  makeCustomerInfo: (active?: string[], products?: string[]) => ReturnType<typeof Object>;
  makePurchasesError: (code: string, userCancelled?: boolean | null) => object;
  PURCHASES_ERROR_CODE: Record<string, string>;
}>('react-native-purchases');

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, logout: jest.fn(() => Promise.resolve({ message: 'ok' })), getEntitlements: jest.fn() } };
});

const sdk = Purchases as unknown as Record<string, jest.Mock>;
const getEntitlements = api.getEntitlements as jest.Mock;

const KEYS = { REVENUECAT_IOS_KEY: 'appl_test', REVENUECAT_ANDROID_KEY: 'goog_test' };
const OPTS: ConfigureOptions = { platform: 'ios', keys: KEYS, executionEnvironment: 'bare' };
const PKG = { identifier: '$rc_lifetime' } as unknown as Parameters<typeof purchasePackage>[0];

function signIn(role: 'parent' | 'child', id = 7) {
  useAppStore.getState().signIn({
    token: `tok-${id}`,
    user: { id, name: 'X', email: role === 'parent' ? 'p@x.si' : null, role },
    pet: null,
  });
}

const active = (key = 'challenge') => ({ entitlements: [{ key, active: true, source: 'purchase', store: 'app_store', granted_at: null, expires_at: null }] });
const inactive = { entitlements: [] };

beforeEach(() => {
  jest.clearAllMocks();
  resetPurchasesForTests();
  queryClient.clear();
  useAppStore.setState(useAppStore.getInitialState(), true);
});

afterEach(() => {
  jest.useRealTimers();
});

describe('resolvePurchasesKey', () => {
  it('picks the platform key and trims it', () => {
    expect(resolvePurchasesKey(OPTS)).toBe('appl_test');
    expect(resolvePurchasesKey({ ...OPTS, platform: 'android' })).toBe('goog_test');
    expect(resolvePurchasesKey({ ...OPTS, keys: { ...KEYS, REVENUECAT_IOS_KEY: '  appl_x ' } })).toBe('appl_x');
  });

  it('is null for a missing key, web and Expo Go', () => {
    expect(resolvePurchasesKey({ ...OPTS, keys: { ...KEYS, REVENUECAT_IOS_KEY: '' } })).toBeNull();
    expect(resolvePurchasesKey({ ...OPTS, keys: { ...KEYS, REVENUECAT_IOS_KEY: '   ' } })).toBeNull();
    expect(resolvePurchasesKey({ ...OPTS, platform: 'web' })).toBeNull();
    expect(resolvePurchasesKey({ ...OPTS, executionEnvironment: 'storeClient' })).toBeNull();
  });
});

describe('missing key', () => {
  it('disables purchases without touching the SDK or the network', async () => {
    signIn('parent');
    const noKey = { ...OPTS, keys: { REVENUECAT_IOS_KEY: '', REVENUECAT_ANDROID_KEY: '' } };

    await expect(ensureParentIdentified(noKey)).resolves.toBe(false);
    expect(getPurchasesStatus()).toBe('disabled');
    await expect(purchasePackage(PKG)).resolves.toEqual({ status: 'unavailable' });
    await expect(restorePurchases()).resolves.toEqual({ status: 'unavailable' });
    await expect(fetchOfferings()).rejects.toMatchObject({ reason: 'unavailable' });
    await logout();

    for (const fn of Object.values(sdk)) if (jest.isMockFunction(fn)) expect(fn).not.toHaveBeenCalled();
    expect(getEntitlements).not.toHaveBeenCalled();
  });

  it('a throwing configure (native module missing) also just disables', async () => {
    sdk.configure.mockImplementationOnce(() => {
      throw new Error('RNPurchases not found');
    });
    signIn('parent');
    await expect(ensureParentIdentified(OPTS)).resolves.toBe(false);
    expect(getPurchasesStatus()).toBe('disabled');
  });
});

describe('identity', () => {
  it('configures once with the parent id as appUserID (no anonymous user, no logIn)', async () => {
    signIn('parent', 7);
    const [a, b] = await Promise.all([ensureParentIdentified(OPTS), ensureParentIdentified(OPTS)]);
    expect(a && b).toBe(true);
    await ensureParentIdentified(OPTS);

    expect(sdk.configure).toHaveBeenCalledTimes(1);
    expect(sdk.configure).toHaveBeenCalledWith({ apiKey: 'appl_test', appUserID: '7' });
    expect(sdk.addCustomerInfoUpdateListener).toHaveBeenCalledTimes(1);
    expect(sdk.logIn).not.toHaveBeenCalled();
    expect(getPurchasesStatus()).toBe('ready');
  });

  it('never configures, identifies or buys in a child session', async () => {
    signIn('child', 9);
    await expect(ensureParentIdentified(OPTS)).resolves.toBe(false);
    await expect(purchasePackage(PKG)).resolves.toEqual({ status: 'not_allowed' });
    await expect(restorePurchases()).resolves.toEqual({ status: 'not_allowed' });
    await expect(fetchOfferings()).rejects.toMatchObject({ reason: 'not_allowed' });

    expect(sdk.configure).not.toHaveBeenCalled();
    expect(sdk.logIn).not.toHaveBeenCalled();
    expect(sdk.purchasePackage).not.toHaveBeenCalled();
    expect(sdk.restorePurchases).not.toHaveBeenCalled();
    expect(sdk.getOfferings).not.toHaveBeenCalled();
  });

  it('a child after a parent on the same install gets nothing (no logIn)', async () => {
    signIn('parent', 7);
    await ensureParentIdentified(OPTS);
    await logout();
    signIn('child', 9);
    await expect(ensureParentIdentified(OPTS)).resolves.toBe(false);
    await expect(purchasePackage(PKG)).resolves.toEqual({ status: 'not_allowed' });
    expect(sdk.logIn).not.toHaveBeenCalled();
    expect(sdk.purchasePackage).not.toHaveBeenCalled();
  });

  it('logout() logs the parent out of RevenueCat; the next parent is logged in', async () => {
    signIn('parent', 7);
    await ensureParentIdentified(OPTS);
    await logout();
    expect(sdk.logOut).toHaveBeenCalledTimes(1);
    expect(getPurchasesStatus()).toBe('idle');

    signIn('parent', 8);
    await expect(ensureParentIdentified(OPTS)).resolves.toBe(true);
    expect(sdk.logIn).toHaveBeenCalledWith('8');
    expect(sdk.configure).toHaveBeenCalledTimes(1);
  });

  it('logout of a child session never calls the SDK', async () => {
    signIn('child', 9);
    await logout();
    expect(sdk.logOut).not.toHaveBeenCalled();
  });

  it('a hanging RevenueCat logOut is cut off after the budget and leaves no timer', async () => {
    jest.useFakeTimers();
    signIn('parent', 7);
    await ensureParentIdentified(OPTS);
    sdk.logOut.mockImplementationOnce(() => new Promise(() => undefined));
    let done = false;
    void resetPurchasesIdentity().then(() => {
      done = true;
    });
    await jest.advanceTimersByTimeAsync(PURCHASES_LOGOUT_TIMEOUT_MS - 1);
    expect(done).toBe(false);
    await jest.advanceTimersByTimeAsync(1);
    expect(done).toBe(true);
    expect(jest.getTimerCount()).toBe(0);
  });

  it('a logIn that finishes after logout is undone, not applied', async () => {
    signIn('parent', 7);
    await ensureParentIdentified(OPTS);
    await logout();
    signIn('parent', 8);
    let finishLogIn: () => void = () => undefined;
    sdk.logIn.mockImplementationOnce(() => new Promise((resolve) => (finishLogIn = () => resolve({ customerInfo: makeCustomerInfo(), created: false }))));
    const pending = ensureParentIdentified(OPTS);
    await resetPurchasesIdentity(); // logout while logIn is in flight
    sdk.logOut.mockClear();
    finishLogIn();
    await expect(pending).resolves.toBe(false);
    expect(sdk.logOut).toHaveBeenCalledTimes(1);
    expect(getPurchasesStatus()).toBe('idle');
  });

  it('a failed logIn sets error once and does not retry by itself', async () => {
    signIn('parent', 7);
    await ensureParentIdentified(OPTS);
    await logout();
    signIn('parent', 8);
    sdk.logIn.mockRejectedValueOnce(makePurchasesError(PURCHASES_ERROR_CODE.NETWORK_ERROR));
    await expect(ensureParentIdentified(OPTS)).resolves.toBe(false);
    expect(getPurchasesStatus()).toBe('error');
    expect(sdk.logIn).toHaveBeenCalledTimes(1);
    // The next explicit action tries exactly once more.
    await expect(restorePurchases()).resolves.toEqual({ status: 'nothing' });
    expect(sdk.logIn).toHaveBeenCalledTimes(2);
  });
});

describe('mapPurchaseError', () => {
  it.each([
    ['PURCHASE_CANCELLED_ERROR', 'cancelled'],
    ['PAYMENT_PENDING_ERROR', 'pending'],
    ['PRODUCT_ALREADY_PURCHASED_ERROR', 'already_owned'],
    ['RECEIPT_ALREADY_IN_USE_ERROR', 'already_owned'],
    ['NETWORK_ERROR', 'network'],
    ['OFFLINE_CONNECTION_ERROR', 'network'],
    ['PURCHASE_NOT_ALLOWED_ERROR', 'not_allowed'],
    ['INSUFFICIENT_PERMISSIONS_ERROR', 'not_allowed'],
    ['CONFIGURATION_ERROR', 'unavailable'],
    ['STORE_PROBLEM_ERROR', 'store_problem'],
    ['PRODUCT_NOT_AVAILABLE_FOR_PURCHASE_ERROR', 'store_problem'],
    ['UNKNOWN_ERROR', 'store_problem'],
  ])('%s → %s', (name, expected) => {
    expect(mapPurchaseError(makePurchasesError(PURCHASES_ERROR_CODE[name]))).toBe(expected);
  });

  it('userCancelled wins; garbage is a store problem', () => {
    expect(mapPurchaseError(makePurchasesError(PURCHASES_ERROR_CODE.UNKNOWN_ERROR, true))).toBe('cancelled');
    expect(mapPurchaseError(new Error('boom'))).toBe('store_problem');
    expect(mapPurchaseError(null)).toBe('store_problem');
    expect(mapPurchaseError({ code: 'nope' })).toBe('store_problem');
  });
});

describe('purchasePackage', () => {
  beforeEach(async () => {
    signIn('parent', 7);
    await ensureParentIdentified(OPTS);
  });

  it('success: polls the server until the entitlement is active (bounded)', async () => {
    jest.useFakeTimers();
    sdk.purchasePackage.mockResolvedValueOnce({ productIdentifier: 'p', customerInfo: makeCustomerInfo(['challenge']), transaction: {} });
    getEntitlements.mockResolvedValueOnce(inactive).mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce(active());

    const result = purchasePackage(PKG);
    await jest.advanceTimersByTimeAsync(10_000);
    await expect(result).resolves.toEqual({ status: 'success', serverConfirmed: true });
    expect(getEntitlements).toHaveBeenCalledTimes(3);
    expect(isEntitlementActive(queryClient.getQueryData(ENTITLEMENTS_KEY), 'challenge')).toBe(true);
  });

  it('success: gives up after 5 reads × 2 s when the webhook is late', async () => {
    jest.useFakeTimers();
    sdk.purchasePackage.mockResolvedValueOnce({ productIdentifier: 'p', customerInfo: makeCustomerInfo(['challenge']), transaction: {} });
    getEntitlements.mockResolvedValue(inactive);
    const invalidate = jest.spyOn(queryClient, 'invalidateQueries');

    const result = purchasePackage(PKG);
    await jest.advanceTimersByTimeAsync(60_000);
    await expect(result).resolves.toEqual({ status: 'success', serverConfirmed: false });
    expect(getEntitlements).toHaveBeenCalledTimes(5);
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ENTITLEMENTS_KEY });
    // Nothing keeps polling (the only timer left is TanStack's cache GC of the written list).
    await jest.advanceTimersByTimeAsync(10 * 60_000);
    expect(getEntitlements).toHaveBeenCalledTimes(5);
  });

  it('logout stops the polling', async () => {
    jest.useFakeTimers();
    sdk.purchasePackage.mockResolvedValueOnce({ productIdentifier: 'p', customerInfo: makeCustomerInfo(['challenge']), transaction: {} });
    getEntitlements.mockResolvedValue(inactive);
    const result = purchasePackage(PKG);
    await jest.advanceTimersByTimeAsync(0);
    expect(getEntitlements).toHaveBeenCalledTimes(1);
    await resetPurchasesIdentity();
    await jest.advanceTimersByTimeAsync(20_000);
    await expect(result).resolves.toEqual({ status: 'success', serverConfirmed: false });
    expect(getEntitlements).toHaveBeenCalledTimes(1);
  });

  it.each([
    ['PURCHASE_CANCELLED_ERROR', 'cancelled'],
    ['PAYMENT_PENDING_ERROR', 'pending'],
    ['PRODUCT_ALREADY_PURCHASED_ERROR', 'already_owned'],
    ['NETWORK_ERROR', 'network'],
    ['STORE_PROBLEM_ERROR', 'store_problem'],
    ['PURCHASE_NOT_ALLOWED_ERROR', 'not_allowed'],
  ])('store error %s → %s, no server polling', async (name, expected) => {
    sdk.purchasePackage.mockRejectedValueOnce(makePurchasesError(PURCHASES_ERROR_CODE[name]));
    await expect(purchasePackage(PKG)).resolves.toEqual({ status: expected });
    expect(getEntitlements).not.toHaveBeenCalled();
  });

  it('a double tap starts one purchase', async () => {
    sdk.purchasePackage.mockRejectedValueOnce(makePurchasesError(PURCHASES_ERROR_CODE.PURCHASE_CANCELLED_ERROR));
    const first = purchasePackage(PKG);
    const second = purchasePackage(PKG);
    expect(second).toBe(first);
    await first;
    expect(sdk.purchasePackage).toHaveBeenCalledTimes(1);
  });
});

describe('restorePurchases', () => {
  beforeEach(async () => {
    signIn('parent', 7);
    await ensureParentIdentified(OPTS);
  });

  it('restored / nothing, and re-reads the server entitlements', async () => {
    const invalidate = jest.spyOn(queryClient, 'invalidateQueries');
    sdk.restorePurchases.mockResolvedValueOnce(makeCustomerInfo(['challenge']));
    await expect(restorePurchases()).resolves.toEqual({ status: 'restored' });
    await expect(restorePurchases()).resolves.toEqual({ status: 'nothing' });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ENTITLEMENTS_KEY });
  });

  it('maps errors (a cancel-like error is a store problem)', async () => {
    sdk.restorePurchases.mockRejectedValueOnce(makePurchasesError(PURCHASES_ERROR_CODE.NETWORK_ERROR));
    await expect(restorePurchases()).resolves.toEqual({ status: 'network' });
    sdk.restorePurchases.mockRejectedValueOnce(makePurchasesError(PURCHASES_ERROR_CODE.PAYMENT_PENDING_ERROR));
    await expect(restorePurchases()).resolves.toEqual({ status: 'store_problem' });
  });
});

describe('customerInfo listener', () => {
  it('invalidates the server entitlements only when the content changes', async () => {
    signIn('parent', 7);
    await ensureParentIdentified(OPTS);
    const listener = sdk.addCustomerInfoUpdateListener.mock.calls[0][0] as typeof handleCustomerInfo;
    const invalidate = jest.spyOn(queryClient, 'invalidateQueries');

    listener(makeCustomerInfo() as never); // baseline, nothing active → no refetch
    expect(invalidate).not.toHaveBeenCalled();
    for (let i = 0; i < 50; i += 1) listener(makeCustomerInfo(['challenge'], ['petprep_challenge_12w']) as never);
    expect(invalidate).toHaveBeenCalledTimes(1);
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ENTITLEMENTS_KEY });
    // Refetching entitlements doesn't feed back into the SDK: no further events, no loop.
    listener(makeCustomerInfo() as never);
    expect(invalidate).toHaveBeenCalledTimes(2);
  });

  it('is ignored while nobody is identified', () => {
    const invalidate = jest.spyOn(queryClient, 'invalidateQueries');
    handleCustomerInfo(makeCustomerInfo(['challenge']) as never);
    expect(invalidate).not.toHaveBeenCalled();
  });
});

describe('entitlements', () => {
  it('reads loose bodies safely (locked by default)', () => {
    expect(readEntitlements(null)).toEqual([]);
    expect(readEntitlements({ entitlements: 'x' })).toEqual([]);
    expect(readEntitlements({ entitlements: [{ key: 'challenge', active: 1 }, { active: true }, null] })).toEqual([
      { key: 'challenge', active: false, source: null, store: null, granted_at: null, expires_at: null },
    ]);
  });

  it('treats a past expires_at as inactive', () => {
    const now = Date.parse('2026-10-07T12:00:00Z');
    const list = readEntitlements({ entitlements: [{ key: 'challenge', active: true, expires_at: '2026-10-07T11:00:00Z' }] });
    expect(isEntitlementActive(list, 'challenge', now)).toBe(false);
    expect(isEntitlementActive(list, 'challenge', Date.parse('2026-10-07T10:00:00Z'))).toBe(true);
  });

  it('pollServerEntitlement never exceeds its attempts', async () => {
    const fetch = jest.fn(() => Promise.reject(new Error('500')));
    await expect(pollServerEntitlement({ keys: ['challenge'], fetch, intervalMs: 0, attempts: 3 })).resolves.toBe(false);
    expect(fetch).toHaveBeenCalledTimes(3);
  });
});

describe('messages', () => {
  afterEach(async () => {
    await i18n.changeLanguage('sl');
  });

  it('every outcome has a message in both languages', async () => {
    const outcomes = [
      { status: 'success', serverConfirmed: true },
      { status: 'success', serverConfirmed: false },
      { status: 'cancelled' },
      { status: 'pending' },
      { status: 'already_owned' },
      { status: 'network' },
      { status: 'store_problem' },
      { status: 'not_allowed' },
      { status: 'unavailable' },
    ] as const;
    const sl = outcomes.map((o) => purchaseOutcomeMessage(o));
    await i18n.changeLanguage('en');
    const en = outcomes.map((o) => purchaseOutcomeMessage(o));
    expect(new Set(sl).size).toBe(outcomes.length);
    expect(new Set(en).size).toBe(outcomes.length);
    expect(sl[3]).toBe('Nakup čaka na odobritev. Odklene se takoj, ko je odobren.');
    expect(en[2]).toBe('Purchase cancelled. You were not charged.');
    expect(restoreOutcomeMessage({ status: 'nothing' })).toBe('No previous purchases found for this store account.');
    expect(restoreOutcomeMessage({ status: 'network' })).toBe(en[5]);
  });
});
