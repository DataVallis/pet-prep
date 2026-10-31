/**
 * Jest setup file for PetPrep mobile tests.
 * Mocks native modules that aren't available in the test environment.
 */

// Mock expo-secure-store
jest.mock('expo-secure-store', () => ({
  setItemAsync: jest.fn(),
  getItemAsync: jest.fn(() => Promise.resolve(null)),
  deleteItemAsync: jest.fn(),
  AFTER_FIRST_UNLOCK: 0,
  WHEN_UNLOCKED: 5,
}));

// expo-localization (M1-18): the test device prefers Slovenian, so existing tests keep
// asserting the Slovenian copy; English is covered by explicit `i18n.changeLanguage('en')`.
jest.mock('expo-localization', () => ({
  getLocales: () => [{ languageTag: 'sl-SI', languageCode: 'sl' }],
}));

// expo-video: manual mock in __mocks__/expo-video.tsx (one fake player per hook, with
// events; registry in src/test-utils/videoPlayers.ts).
jest.mock('expo-video');

// expo-notifications (M3-02): manual mock in __mocks__/expo-notifications.ts.
jest.mock('expo-notifications');

// react-native-purchases (M3-07): manual mock in __mocks__/react-native-purchases.ts.
jest.mock('react-native-purchases');

// expo-clipboard (child PIN paste): manual mock in __mocks__/expo-clipboard.ts. The paste
// button only shows after `setClipboardProbeForTests(() => true)` (pinClipboard.ts).
jest.mock('expo-clipboard');

// Health step stores (M3-04 / M3-05): manual mock in
// src/modules/steps/health/__mocks__/healthAdapter.ts — no health store unless a test
// injects one (`deps.health`), so the native libraries are never loaded in Jest.
jest.mock('@/modules/steps/health/healthAdapter');

// Background step sync (M3-06): native task scheduler.
jest.mock('expo-task-manager', () => ({
  defineTask: jest.fn(),
  isTaskDefined: jest.fn(() => false),
  isTaskRegisteredAsync: jest.fn(() => Promise.resolve(false)),
}));
jest.mock('expo-background-task', () => ({
  BackgroundTaskStatus: { Restricted: 1, Available: 2 },
  BackgroundTaskResult: { Success: 1, Failed: 2 },
  getStatusAsync: jest.fn(() => Promise.resolve(2)),
  registerTaskAsync: jest.fn(() => Promise.resolve()),
  unregisterTaskAsync: jest.fn(() => Promise.resolve()),
}));

// Mock expo-sensors (Pedometer)
jest.mock('expo-sensors', () => ({
  Pedometer: {
    isAvailableAsync: jest.fn(() => Promise.resolve(true)),
    getPermissionsAsync: jest.fn(() =>
      Promise.resolve({ status: 'granted', granted: true, canAskAgain: true, expires: 'never' }),
    ),
    requestPermissionsAsync: jest.fn(() =>
      Promise.resolve({ status: 'granted', granted: true, canAskAgain: true, expires: 'never' }),
    ),
    getStepCountAsync: jest.fn(() => Promise.resolve({ steps: 0 })),
    watchStepCount: jest.fn(() => ({
      remove: jest.fn(),
    })),
  },
}));

// Mock laravel-echo and pusher-js
jest.mock('laravel-echo', () => jest.fn());
jest.mock('pusher-js', () => jest.fn());

// No test may reach the real API (the app defaults to production). An endpoint a test does not
// mock fails like an offline phone; tests that exercise the client assign their own fetch.
// M5-R09c: the HUD of a pedigree pet now also asks for its breed facts.
// A plain function (not jest.fn) so `jest.restoreAllMocks()` never brings the real fetch back.
globalThis.fetch = ((input: unknown) =>
  Promise.reject(
    new TypeError(`Network request failed (no fetch in tests): ${typeof input === 'string' ? input : String((input as { url?: unknown })?.url ?? input)}`),
  )) as typeof fetch;

// GAMES_SPEC phase 1 game layer (2026-10-30): manual mocks in __mocks__/ for the native
// libraries — reanimated's own mock needs native worklets under jest-expo; the gesture-handler
// mock records worklet callbacks (`fireGameGesture`). The layer itself is off in Jest unless a
// test calls `setGameLayerAvailableForTests(true)` (jest.afterEnv.ts).
jest.mock('react-native-reanimated');
jest.mock('react-native-worklets');
jest.mock('react-native-gesture-handler');
jest.mock('@shopify/react-native-skia');
jest.mock('expo-audio');
jest.mock('expo-haptics');
