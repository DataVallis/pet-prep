/**
 * M3-02: our Slovenian pre-prompt before the one-time system permission prompt.
 */
import * as Notifications from 'expo-notifications';
import * as SecureStore from 'expo-secure-store';
import { Alert, Linking, type AlertButton } from 'react-native';

import { api } from '@/api/client';
import { PRE_PROMPT_RETRY_MS, PUSH_STORAGE_KEYS, PUSH_STRINGS } from '@/modules/push/pushConfig';
import { enablePushNotifications, getPushPermissionStatus, maybeAskForPush } from '@/modules/push/pushPrompt';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, registerDevice: jest.fn(() => Promise.resolve({ device: {} })) } };
});

jest.mock('expo-constants', () => ({
  __esModule: true,
  default: { expoConfig: { version: '1.10.4', extra: { eas: { projectId: 'project-uuid' } } }, easConfig: null },
}));

const getPermissions = jest.mocked(Notifications.getPermissionsAsync);
const requestPermissions = jest.mocked(Notifications.requestPermissionsAsync);
const getItem = SecureStore.getItemAsync as jest.Mock;
const setItem = SecureStore.setItemAsync as jest.Mock;
const registerDevice = api.registerDevice as jest.Mock;

type Status = Notifications.NotificationPermissionsStatus;
const undetermined = { status: 'undetermined', granted: false, canAskAgain: true, expires: 'never' } as Status;
const granted = { status: 'granted', granted: true, canAskAgain: true, expires: 'never' } as Status;
const deniedForGood = { status: 'denied', granted: false, canAskAgain: false, expires: 'never' } as Status;
const deniedNow = { status: 'denied', granted: false, canAskAgain: false, expires: 'never' } as Status;

/** Answer our Alert with the button at `index` (0 = "Ne zdaj", 1 = "Dovoli obvestila"). */
function answerAlert(index: 0 | 1) {
  return jest.spyOn(Alert, 'alert').mockImplementation((_title, _message, buttons?: AlertButton[]) => {
    buttons?.[index]?.onPress?.();
  });
}

const NOW = Date.parse('2026-10-14T10:00:00Z');

