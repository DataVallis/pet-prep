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
 * - a REAL rotation (a different device token) that arrives while a run is in flight is
 *   remembered and registered once after that run settles (follow-up 2026-10-06);
 * - logout ({@link resetPushRegistration}) bumps a generation: a run that was still in
 *   flight can no longer touch state, store its token or schedule a retry;
 * - `POST /api/devices` has its own limiter (2, then 1 per minute); a failure retries in
 *   the background with `Retry-After` / backoff, at most {@link MAX_REGISTER_ATTEMPTS}.
 */

import Constants from 'expo-constants';
import * as Notifications from 'expo-notifications';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

import { api, getAuthToken } from '@/api/client';
import { getBuildInfo } from '@/config/buildInfo';
import { currentLanguage } from '@/i18n';
import { createFetchGate, errorStatus, retryAfterMs, type FetchGate } from '@/modules/childPet/refetchGovernor';
import { ALARM_VIBRATION, PUSH_CHANNELS, PUSH_STORAGE_KEYS, PUSH_STRINGS } from '@/modules/push/pushConfig';
import { useAppStore } from '@/store/appStore';

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
 * Name of the reminder channel (M5-R06-08d, David 2026-10-09): a child's phone has one pet,
 * so it names that pet's species ("Dog reminders", or "Cat reminders" through the cat
 * override while the child's text species is a cat); a parent's phone gets every pet of
 * the family on the same channel, so it gets the neutral "Pet reminders". With no user
 * (signed out) the neutral name is used too, but only when the channels are next saved
 * (a language switch, the next sign-in) — logout itself does not rename the channel.
 * Same channel id either way: re-saving it only renames it.
 */
export function reminderChannelName(): string {
  return useAppStore.getState().user?.role === 'child' ? PUSH_STRINGS.channels.default : PUSH_STRINGS.channels.shared;
}

/**
 * Android channels. Created right before the permission request / token (Android 13+
 * needs a channel for the system prompt); harmless to repeat. Re-creating a channel with
 * the same id updates its name in the system settings (importance can't be raised), so a
 * new wording or language reaches existing installs without a new id.
 */
