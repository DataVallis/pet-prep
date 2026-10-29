/**
 * »Preveri posodobitve« (parent Nadzor only, David 2026-10-29): check → download → offer a
 * restart. The ONE place in the app that calls the expo-updates check / fetch / reload
 * functions — only from the parent's button, never at boot (`updatesNeverBlockBoot.test.ts`
 * allows exactly this file and checks that nothing on the launch path imports it).
 *
 * - Off (`unavailable`) in `__DEV__`, Expo Go / dev clients, without the native module or
 *   when expo-updates is disabled — the native calls would reject there anyway.
 * - Every step is bounded (`withTimeout`): a hung network never leaves the button spinning.
 * - Never reloads by itself: `ready` only tells the parent; `restartIntoUpdate()` is the
 *   »Znova zaženi zdaj« button.
 * - `fetchUpdateAsync` compares with the RUNNING update, so an update the launcher already
 *   downloaded in the background (ON_LOAD) still comes back as new → `ready`.
 */

import { requireOptionalNativeModule } from 'expo';
import Constants, { ExecutionEnvironment } from 'expo-constants';

import { isTimeoutError, withTimeout } from '@/utils/withTimeout';

export const CHECK_TIMEOUT_MS = 15_000;
export const FETCH_TIMEOUT_MS = 60_000;

/** The slice of expo-updates used here (own type: the boot guard forbids a static import). */
export interface UpdatesApi {
  isEnabled: boolean;
  checkForUpdateAsync(): Promise<{ isAvailable: boolean; isRollBackToEmbedded?: boolean }>;
  fetchUpdateAsync(): Promise<{ isNew: boolean; isRollBackToEmbedded?: boolean }>;
  reloadAsync(): Promise<void>;
}

export type UpdateCheckOutcome =
  /** No check possible in this runtime (dev, Expo Go, no module, disabled). */
  | 'unavailable'
  | 'up_to_date'
  /** A newer bundle (or a rollback) is downloaded; a restart applies it. */
  | 'ready'
  | 'error'
  | 'timeout';

type Loader = () => UpdatesApi | null;

const defaultLoader: Loader = () => {
  if (__DEV__) return null;
  try {
    if (Constants.executionEnvironment === ExecutionEnvironment.StoreClient) return null;
    if (requireOptionalNativeModule('ExpoUpdates') == null) return null;
    const updates = require('expo-updates') as UpdatesApi;
    return updates.isEnabled ? updates : null;
  } catch {
    return null;
  }
};

let loader: Loader = defaultLoader;

function updatesApi(): UpdatesApi | null {
  try {
    return loader();
  } catch {
    return null;
  }
}

/** Whether »Preveri posodobitve« can do anything in this runtime. */
export function canCheckForUpdates(): boolean {
  return updatesApi() !== null;
}

/** Check, and when there is something, download it. Never throws, never reloads. */
export async function checkAndFetchUpdate(
  timeouts: { checkMs?: number; fetchMs?: number } = {},
): Promise<UpdateCheckOutcome> {
  const updates = updatesApi();
  if (!updates) return 'unavailable';
  try {
    const check = await withTimeout(updates.checkForUpdateAsync(), timeouts.checkMs ?? CHECK_TIMEOUT_MS, undefined, 'update check');
    if (!check.isAvailable && !check.isRollBackToEmbedded) return 'up_to_date';
    const fetched = await withTimeout(updates.fetchUpdateAsync(), timeouts.fetchMs ?? FETCH_TIMEOUT_MS, undefined, 'update download');
    return fetched.isNew || fetched.isRollBackToEmbedded ? 'ready' : 'up_to_date';
  } catch (error) {
    return isTimeoutError(error) ? 'timeout' : 'error';
  }
}

/** »Znova zaženi zdaj«: restart into the downloaded bundle. False when the reload failed. */
export async function restartIntoUpdate(): Promise<boolean> {
  const updates = updatesApi();
  if (!updates) return false;
  try {
    await updates.reloadAsync();
    return true;
  } catch {
    return false;
  }
}

/** Tests: replace the expo-updates lookup (null = the real one, which is off under `__DEV__`). */
export function setUpdatesApiForTests(next: UpdatesApi | null | 'real'): void {
  loader = next === 'real' ? defaultLoader : () => next;
}