describe('maybeAskForPush', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    jest.restoreAllMocks();
    getItem.mockResolvedValue(null);
    getPermissions.mockResolvedValue(undetermined);
  });

  it('child: shows the Slovenian pre-prompt, then the system prompt, then registers', async () => {
    const alert = answerAlert(1);
    requestPermissions.mockResolvedValueOnce(granted);
    getPermissions.mockResolvedValueOnce(undetermined).mockResolvedValue(granted);

    await expect(maybeAskForPush('child', NOW)).resolves.toBe('registered');

    expect(alert).toHaveBeenCalledWith(
      PUSH_STRINGS.prePrompt.child.title,
      PUSH_STRINGS.prePrompt.child.message,
      expect.any(Array),
      expect.objectContaining({ cancelable: true }),
    );
    expect(requestPermissions).toHaveBeenCalledWith({ ios: { allowAlert: true, allowSound: true, allowBadge: false } });
    expect(registerDevice).toHaveBeenCalledWith(expect.objectContaining({ platform: 'ios' }));
  });

  it('parent texts mention quiet hours and that no names are sent', async () => {
    const alert = answerAlert(0);

    await maybeAskForPush('parent', NOW);

    const [title, message] = alert.mock.calls[0] ?? [];
    expect(title).toBe(PUSH_STRINGS.prePrompt.parent.title);
    expect(message).toContain('tihimi urami');
    expect(message).toContain('ne vsebujejo imen');
  });

  it('"Ne zdaj" never opens the system prompt and is remembered for 3 days', async () => {
    answerAlert(0);

    await expect(maybeAskForPush('child', NOW)).resolves.toBe('declined');
    expect(requestPermissions).not.toHaveBeenCalled();
    expect(setItem).toHaveBeenCalledWith(PUSH_STORAGE_KEYS.declinedAt, String(NOW));

    // Two days later: no question at all.
    const alert = answerAlert(1);
    alert.mockClear();
    getItem.mockImplementation(async (key: string) => (key === PUSH_STORAGE_KEYS.declinedAt ? String(NOW) : null));
    await expect(maybeAskForPush('child', NOW + PRE_PROMPT_RETRY_MS - 1)).resolves.toBe('skipped');
    expect(alert).not.toHaveBeenCalled();

    // After 3 days: ask again.
    requestPermissions.mockResolvedValueOnce(granted);
    await maybeAskForPush('child', NOW + PRE_PROMPT_RETRY_MS);
    expect(alert).toHaveBeenCalledTimes(1);
  });

  it('respects a permanent "no" from the system — no pre-prompt, no request', async () => {
    getPermissions.mockResolvedValue(deniedForGood);
    const alert = answerAlert(1);

    await expect(maybeAskForPush('parent', NOW)).resolves.toBe('skipped');

    expect(alert).not.toHaveBeenCalled();
    expect(requestPermissions).not.toHaveBeenCalled();
    expect(registerDevice).not.toHaveBeenCalled();
  });

  it('a system "Don\'t allow" after our pre-prompt registers nothing', async () => {
    answerAlert(1);
    requestPermissions.mockResolvedValueOnce(deniedNow);

    await expect(maybeAskForPush('child', NOW)).resolves.toBe('denied');

    expect(registerDevice).not.toHaveBeenCalled();
  });

  it('already allowed: registers silently without any prompt', async () => {
    getPermissions.mockResolvedValue(granted);
    const alert = answerAlert(1);

    await expect(maybeAskForPush('child', NOW)).resolves.toBe('registered');

    expect(alert).not.toHaveBeenCalled();
    expect(requestPermissions).not.toHaveBeenCalled();
  });

  it('first-view mode never registers or prompts an already allowed install', async () => {
    getPermissions.mockResolvedValue(granted);
    const alert = answerAlert(1);

    await expect(maybeAskForPush('parent', NOW, { onlyIfUndetermined: true })).resolves.toBe('skipped');

    expect(alert).not.toHaveBeenCalled();
    expect(registerDevice).not.toHaveBeenCalled();
  });

  it('asks only once when two moments overlap (contract → first HUD view)', async () => {
    let answer: ((allow: boolean) => void) | undefined;
    const alert = jest.spyOn(Alert, 'alert').mockImplementation((_t, _m, buttons?: AlertButton[]) => {
      answer = (allow) => buttons?.[allow ? 1 : 0]?.onPress?.();
    });

    const first = maybeAskForPush('child', NOW);
    const second = maybeAskForPush('child', NOW, { onlyIfUndetermined: true });
    await Promise.resolve();
    await new Promise((r) => setTimeout(r, 0));
    answer?.(false);

    await expect(first).resolves.toBe('declined');
    await expect(second).resolves.toBe('declined');
    expect(alert).toHaveBeenCalledTimes(1);
  });
});

describe('Nadzor "Obvestila" helpers', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    jest.restoreAllMocks();
  });

  it('reports on / off / blocked', async () => {
    getPermissions.mockResolvedValueOnce(granted);
    await expect(getPushPermissionStatus()).resolves.toBe('on');
    getPermissions.mockResolvedValueOnce(undetermined);
    await expect(getPushPermissionStatus()).resolves.toBe('off');
    getPermissions.mockResolvedValueOnce(deniedForGood);
    await expect(getPushPermissionStatus()).resolves.toBe('blocked');
  });

  it('"Vklopi obvestila" goes straight to the system prompt, clears "Ne zdaj" and registers', async () => {
    getPermissions.mockResolvedValueOnce(undetermined).mockResolvedValue(granted);
    requestPermissions.mockResolvedValueOnce(granted);
    const alert = jest.spyOn(Alert, 'alert');

    await expect(enablePushNotifications()).resolves.toBe('on');

    expect(alert).not.toHaveBeenCalled();
    expect(SecureStore.deleteItemAsync).toHaveBeenCalledWith(PUSH_STORAGE_KEYS.declinedAt);
    expect(registerDevice).toHaveBeenCalled();
  });

  it('opens the phone settings once the system will not ask again', async () => {
    getPermissions.mockResolvedValue(deniedForGood);
    const openSettings = jest.spyOn(Linking, 'openSettings').mockResolvedValue(undefined);

    await expect(enablePushNotifications()).resolves.toBe('blocked');

    expect(openSettings).toHaveBeenCalled();
    expect(requestPermissions).not.toHaveBeenCalled();
  });
});
