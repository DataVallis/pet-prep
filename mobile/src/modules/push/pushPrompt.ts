/**
 * Ask for the notification permission at a good moment (M3-02), with our own
 * Slovenian explanation first (the system prompt can be shown only once on iOS):
 *  - child: right after signing the contract (the pet is born);
 *  - parent: right after adding a child;
 *  - both (PR #35): the first dashboard / HUD view of a session while the user
 *    hasn't decided (`usePushPromptOnFirstView`).
 * The parent's "Obvestila" row in Nadzor shows the status and enables directly.
 *
 * "Ne zdaj" is remembered; we ask again at the next good moment after 3 days.
 * A permanent "no" (system) is respected — no nagging, the settings app is the way back.
 */

import * as Notifications from 'expo-notifications';
import * as SecureStore from 'expo-secure-store';
import { Alert, Linking, Platform } from 'react-native';

import { PRE_PROMPT_RETRY_MS, PUSH_STORAGE_KEYS, PUSH_STRINGS, type PushAudience } from '@/modules/push/pushConfig';
import { ensureAndroidChannels, isPushAllowed, registerForPush } from '@/modules/push/pushRegistration';

export type PushPromptOutcome = 'registered' | 'granted' | 'denied' | 'declined' | 'skipped';

/** Our pre-prompt: resolves true for "Dovoli obvestila". */
export function showPrePrompt(audience: PushAudience): Promise<boolean> {
  const s = PUSH_STRINGS.prePrompt[audience];
  return new Promise((resolve) => {
    Alert.alert(
      s.title,
      s.message,
      [
        { text: s.later, style: 'cancel', onPress: () => resolve(false) },
        { text: s.allow, onPress: () => resolve(true) },
      ],
      { cancelable: true, onDismiss: () => resolve(false) },
    );
  });
}

async function declinedRecently(now: number): Promise<boolean> {
  try {
    const raw = await SecureStore.getItemAsync(PUSH_STORAGE_KEYS.declinedAt);
    const at = raw === null ? NaN : Number(raw);
    return Number.isFinite(at) && now - at < PRE_PROMPT_RETRY_MS;
  } catch {
    return false;
  }
}

export interface MaybeAskOptions {
  /**
   * First-view prompt (PR #35): only ask when the user hasn't decided yet; an
   * already allowed install is registered by `usePushNotifications`, not here.
   */
  onlyIfUndetermined?: boolean;
}

/** One question at a time (contract → HUD first view can overlap). */
let inFlight: Promise<PushPromptOutcome> | null = null;

export function maybeAskForPush(
  audience: PushAudience,
  now: number = Date.now(),
  options: MaybeAskOptions = {},
): Promise<PushPromptOutcome> {
  if (inFlight) return inFlight;
  inFlight = askForPush(audience, now, options).finally(() => {
    inFlight = null;
  });
  return inFlight;
}

async function askForPush(audience: PushAudience, now: number, { onlyIfUndetermined = false }: MaybeAskOptions): Promise<PushPromptOutcome> {
  if (Platform.OS !== 'ios' && Platform.OS !== 'android') return 'skipped';

  let permissions: Notifications.NotificationPermissionsStatus;
  try {
    permissions = await Notifications.getPermissionsAsync();
  } catch {
    return 'skipped';
  }

  if (isPushAllowed(permissions)) {
    if (onlyIfUndetermined) return 'skipped';
    const result = await registerForPush();
    return result.status === 'registered' ? 'registered' : 'granted';
  }
  // The system already said no for good: only the settings app can change it.
  if (!permissions.canAskAgain) return 'skipped';
  if (await declinedRecently(now)) return 'skipped';

  if (!(await showPrePrompt(audience))) {
    try {
      await SecureStore.setItemAsync(PUSH_STORAGE_KEYS.declinedAt, String(now));
    } catch {
      // Then we may ask again a bit sooner — acceptable.
    }
    return 'declined';
  }

  try {
    await ensureAndroidChannels();
    const answer = await Notifications.requestPermissionsAsync({
      ios: { allowAlert: true, allowSound: true, allowBadge: false },
    });
    if (!isPushAllowed(answer)) return 'denied';
  } catch {
    return 'skipped';
  }

  const result = await registerForPush();
  return result.status === 'registered' ? 'registered' : 'granted';
}

/** Notification permission as the parent's "Obvestila" row shows it (PR #35). */
export type PushPermissionStatus = 'on' | 'off' | 'blocked' | 'unsupported';

export async function getPushPermissionStatus(): Promise<PushPermissionStatus> {
  if (Platform.OS !== 'ios' && Platform.OS !== 'android') return 'unsupported';
  try {
    const permissions = await Notifications.getPermissionsAsync();
    if (isPushAllowed(permissions)) return 'on';
    return permissions.canAskAgain ? 'off' : 'blocked';
  } catch {
    return 'unsupported';
  }
}

/**
 * The user tapped "Vklopi obvestila" (Nadzor): ask the system right away (the row
 * itself explains why), or open the phone settings once the system won't ask again.
 * Clears an earlier "Ne zdaj".
 */
export async function enablePushNotifications(): Promise<PushPermissionStatus> {
  const status = await getPushPermissionStatus();
  if (status === 'unsupported') return status;
  if (status === 'on') {
    await registerForPush();
    return 'on';
  }
  if (status === 'blocked') {
    try {
      await Linking.openSettings();
    } catch {
      // Nothing else we can do.
    }
    return 'blocked';
  }

  try {
    await SecureStore.deleteItemAsync(PUSH_STORAGE_KEYS.declinedAt);
  } catch {
    // Not important.
  }
  try {
    await ensureAndroidChannels();
    const answer = await Notifications.requestPermissionsAsync({
      ios: { allowAlert: true, allowSound: true, allowBadge: false },
    });
    if (!isPushAllowed(answer)) return answer.canAskAgain ? 'off' : 'blocked';
  } catch {
    return 'off';
  }
  await registerForPush();
  return 'on';
}
