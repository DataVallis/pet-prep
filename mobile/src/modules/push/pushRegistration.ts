/**
 * Push registration (M3-02): Expo push token → `POST /api/devices`, and the reverse
 * on logout. Never asks for the permission itself — that happens only at a good
 * moment through the pre-prompt (`pushPrompt.ts`). Errors never reach the UI: push
 * is a bonus, the game works without it.
 *
 * Needs a dev / store build (native module; not in Expo Go) and the EAS projectId
 * from app config (`extra.eas.projectId`).
 */

import Constants from 'expo-constants';
import * as Notifications from 'expo-notifications';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

import { api } from '@/api/client';
import { getBuildInfo } from '@/config/buildInfo';
import { ALARM_VIBRATION, PUSH_CHANNELS, PUSH_STORAGE_KEYS, PUSH_STRINGS } from '@/modules/push/pushConfig';

export type PushRegistrationResult =
  | { status: 'registered'; token: string }
  | { status: 'no_permission' }
  | { status: 'unsupported' }
  | { status: 'failed'; reason: 'no_project_id' | 'token' | 'server' };

type DevicePlatform = 'ios' | 'android';

function devicePlatform(): DevicePlatform | null {
  return Platform.OS === 'ios' || Platform.OS === 'android' ? Platform.OS : null;
}

/** EAS project id (app.json `extra.eas.projectId`, or the EAS build's own config). */
export function easProjectId(): string | null {
  const extra = Constants.expoConfig?.extra as { eas?: { projectId?: unknown } } | undefined;
  const fromExtra = extra?.eas?.projectId;
  if (typeof fromExtra === 'string' && fromExtra.length > 0) return fromExtra;
  const fromEas = Constants.easConfig?.projectId;
  return typeof fromEas === 'string' && fromEas.length > 0 ? fromEas : null;
}

/** Granted, or iOS provisional (quiet delivery) — both can receive pushes. */
export function isPushAllowed(permissions: Notifications.NotificationPermissionsStatus): boolean {
  return (
    permissions.granted ||
    permissions.ios?.status === Notifications.IosAuthorizationStatus.PROVISIONAL ||
    permissions.ios?.status === Notifications.IosAuthorizationStatus.EPHEMERAL
  );
}

/**
 * Android channels. Created right before the permission request / token (Android 13+
 * needs a channel for the system prompt); harmless to repeat.
 */
export async function ensureAndroidChannels(): Promise<void> {
  if (Platform.OS !== 'android') return;
  await Notifications.setNotificationChannelAsync(PUSH_CHANNELS.default, {
    name: PUSH_STRINGS.channels.default,
    importance: Notifications.AndroidImportance.DEFAULT,
  });
  await Notifications.setNotificationChannelAsync(PUSH_CHANNELS.alarm, {
    name: PUSH_STRINGS.channels.alarm,
    importance: Notifications.AndroidImportance.HIGH,
    vibrationPattern: ALARM_VIBRATION,
    enableVibrate: true,
    sound: 'default',
    lockscreenVisibility: Notifications.AndroidNotificationVisibility.PUBLIC,
  });
}

/** "1.10.4 (abc1234)" — matches the server's app_version rule. */
function appVersion(): string {
  const info = getBuildInfo();
  return `${info.version} (${info.sha})`.slice(0, 32);
}

/**
 * Register this install for the signed-in account if the user already allowed
 * notifications. Safe to call on every login / app start (server upsert).
 */
export async function registerForPush(): Promise<PushRegistrationResult> {
  const platform = devicePlatform();
  if (platform === null) return { status: 'unsupported' };

  try {
    const permissions = await Notifications.getPermissionsAsync();
    if (!isPushAllowed(permissions)) return { status: 'no_permission' };
  } catch {
    return { status: 'unsupported' };
  }

  const projectId = easProjectId();
  if (projectId === null) return { status: 'failed', reason: 'no_project_id' };

  let token: string;
  try {
    await ensureAndroidChannels();
    token = (await Notifications.getExpoPushTokenAsync({ projectId })).data;
  } catch {
    // Offline, simulator, or missing APNs / FCM credentials — next app start retries.
    return { status: 'failed', reason: 'token' };
  }

  try {
    await api.registerDevice({ expo_push_token: token, platform, app_version: appVersion() });
  } catch {
    return { status: 'failed', reason: 'server' };
  }

  try {
    await SecureStore.setItemAsync(PUSH_STORAGE_KEYS.token, token);
  } catch {
    // Without the stored token logout can't unregister explicitly — the server
    // still drops the registration with the revoked session token.
  }
  return { status: 'registered', token };
}

/**
 * Logout: remove this install's registration (best effort, before the session token
 * is revoked — the server would drop it with the token anyway).
 */
export async function unregisterFromPush({
  signal,
  callServer = true,
  timeoutMs,
}: { signal?: AbortSignal; callServer?: boolean; timeoutMs?: number } = {}): Promise<void> {
  let token: string | null = null;
  try {
    token = await SecureStore.getItemAsync(PUSH_STORAGE_KEYS.token);
  } catch {
    token = null;
  }
  if (token && callServer) {
    // Own timeout (logout: 2 s) on top of an optional caller signal.
    const controller = new AbortController();
    const onAbort = () => controller.abort();
    signal?.addEventListener('abort', onAbort);
    const timer = timeoutMs !== undefined ? setTimeout(() => controller.abort(), timeoutMs) : null;
    try {
      await api.unregisterDevice(token, controller.signal);
    } catch {
      // Offline / aborted / already gone — fine (the revoke drops it server side).
    } finally {
      if (timer !== null) clearTimeout(timer);
      signal?.removeEventListener('abort', onAbort);
    }
  }
  try {
    await SecureStore.deleteItemAsync(PUSH_STORAGE_KEYS.token);
  } catch {
    // Nothing more to do.
  }
}
