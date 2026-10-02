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

// Mock expo-video
jest.mock('expo-video', () => ({
  useVideoPlayer: jest.fn(() => ({
    play: jest.fn(),
    pause: jest.fn(),
    replay: jest.fn(),
    replace: jest.fn(),
    setCurrentTime: jest.fn(),
    enterFullscreen: jest.fn(),
    exitFullscreen: jest.fn(),
  })),
  VideoView: 'VideoView',
  VideoContent: 'VideoContent',
}));

// Mock expo-sensors (Pedometer)
jest.mock('expo-sensors', () => ({
  Pedometer: {
    isAvailableAsync: jest.fn(() => Promise.resolve(true)),
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

// Mock nativewind to avoid CSS interop runtime issues in tests
jest.mock('nativewind', () => ({
  styled: (Component) => Component,
  useColorScheme: () => ({ colorScheme: 'light', setColorScheme: jest.fn(), toggleColorScheme: jest.fn() }),
}));

// Mock react-native-css-interop to prevent runtime className processing
jest.mock('react-native-css-interop', () => ({
  styled: (Component) => Component,
  useColorScheme: () => ({ colorScheme: 'light', setColorScheme: jest.fn(), toggleColorScheme: jest.fn() }),
  colorScheme: 'light',
}));

// Silence console warnings in tests
const originalWarn = console.warn;
console.warn = (...args) => {
  if (typeof args[0] === 'string' && args[0].includes('NativeWind')) return;
  originalWarn.call(console, ...args);
};
