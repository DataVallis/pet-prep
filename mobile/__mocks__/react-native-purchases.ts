/**
 * Manual mock of `react-native-purchases` (M3-07): no native module in Jest. Every SDK
 * call is a `jest.fn` with a harmless default; the enums are the real ones from
 * `@revenuecat/purchases-typescript-internal` (plain JS), so error-code mapping is tested
 * against the actual values. Fixtures: `makeCustomerInfo`, `makePurchasesError`.
 */

const internal = jest.requireActual<typeof import('@revenuecat/purchases-typescript-internal')>(
  '@revenuecat/purchases-typescript-internal',
);

export const { PURCHASES_ERROR_CODE, LOG_LEVEL, PACKAGE_TYPE } = internal;

type CustomerInfo = import('@revenuecat/purchases-typescript-internal').CustomerInfo;

/** Minimal CustomerInfo with the given active entitlement ids. */
export function makeCustomerInfo(active: string[] = [], products: string[] = []): CustomerInfo {
  const entitlements = Object.fromEntries(active.map((id) => [id, { identifier: id, isActive: true }]));
  return {
    entitlements: { all: entitlements, active: entitlements, verification: 'NOT_REQUESTED' },
    activeSubscriptions: [],
    allPurchasedProductIdentifiers: products,
    latestExpirationDate: null,
    firstSeen: '2026-10-07T00:00:00Z',
    originalAppUserId: 'mock',
    requestDate: '2026-10-07T00:00:00Z',
    allExpirationDates: {},
    allPurchaseDates: {},
    originalApplicationVersion: null,
    originalPurchaseDate: null,
    managementURL: null,
    nonSubscriptionTransactions: [],
    subscriptionsByProductIdentifier: {},
  } as unknown as CustomerInfo;
}

export function makePurchasesError(code: string, userCancelled: boolean | null = null) {
  return { code, message: `mock error ${code}`, readableErrorCode: code, userInfo: { readableErrorCode: code }, underlyingErrorMessage: '', userCancelled };
}

const Purchases = {
  configure: jest.fn(),
  setLogLevel: jest.fn(() => Promise.resolve()),
  isConfigured: jest.fn(() => Promise.resolve(true)),
  logIn: jest.fn(() => Promise.resolve({ customerInfo: makeCustomerInfo(), created: false })),
  logOut: jest.fn(() => Promise.resolve(makeCustomerInfo())),
  getOfferings: jest.fn(() => Promise.resolve({ all: {}, current: null })),
  purchasePackage: jest.fn(() => Promise.resolve({ productIdentifier: 'mock', customerInfo: makeCustomerInfo(), transaction: {} })),
  restorePurchases: jest.fn(() => Promise.resolve(makeCustomerInfo())),
  getCustomerInfo: jest.fn(() => Promise.resolve(makeCustomerInfo())),
  addCustomerInfoUpdateListener: jest.fn(),
  removeCustomerInfoUpdateListener: jest.fn(() => true),
  PURCHASES_ERROR_CODE,
  LOG_LEVEL,
  PACKAGE_TYPE,
};

export default Purchases;
