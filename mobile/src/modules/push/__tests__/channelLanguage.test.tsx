/**
 * M1-18: Android shows our notification channel names in its settings — after a language
 * switch `usePushNotifications` re-saves them in the new language, once, and only when
 * notifications are already allowed (never a prompt).
 */
import * as Notifications from 'expo-notifications';
import { act, renderHook } from '@testing-library/react-native';
import { Platform } from 'react-native';

import { i18n } from '@/i18n';
import { usePushNotifications } from '@/modules/push/usePushNotifications';

const getPermissions = jest.mocked(Notifications.getPermissionsAsync);
const setChannel = jest.mocked(Notifications.setNotificationChannelAsync);
const granted = { status: 'granted', granted: true, canAskAgain: true, expires: 'never' } as Notifications.NotificationPermissionsStatus;
const denied = { status: 'denied', granted: false, canAskAgain: true, expires: 'never' } as Notifications.NotificationPermissionsStatus;

function setPlatform(os: 'ios' | 'android') {
  Object.defineProperty(Platform, 'OS', { configurable: true, get: () => os });
}

async function switchTo(language: 'en' | 'sl') {
  await act(async () => {
    await i18n.changeLanguage(language);
  });
}

describe('usePushNotifications — channel names follow the language', () => {
  const originalOS = Platform.OS;

  beforeEach(() => {
    jest.clearAllMocks();
    setPlatform('android');
  });

  afterEach(async () => {
    await switchTo('sl');
    setPlatform(originalOS as 'ios' | 'android');
  });

  it('renames both channels after a switch when notifications are allowed (signed out → neutral name, M5-R06-08d)', async () => {
    getPermissions.mockResolvedValue(granted);
    renderHook(() => usePushNotifications());
    expect(setChannel).not.toHaveBeenCalled();

    await switchTo('en');

    expect(setChannel).toHaveBeenCalledTimes(2);
    expect(setChannel).toHaveBeenCalledWith('default', expect.objectContaining({ name: 'Pet reminders' }));
    expect(setChannel).toHaveBeenCalledWith('alarm', expect.objectContaining({ name: 'Urgent alerts' }));
  });

  it('does nothing when notifications are not allowed', async () => {
    getPermissions.mockResolvedValue(denied);
    renderHook(() => usePushNotifications());

    await switchTo('en');

    expect(getPermissions).toHaveBeenCalledTimes(1);
    expect(setChannel).not.toHaveBeenCalled();
  });
});
