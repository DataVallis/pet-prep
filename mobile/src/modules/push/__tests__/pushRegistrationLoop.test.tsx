/**
 * Hotfix 2026-10-06 — the real loop behind the TestFlight 1.24.4 incident: Caddy counted
 * `POST /api/devices` 58–60 ×200 plus 193–1 650 ×429 per minute for one child (08:36–08:42
 * UTC), which drained the shared `throttle:api` bucket (→ 429 on `GET /api/child/pet`).
 *
 * On iOS, `getExpoPushTokenAsync()` → `registerForRemoteNotifications()` → `didRegister`
 * emits `onDevicePushToken` AGAIN (expo-notifications `PushTokenModule.swift`), also for an
 * unchanged token. The mock below does the same; the old listener
 * (`addPushTokenListener(() => registerForPush())`) re-registered on every echo.
 */
import * as Notifications from 'expo-notifications';
import { act, renderHook } from '@testing-library/react-native';
import { Platform } from 'react-native';

import { ApiError, api } from '@/api/client';
import {
  MAX_REGISTER_ATTEMPTS,
  handlePushTokenEvent,
  registerForPush,
  resetPushRegistration,
} from '@/modules/push/pushRegistration';
import { usePushNotifications } from '@/modules/push/usePushNotifications';
import { useAppStore } from '@/store/appStore';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, registerDevice: jest.fn() } };
});
jest.mock('expo-constants', () => ({
  __esModule: true,
  default: { expoConfig: { version: '1.24.4', extra: { eas: { projectId: 'project-uuid' } } }, easConfig: null },
}));

const registerDevice = api.registerDevice as jest.Mock;
const getToken = jest.mocked(Notifications.getExpoPushTokenAsync);
const granted = { status: 'granted', granted: true, canAskAgain: true, expires: 'never' } as Notifications.NotificationPermissionsStatus;

let deviceToken = 'apns-device-token-1';
const listeners = new Set<(token: Notifications.DevicePushToken) => void>();

/** iOS: every token fetch makes the OS call didRegister → the token event fires again. */
function emulateIosTokenEcho() {
  jest.mocked(Notifications.addPushTokenListener).mockImplementation((listener) => {
    listeners.add(listener);
    return { remove: () => listeners.delete(listener) } as ReturnType<typeof Notifications.addPushTokenListener>;
  });
  getToken.mockImplementation(async () => {
    const data = `ExponentPushToken[${deviceToken}]`;
    // didRegister: resolve the promise, then sendEvent(onDevicePushToken).
    setTimeout(() => listeners.forEach((l) => l({ type: 'ios', data: deviceToken })), 0);
    return { type: 'expo', data };
  });
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

describe('push registration loop (hotfix 2026-10-06)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    Object.defineProperty(Platform, 'OS', { configurable: true, get: () => 'ios' });
    resetPushRegistration();
    listeners.clear();
    deviceToken = 'apns-device-token-1';
    jest.mocked(Notifications.getPermissionsAsync).mockResolvedValue(granted);
    emulateIosTokenEcho();
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('sign-in registers ONCE; the token echo of our own fetch does not re-register (was: endless loop)', async () => {
    registerDevice.mockResolvedValue({ device: { id: 1 } });
    renderHook(() => usePushNotifications());
    act(() => {
      useAppStore.getState().signIn({ token: 'tok', user: { id: 2, name: 'M', email: null, role: 'child' }, pet: null });
    });
    await advance(60_000);
    expect(registerDevice).toHaveBeenCalledTimes(1);
    expect(getToken).toHaveBeenCalledTimes(1);
  });

  it('server answers 429 (shared bucket full): at most a few attempts, spaced by Retry-After', async () => {
    registerDevice.mockRejectedValue(new ApiError('Too Many Attempts.', 429, undefined, 20));
    renderHook(() => usePushNotifications());
    act(() => {
      useAppStore.getState().signIn({ token: 'tok', user: { id: 2, name: 'M', email: null, role: 'child' }, pet: null });
    });
    await advance(10 * 60_000);
    expect(registerDevice.mock.calls.length).toBeLessThanOrEqual(MAX_REGISTER_ATTEMPTS);
    expect(registerDevice.mock.calls.length).toBeGreaterThanOrEqual(2);
  });

  it('every prompt / restore path calling registerForPush shares one request per (session, token)', async () => {
    registerDevice.mockResolvedValue({ device: { id: 1 } });
    const results = await Promise.all([registerForPush(), registerForPush(), registerForPush()]);
    await registerForPush();
    expect(results.every((r) => r.status === 'registered')).toBe(true);
    expect(registerDevice).toHaveBeenCalledTimes(1);
  });

  it('a real token rotation (outside our own fetch) registers the new token once', async () => {
    registerDevice.mockResolvedValue({ device: { id: 1 } });
    await registerForPush();
    await advance(1_000); // echo of our fetch → ignored
    expect(registerDevice).toHaveBeenCalledTimes(1);

    await advance(60_000); // grace over, limiter refilled
    deviceToken = 'apns-device-token-2';
    expect(handlePushTokenEvent('apns-device-token-2')).toBe(true);
    await advance(1_000);
    expect(registerDevice).toHaveBeenCalledTimes(2);
    expect(registerDevice.mock.calls[1][0]).toMatchObject({ expo_push_token: 'ExponentPushToken[apns-device-token-2]' });

    // The same token again (APNs re-delivers it): nothing.
    await advance(60_000);
    expect(handlePushTokenEvent('apns-device-token-2')).toBe(false);
    expect(registerDevice).toHaveBeenCalledTimes(2);
  });
});
