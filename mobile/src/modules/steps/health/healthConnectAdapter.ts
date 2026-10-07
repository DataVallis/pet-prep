/**
 * Health Connect adapter (M3-05) on `react-native-health-connect` (its own Expo config
 * plugin adds the permission-rationale intent filter + Android 14 activity alias).
 * Permission `android.permission.health.READ_STEPS` ONLY (declared in `app.json`); no
 * writes, no other record types, no background-read permission (so this source is read
 * while the app is open — Health Connect keeps the history, so a day's steps are caught
 * up as soon as the child opens the app).
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

import type { HealthAccess, HealthStepsAdapter } from './types';

export const HEALTH_CONNECT_PACKAGE = 'com.google.android.apps.healthdata';
const STORE_URL = `market://details?id=${HEALTH_CONNECT_PACKAGE}&url=healthconnect%3A%2F%2Fonboarding`;
const STORE_WEB_URL = `https://play.google.com/store/apps/details?id=${HEALTH_CONNECT_PACKAGE}`;

/** Set once the child tapped "Connect" — tells "never asked" from "refused". Not personal data. */
export const HC_REQUESTED_KEY = 'petprep.health_connect_requested';

const READ_STEPS = { accessType: 'read', recordType: 'Steps' } as const;

interface PermissionLike {
  accessType?: unknown;
  recordType?: unknown;
}

function hasReadSteps(granted: readonly unknown[]): boolean {
  return granted.some((p) => {
    if (typeof p !== 'object' || p === null) return false;
    const { accessType, recordType } = p as PermissionLike;
    return accessType === 'read' && recordType === 'Steps';
  });
}

export function createHealthConnectAdapter(store: Pick<typeof SecureStore, 'getItemAsync' | 'setItemAsync'> = SecureStore): HealthStepsAdapter {
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
      return (await store.getItemAsync(HC_REQUESTED_KEY)) === '1';
    } catch {
      return false;
    }
  };

  return {
    source: 'health_connect',

    async availability() {
      const status = await getSdkStatus(HEALTH_CONNECT_PACKAGE);
      if (status === SdkAvailabilityStatus.SDK_AVAILABLE) return 'available';
      if (status === SdkAvailabilityStatus.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED) return 'needs_update';
      return 'unavailable';
    },

    async access(): Promise<HealthAccess> {
      await ensureInitialized();
      if (hasReadSteps(await getGrantedPermissions())) return 'connected';
      return (await wasRequested()) ? 'denied' : 'undetermined';
    },

    async requestAccess(): Promise<HealthAccess> {
      await ensureInitialized();
      try {
        await store.setItemAsync(HC_REQUESTED_KEY, '1');
      } catch {
        // Only affects the wording next time (undetermined vs denied).
      }
      const granted = await requestPermission([READ_STEPS]);
      return hasReadSteps(granted) ? 'connected' : 'denied';
    },

    async readSteps(start, end) {
      await ensureInitialized();
      const result = await aggregateRecord({
        recordType: 'Steps',
        timeRangeFilter: { operator: 'between', startTime: start.toISOString(), endTime: end.toISOString() },
      });
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
