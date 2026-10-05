/**
 * Manual mock of expo-notifications (M3-02) — the native module doesn't exist in Jest.
 * Default: permission undetermined (askable), token resolves, listeners return a
 * removable subscription. Tests override per case with `jest.mocked(...)`.
 */

export enum AndroidImportance {
  UNKNOWN = 0,
  UNSPECIFIED = 1,
  NONE = 2,
  MIN = 3,
  LOW = 4,
  DEFAULT = 5,
  HIGH = 6,
  MAX = 7,
}

export enum AndroidNotificationVisibility {
  UNKNOWN = 0,
  PUBLIC = 1,
  PRIVATE = 2,
  SECRET = 3,
}

export enum IosAuthorizationStatus {
  NOT_DETERMINED = 0,
  DENIED = 1,
  AUTHORIZED = 2,
  PROVISIONAL = 3,
  EPHEMERAL = 4,
}

const undetermined = { status: 'undetermined', granted: false, canAskAgain: true, expires: 'never' };

export const getPermissionsAsync = jest.fn(() => Promise.resolve({ ...undetermined }));
export const requestPermissionsAsync = jest.fn(() =>
  Promise.resolve({ status: 'granted', granted: true, canAskAgain: true, expires: 'never' }),
);
export const getExpoPushTokenAsync = jest.fn(() =>
  Promise.resolve({ type: 'expo', data: 'ExponentPushToken[test-token-0000000000]' }),
);
export const setNotificationChannelAsync = jest.fn(() => Promise.resolve(null));
export const setNotificationHandler = jest.fn();
export const addPushTokenListener = jest.fn(() => ({ remove: jest.fn() }));
export const addNotificationResponseReceivedListener = jest.fn(() => ({ remove: jest.fn() }));
export const addNotificationReceivedListener = jest.fn(() => ({ remove: jest.fn() }));
export const getLastNotificationResponseAsync = jest.fn(() => Promise.resolve(null));
export const clearLastNotificationResponseAsync = jest.fn(() => Promise.resolve());
