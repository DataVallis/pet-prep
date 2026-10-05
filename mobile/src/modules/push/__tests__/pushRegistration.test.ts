/**
 * M3-02: Expo push token → POST /api/devices; DELETE on logout.
 */
import * as Notifications from 'expo-notifications';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

import { api } from '@/api/client';
import { PUSH_CHANNELS, PUSH_STORAGE_KEYS } from '@/modules/push/pushConfig';
import { easProjectId, registerForPush, unregisterFromPush } from '@/modules/push/pushRegistration';
import { logout } from '@/modules/session/logout';
import { useAppStore } from '@/store/appStore';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, registerDevice: jest.fn(), unregisterDevice: jest.fn(), logout: jest.fn() },
  };
});

jest.mock('expo-constants', () => ({
  __esModule: true,
  default: {
    expoConfig: { version: '1.10.4', extra: { eas: { projectId: 'project-uuid' }, appVersion: '1.10.4', gitSha: 'abc1234' } },
    easConfig: null,
  },
}));

const registerDevice = api.registerDevice as jest.Mock;
const unregisterDevice = api.unregisterDevice as jest.Mock;
const apiLogout = api.logout as jest.Mock;
const getItem = SecureStore.getItemAsync as jest.Mock;
const setItem = SecureStore.setItemAsync as jest.Mock;
const deleteItem = SecureStore.deleteItemAsync as jest.Mock;
const getPermissions = jest.mocked(Notifications.getPermissionsAsync);
const getToken = jest.mocked(Notifications.getExpoPushTokenAsync);
const setChannel = jest.mocked(Notifications.setNotificationChannelAsync);

const granted = { status: 'granted', granted: true, canAskAgain: true, expires: 'never' } as Notifications.NotificationPermissionsStatus;
const denied = { status: 'denied', granted: false, canAskAgain: false, expires: 'never' } as Notifications.NotificationPermissionsStatus;
const TOKEN = 'ExponentPushToken[abcdefghijklmnopqrstuv]';

function setPlatform(os: 'ios' | 'android') {
  Object.defineProperty(Platform, 'OS', { configurable: true, get: () => os });
}

describe('registerForPush', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    setPlatform('ios');
    getPermissions.mockResolvedValue(granted);
    getToken.mockResolvedValue({ type: 'expo', data: TOKEN });
    registerDevice.mockResolvedValue({ device: { id: 1, platform: 'ios', app_version: '1.10.4 (abc1234)', enabled: true, last_seen_at: null } });
  });

  it('registers the Expo token with platform and app version, and remembers it for logout', async () => {
    await expect(registerForPush()).resolves.toEqual({ status: 'registered', token: TOKEN });

    expect(getToken).toHaveBeenCalledWith({ projectId: 'project-uuid' });
    expect(registerDevice).toHaveBeenCalledWith({ expo_push_token: TOKEN, platform: 'ios', app_version: '1.10.4 (abc1234)' });
    expect(setItem).toHaveBeenCalledWith(PUSH_STORAGE_KEYS.token, TOKEN);
  });

  it('creates the reminder and alarm channels on Android before asking for a token', async () => {
    setPlatform('android');

    await registerForPush();

    expect(setChannel).toHaveBeenCalledWith(PUSH_CHANNELS.default, expect.objectContaining({ importance: Notifications.AndroidImportance.DEFAULT }));
    expect(setChannel).toHaveBeenCalledWith(
      PUSH_CHANNELS.alarm,
      expect.objectContaining({ importance: Notifications.AndroidImportance.HIGH, enableVibrate: true }),
    );
    expect(registerDevice).toHaveBeenCalledWith(expect.objectContaining({ platform: 'android' }));
  });

  it('never prompts and never registers without permission', async () => {
    getPermissions.mockResolvedValue(denied);

    await expect(registerForPush()).resolves.toEqual({ status: 'no_permission' });

    expect(Notifications.requestPermissionsAsync).not.toHaveBeenCalled();
    expect(getToken).not.toHaveBeenCalled();
    expect(registerDevice).not.toHaveBeenCalled();
  });

  it('counts iOS provisional authorization as allowed', async () => {
    getPermissions.mockResolvedValue({
      ...denied,
      status: 'undetermined',
      ios: { status: Notifications.IosAuthorizationStatus.PROVISIONAL },
    } as unknown as Notifications.NotificationPermissionsStatus);

    await expect(registerForPush()).resolves.toMatchObject({ status: 'registered' });
  });

  it('reports token and server failures without throwing', async () => {
    getToken.mockRejectedValueOnce(new Error('offline'));
    await expect(registerForPush()).resolves.toEqual({ status: 'failed', reason: 'token' });

    registerDevice.mockRejectedValueOnce(new Error('500'));
    await expect(registerForPush()).resolves.toEqual({ status: 'failed', reason: 'server' });
    expect(setItem).not.toHaveBeenCalled();
  });

  it('reads the EAS project id from app config', () => {
    expect(easProjectId()).toBe('project-uuid');
  });
});

describe('unregisterFromPush / logout', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({ token: 'tok', user: { id: 1, name: 'Starš', email: 'p@x.si', role: 'parent' }, pet: null });
  });

  it('deletes the registration on the server and forgets the token', async () => {
    getItem.mockImplementation(async (key: string) => (key === PUSH_STORAGE_KEYS.token ? TOKEN : null));

    await unregisterFromPush();

    expect(unregisterDevice).toHaveBeenCalledWith(TOKEN, undefined);
    expect(deleteItem).toHaveBeenCalledWith(PUSH_STORAGE_KEYS.token);
  });

  it('logout unregisters the push device BEFORE revoking the session token', async () => {
    const order: string[] = [];
    getItem.mockImplementation(async (key: string) => (key === PUSH_STORAGE_KEYS.token ? TOKEN : null));
    unregisterDevice.mockImplementation(async () => {
      order.push('unregister');
      return null;
    });
    apiLogout.mockImplementation(async () => {
      order.push('revoke');
      return { message: 'ok' };
    });

    await logout();

    expect(order).toEqual(['unregister', 'revoke']);
    expect(deleteItem).toHaveBeenCalledWith(PUSH_STORAGE_KEYS.token);
    expect(useAppStore.getState().authToken).toBeNull();
  });

  it('after a 401 (token already gone) only forgets the token locally', async () => {
    getItem.mockImplementation(async (key: string) => (key === PUSH_STORAGE_KEYS.token ? TOKEN : null));

    await logout({ revoke: false });

    expect(unregisterDevice).not.toHaveBeenCalled();
    expect(deleteItem).toHaveBeenCalledWith(PUSH_STORAGE_KEYS.token);
  });

  it('logs out even when the unregister call fails', async () => {
    getItem.mockImplementation(async (key: string) => (key === PUSH_STORAGE_KEYS.token ? TOKEN : null));
    unregisterDevice.mockRejectedValueOnce(new Error('offline'));
    apiLogout.mockResolvedValueOnce({ message: 'ok' });

    await logout();

    expect(apiLogout).toHaveBeenCalled();
    expect(useAppStore.getState().user).toBeNull();
  });
});
