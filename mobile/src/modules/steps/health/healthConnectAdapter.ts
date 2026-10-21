/**
 * Health Connect adapter (M3-05) on `react-native-health-connect` (its own Expo config
 * plugin adds the permission-rationale intent filter + Android 14 activity alias).
 * Permission `android.permission.health.READ_STEPS` ONLY (declared in `app.json`); no
 * writes, no other record types, no background-read permission (so this source is read
 * while the app is open — Health Connect keeps the history, so a day's steps are caught
 * up as soon as the child opens the app).
 *
 * M5-T12 (adapted mode): the `pushes` adapter asks for / reads `WheelchairPushes` ONLY
 * (`android.permission.health.READ_WHEELCHAIR_PUSHES`) instead of steps.
 *
 * Imported lazily by `healthAdapter.ts` on Android only, so Jest / iOS never load it.
 */

import { Linking } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import {
  aggregateRecord,
  getGrantedPermissions,
  getSdkStatus,
  initialize,
  openHealthConnectSettings,
  requestPermission,
  SdkAvailabilityStatus,
} from 'react-native-health-connect';

import type { HealthAccess, HealthMeasure, HealthStepsAdapter } from './types';

export const HEALTH_CONNECT_PACKAGE = 'com.google.android.apps.healthdata';
const STORE_URL = `market://details?id=${HEALTH_CONNECT_PACKAGE}&url=healthconnect%3A%2F%2Fonboarding`;
const STORE_WEB_URL = `https://play.google.com/store/apps/details?id=${HEALTH_CONNECT_PACKAGE}`;

/** Set once the child tapped "Connect" — tells "never asked" from "refused". Not personal data. */
export const HC_REQUESTED_KEY = 'petprep.health_connect_requested';
/** M5-T12: the same flag for the wheelchair-pushes permission (asked separately). */
export const HC_PUSHES_REQUESTED_KEY = 'petprep.health_connect_pushes_requested';

const RECORD_OF = { steps: 'Steps', pushes: 'WheelchairPushes' } as const;

interface PermissionLike {
  accessType?: unknown;
  recordType?: unknown;
}

function hasRead(granted: readonly unknown[], record: string): boolean {
  return granted.some((p) => {
    if (typeof p !== 'object' || p === null) return false;
    const { accessType, recordType } = p as PermissionLike;
    return accessType === 'read' && recordType === record;
  });
}

export function createHealthConnectAdapter(
  store: Pick<typeof SecureStore, 'getItemAsync' | 'setItemAsync'> = SecureStore,
  measure: HealthMeasure = 'steps',
): HealthStepsAdapter {
  const record = RECORD_OF[measure];
  const requestedKey = measure === 'pushes' ? HC_PUSHES_REQUESTED_KEY : HC_REQUESTED_KEY;
  const READ_ONLY = { accessType: 'read', recordType: record } as const;
  let ready: Promise<boolean> | null = null;
  const ensureInitialized = async (): Promise<void> => {
    ready ??= initialize(HEALTH_CONNECT_PACKAGE).catch(() => false);
    if (!(await ready)) {
      ready = null;
      throw new Error('Health Connect not initialized');
    }
  };

  const wasRequested = async (): Promise<boolean> => {
    try {
      return (await store.getItemAsync(requestedKey)) === '1';
    } catch {
      return false;
    }
  };

  return {
    source: 'health_connect',
    measure,

    async availability() {
      const status = await getSdkStatus(HEALTH_CONNECT_PACKAGE);
      if (status === SdkAvailabilityStatus.SDK_AVAILABLE) return 'available';
      if (status === SdkAvailabilityStatus.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED) return 'needs_update';
      return 'unavailable';
    },

    async access(): Promise<HealthAccess> {
      await ensureInitialized();
      if (hasRead(await getGrantedPermissions(), record)) return 'connected';
      return (await wasRequested()) ? 'denied' : 'undetermined';
    },

    async requestAccess(): Promise<HealthAccess> {
      await ensureInitialized();
      try {
        await store.setItemAsync(requestedKey, '1');
      } catch {
        // Only affects the wording next time (undetermined vs denied).
      }
      const granted = await requestPermission([READ_ONLY]);
      return hasRead(granted, record) ? 'connected' : 'denied';
    },

    async readSteps(start, end) {
      await ensureInitialized();
      const timeRangeFilter = { operator: 'between', startTime: start.toISOString(), endTime: end.toISOString() } as const;
      const result =
        measure === 'pushes'
          ? await aggregateRecord({ recordType: 'WheelchairPushes', timeRangeFilter })
          : await aggregateRecord({ recordType: 'Steps', timeRangeFilter });
      const steps = result.COUNT_TOTAL;
      return Number.isFinite(steps) && steps > 0 ? Math.floor(steps) : 0;
    },

    async openSettings() {
      openHealthConnectSettings();
    },

    async openStore() {
      try {
        await Linking.openURL(STORE_URL);
      } catch {
        await Linking.openURL(STORE_WEB_URL);
      }
    },
  };
}
