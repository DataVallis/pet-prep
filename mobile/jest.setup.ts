/**
 * Jest setup file for PetPrep mobile tests.
 * Mocks native modules that aren't available in the test environment.
 */

// Mock expo-secure-store
jest.mock('expo-secure-store', () => ({
  setItemAsync: jest.fn(),
  getItemAsync: jest.fn(() => Promise.resolve(null)),
  deleteItemAsync: jest.fn(),
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
