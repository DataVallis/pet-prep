/**
 * Push registration (M3-02): Expo push token → `POST /api/devices`, and the reverse
 * on logout. Never asks for the permission itself — that happens only at a good
 * moment through the pre-prompt (`pushPrompt.ts`). Errors never reach the UI: push
 * is a bonus, the game works without it.
 *
 * Needs a dev / store build (native module; not in Expo Go) and the EAS projectId
 * from app config (`extra.eas.projectId`).
 *
 * Hotfix 2026-10-06 (TestFlight 1.24.4: `POST /api/devices` 58–60 ×200 plus up to
 * 1 650 ×429 per minute): on iOS every `getExpoPushTokenAsync()` →
 * `getDevicePushTokenAsync()` → `registerForRemoteNotifications()` → `didRegister`
 * emits `onDevicePushToken` again (expo-notifications `PushTokenModule.swift`
 * `didRegister`), even for an unchanged token. The push-token listener re-registered
 * on every event, so each registration triggered the next one, forever. Now:
 * - one registration at a time (concurrent callers share it);
 * - each (session token, Expo token) pair is sent once per app run;
 * - token events caused by our own token fetch (while it runs and 10 s after) and
 *   events with the device token we already know are ignored ({@link handlePushTokenEvent});
 * - `POST /api/devices` has its own limiter (2, then 1 per minute); a failure retries in
 *   the background with `Retry-After` / backoff, at most {@link MAX_REGISTER_ATTEMPTS}.
 */

import Constants from 'expo-constants';
import * as Notifications from 'expo-notifications';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

import { api, getAuthToken } from '@/api/client';
import { getBuildInfo } from '@/config/buildInfo';
import { createFetchGate, errorStatus, retryAfterMs, type FetchGate } from '@/modules/childPet/refetchGovernor';
import { ALARM_VIBRATION, PUSH_CHANNELS, PUSH_STORAGE_KEYS, PUSH_STRINGS } from '@/modules/push/pushConfig';

export type PushRegistrationResult =
  | { status: 'registered'; token: string }
  | { status: 'no_permission' }
  | { status: 'unsupported' }
  | { status: 'failed'; reason: 'no_project_id' | 'token' | 'server' | 'throttled' };

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

/** Token events this long after our own token fetch are its echo, not a rotation. */
export const SELF_TOKEN_EVENT_GRACE_MS = 10_000;
/** Failed registrations in a row before giving up until the next app start / login. */
export const MAX_REGISTER_ATTEMPTS = 3;
/** `POST /api/devices` limiter: 2 at once, then one per minute. */
export const DEVICES_BURST = 2;
export const DEVICES_REFILL_MS = 60_000;
export const devicesGate: FetchGate = createFetchGate(DEVICES_BURST, DEVICES_REFILL_MS);

interface RegistrationState {
  inFlight: Promise<PushRegistrationResult> | null;
  /** `${session token}|${expo token}` the server accepted in this app run. */
  registeredKey: string | null;
  /** Device ms until which a token event is the echo of our own fetch. */
  selfFetchUntil: number;
  /** Device push token of the last token event. */
  lastDeviceToken: string | null;
  failedAttempts: number;
  retryTimer: ReturnType<typeof setTimeout> | null;
}

const state: RegistrationState = {
  inFlight: null,
  registeredKey: null,
  selfFetchUntil: 0,
  lastDeviceToken: null,
  failedAttempts: 0,
  retryTimer: null,
};

/** Forget this app run's registration (logout, tests). */
export function resetPushRegistration(): void {
  if (state.retryTimer !== null) clearTimeout(state.retryTimer);
  state.inFlight = null;
  state.registeredKey = null;
  state.selfFetchUntil = 0;
  state.lastDeviceToken = null;
  state.failedAttempts = 0;
  state.retryTimer = null;
  devicesGate.reset();
}

/**
 * `Notifications.addPushTokenListener` handler. Re-registers only for a device token
 * that really changed — never for the echo of our own `getExpoPushTokenAsync()`.
 * Returns whether a registration was started.
 */
export function handlePushTokenEvent(deviceToken: string, nowMs: number = Date.now()): boolean {
  const previous = state.lastDeviceToken;
  state.lastDeviceToken = deviceToken;
  if (state.inFlight !== null || nowMs < state.selfFetchUntil) return false;
  if (previous === deviceToken) return false;
  void registerForPush();
  return true;
}

function scheduleRetry(delayMs: number): void {
  if (state.retryTimer !== null || state.failedAttempts >= MAX_REGISTER_ATTEMPTS) return;
  state.retryTimer = setTimeout(() => {
    state.retryTimer = null;
    void registerForPush();
  }, delayMs);
}

/**
 * Register this install for the signed-in account if the user already allowed
 * notifications. Safe to call on every login / app start and from every prompt: at
 * most one request per (session, token) per app run.
 */
export function registerForPush(): Promise<PushRegistrationResult> {
  if (state.inFlight !== null) return state.inFlight;
  const run = registerOnce().finally(() => {
    if (state.inFlight === run) state.inFlight = null;
  });
  state.inFlight = run;
  return run;
}

async function registerOnce(): Promise<PushRegistrationResult> {
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
    state.selfFetchUntil = Number.POSITIVE_INFINITY;
    token = (await Notifications.getExpoPushTokenAsync({ projectId })).data;
  } catch {
    // Offline, simulator, or missing APNs / FCM credentials — next app start retries.
    return { status: 'failed', reason: 'token' };
  } finally {
    state.selfFetchUntil = Date.now() + SELF_TOKEN_EVENT_GRACE_MS;
  }

  let session: string | null;
  try {
    session = await getAuthToken();
  } catch {
    session = null;
  }
  const key = `${session ?? ''}|${token}`;
  if (state.registeredKey === key) return { status: 'registered', token };

  const wait = devicesGate.waitMs(Date.now());
  if (wait > 0) {
    scheduleRetry(wait);
    return { status: 'failed', reason: 'throttled' };
  }
  devicesGate.take(Date.now());
  try {
    await api.registerDevice({ expo_push_token: token, platform, app_version: appVersion() });
  } catch (error) {
    state.failedAttempts += 1;
    const after = retryAfterMs(error);
    if (after !== null) devicesGate.block(Date.now(), after);
    const status = errorStatus(error);
    // 429 / 5xx / network: try again later; other 4xx (validation, auth) won't change.
    if (after !== null || status === null || status >= 500) {
      scheduleRetry(after ?? Math.min(30_000 * 2 ** (state.failedAttempts - 1), 5 * 60_000));
    }
    return { status: 'failed', reason: after !== null ? 'throttled' : 'server' };
  }
  state.registeredKey = key;
  state.failedAttempts = 0;

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
  // The next account registers afresh (its own session token).
  resetPushRegistration();
}