export async function ensureAndroidChannels(): Promise<void> {
  if (Platform.OS !== 'android') return;
  await Notifications.setNotificationChannelAsync(PUSH_CHANNELS.default, {
    name: reminderChannelName(),
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
  /** `${session token}|${expo token}|${language}` the server accepted in this app run. */
  registeredKey: string | null;
  /** Device ms until which a token event is the echo of our own fetch. */
  selfFetchUntil: number;
  /**
   * Device push token we consider known: the echo of our own fetch, or the last
   * rotation we acted on. An event with a different token is a real rotation.
   */
  lastDeviceToken: string | null;
  /** A real rotation arrived while a run was in flight → register once after it. */
  rotationPending: boolean;
  /** The language changed while a run was in flight (it may have sent the old one) → once more after it. */
  languagePending: boolean;
  failedAttempts: number;
  retryTimer: ReturnType<typeof setTimeout> | null;
  /** Bumped by {@link resetPushRegistration}; runs / retries of older generations are ignored. */
  generation: number;
}

const state: RegistrationState = {
  inFlight: null,
  registeredKey: null,
  selfFetchUntil: 0,
  lastDeviceToken: null,
  rotationPending: false,
  languagePending: false,
  failedAttempts: 0,
  retryTimer: null,
  generation: 0,
};

/** Forget this app run's registration (logout, tests). */
export function resetPushRegistration(): void {
  if (state.retryTimer !== null) clearTimeout(state.retryTimer);
  state.generation += 1;
  state.inFlight = null;
  state.registeredKey = null;
  state.selfFetchUntil = 0;
  state.lastDeviceToken = null;
  state.rotationPending = false;
  state.languagePending = false;
  state.failedAttempts = 0;
  state.retryTimer = null;
  devicesGate.reset();
}

/**
 * `Notifications.addPushTokenListener` handler. Re-registers only for a device token
 * that really changed — never for the echo of our own `getExpoPushTokenAsync()`.
 *
 * - The first event inside our own fetch window (run in flight or its grace period) is
 *   the echo of that fetch: it becomes the known token. Repeats of it are ignored.
 * - A DIFFERENT token is a real rotation. While a run is in flight it is remembered and
 *   registered once after the run settles; in the grace period after a run (or later)
 *   it registers right away.
 * Every registration still goes through the in-flight sharing, the (session, token)
 * dedupe and the `POST /api/devices` limiter. Returns whether a registration was started.
 */
export function handlePushTokenEvent(deviceToken: string, nowMs: number = Date.now()): boolean {
  const known = state.lastDeviceToken;
  const ownFetchWindow = state.inFlight !== null || nowMs < state.selfFetchUntil;
  if (known === deviceToken) return false;
  state.lastDeviceToken = deviceToken;
  // First token we see during our own fetch: its echo, already covered by that run.
  if (ownFetchWindow && known === null) return false;
  if (state.inFlight !== null) {
    state.rotationPending = true;
    return false;
  }
  void registerForPush();
  return true;
}

function scheduleRetry(delayMs: number, generation: number): void {
  if (generation !== state.generation) return;
  if (state.retryTimer !== null || state.failedAttempts >= MAX_REGISTER_ATTEMPTS) return;
  state.retryTimer = setTimeout(() => {
    if (generation !== state.generation) return;
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
  const generation = state.generation;
  const run = registerOnce(generation).finally(() => {
    if (state.inFlight === run) state.inFlight = null;
    // A real rotation or a language switch arrived meanwhile → one more (deduped,
    // limited by the devices gate) registration.
    if (generation === state.generation && (state.rotationPending || state.languagePending)) {
      state.rotationPending = false;
      state.languagePending = false;
      void registerForPush();
    }
  });
  state.inFlight = run;
  return run;
}

/**
 * The app language changed (signed in): register again so pushes come in the new language.
 * A run in flight may already have sent the old language — then exactly one more run
 * follows it (several switches during one run still mean one more run). Deduped per
 * (session, token, language) and limited by {@link devicesGate}.
 */
export function registerForLanguageChange(): void {
  if (state.inFlight !== null) {
    state.languagePending = true;
    return;
  }
  void registerForPush();
}

/** Result of a run whose session was reset (logout) while it ran: touches nothing. */
const STALE: PushRegistrationResult = { status: 'failed', reason: 'server' };

async function registerOnce(generation: number): Promise<PushRegistrationResult> {
  const stale = (): boolean => generation !== state.generation;
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
    // Also after a reset: the iOS echo of this fetch still arrives and must be ignored.
    state.selfFetchUntil = Date.now() + SELF_TOKEN_EVENT_GRACE_MS;
  }
  if (stale()) return STALE;

  let session: string | null;
  try {
    session = await getAuthToken();
  } catch {
    session = null;
  }
  if (stale()) return STALE;
  // The server stores the push language per device, only from the explicit `locale`
  // field (M1-18): a language switch re-registers.
  const locale = currentLanguage();
  const key = `${session ?? ''}|${token}|${locale}`;
  if (state.registeredKey === key) return { status: 'registered', token };

  const wait = devicesGate.waitMs(Date.now());
  if (wait > 0) {
    scheduleRetry(wait, generation);
    return { status: 'failed', reason: 'throttled' };
  }
  devicesGate.take(Date.now());
  try {
    await api.registerDevice({ expo_push_token: token, platform, app_version: appVersion(), locale });
  } catch (error) {
    if (stale()) return STALE;
    state.failedAttempts += 1;
    const after = retryAfterMs(error);
    if (after !== null) devicesGate.block(Date.now(), after);
    const status = errorStatus(error);
    // 429 / 5xx / network: try again later; other 4xx (validation, auth) won't change.
    if (after !== null || status === null || status >= 500) {
      scheduleRetry(after ?? Math.min(30_000 * 2 ** (state.failedAttempts - 1), 5 * 60_000), generation);
    }
    return { status: 'failed', reason: after !== null ? 'throttled' : 'server' };
  }
  // Logged out meanwhile: don't mark the old session registered or store its token.
  if (stale()) return STALE;
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
