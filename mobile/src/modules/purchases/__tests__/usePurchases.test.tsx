import { act, renderHook } from '@testing-library/react-native';
import Purchases from 'react-native-purchases';

import { useAppStore } from '@/store/appStore';
import { resetPurchasesForTests } from '../purchases';
import { usePurchasesSession } from '../usePurchases';

// Keys present (the hook path reads ENV); a real build inlines them from EXPO_PUBLIC_*.
jest.mock('@/config/env', () => ({
  ENV: {
    API_BASE_URL: 'http://localhost',
    REVERB_APP_KEY: 'k',
    REVERB_HOST: 'localhost',
    REVERB_PORT: 8080,
    REVERB_SCHEME: 'http',
    REVENUECAT_IOS_KEY: 'appl_test',
    REVENUECAT_ANDROID_KEY: 'goog_test',
  },
}));

// Not Expo Go: the native module would exist in a dev build.
jest.mock('expo-constants', () => {
  const actual = jest.requireActual<typeof import('expo-constants')>('expo-constants');
  return { __esModule: true, ...actual, default: { ...actual.default, executionEnvironment: 'bare' } };
});

const sdk = Purchases as unknown as Record<string, jest.Mock>;

function signIn(role: 'parent' | 'child', id: number) {
  useAppStore.getState().signIn({ token: `t${id}`, user: { id, name: 'X', email: null, role }, pet: null });
}

beforeEach(() => {
  jest.clearAllMocks();
  resetPurchasesForTests();
  useAppStore.setState(useAppStore.getInitialState(), true);
});

describe('usePurchasesSession', () => {
  it('identifies a parent once per id, re-renders do nothing more', async () => {
    signIn('parent', 7);
    const { rerender } = renderHook(() => usePurchasesSession());
    for (let i = 0; i < 20; i += 1) rerender({});
    await act(async () => undefined);
    expect(sdk.configure).toHaveBeenCalledTimes(1);
    expect(sdk.configure).toHaveBeenCalledWith({ apiKey: 'appl_test', appUserID: '7' });
    expect(sdk.logIn).not.toHaveBeenCalled();
  });

  it('a failed logIn is not retried by the effect (no loop)', async () => {
    signIn('parent', 7);
    const { rerender } = renderHook(() => usePurchasesSession());
    await act(async () => undefined);
    // Next parent on the same install: logIn fails (offline).
    act(() => useAppStore.getState().reset());
    sdk.logIn.mockRejectedValue({ code: '10' });
    act(() => signIn('parent', 8));
    for (let i = 0; i < 20; i += 1) rerender({});
    await act(async () => undefined);
    expect(sdk.logIn).toHaveBeenCalledTimes(1);
    sdk.logIn.mockReset();
  });

  it('does nothing in a child session', async () => {
    signIn('child', 9);
    renderHook(() => usePurchasesSession());
    await act(async () => undefined);
    expect(sdk.configure).not.toHaveBeenCalled();
    expect(sdk.logIn).not.toHaveBeenCalled();
  });

  it('does nothing while the session is still restoring', async () => {
    signIn('parent', 7);
    act(() => useAppStore.getState().setBootStatus('restoring'));
    renderHook(() => usePurchasesSession());
    await act(async () => undefined);
    expect(sdk.configure).not.toHaveBeenCalled();
  });
});
