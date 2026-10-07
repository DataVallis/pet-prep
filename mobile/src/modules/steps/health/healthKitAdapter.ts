/**
 * Apple Health adapter (M3-04) on `@kingstinct/react-native-healthkit` (Nitro module,
 * New Architecture). Read permission for `HKQuantityTypeIdentifierStepCount` ONLY — no
 * share (write) types, no other data. The entitlement and the usage text come from the
 * library's config plugin (`app.json`, `NSHealthUpdateUsageDescription: false`).
 *
 * Imported lazily by `healthAdapter.ts` on iOS only, so Jest / Android never load it.
 */

import { Linking } from 'react-native';
import {
  AuthorizationRequestStatus,
  getRequestStatusForAuthorization,
  isHealthDataAvailableAsync,
  isProtectedDataAvailable,
  queryStatisticsForQuantity,
  requestAuthorization,
} from '@kingstinct/react-native-healthkit';

import type { HealthAccess, HealthStepsAdapter } from './types';

export const HK_STEP_COUNT = 'HKQuantityTypeIdentifierStepCount' as const;

/** The only thing we ever ask HealthKit for. */
const READ_STEPS_ONLY = { toRead: [HK_STEP_COUNT] } as const;

function accessFrom(status: AuthorizationRequestStatus): HealthAccess {
  // `unnecessary` = the sheet was already answered (granted OR denied — HealthKit hides which).
  return status === AuthorizationRequestStatus.unnecessary ? 'connected' : 'undetermined';
}

export function createHealthKitAdapter(): HealthStepsAdapter {
  return {
    source: 'healthkit',

    async availability() {
      return (await isHealthDataAvailableAsync()) ? 'available' : 'unavailable';
    },

    async access() {
      return accessFrom(await getRequestStatusForAuthorization(READ_STEPS_ONLY));
    },

    async requestAccess() {
      await requestAuthorization(READ_STEPS_ONLY);
      return accessFrom(await getRequestStatusForAuthorization(READ_STEPS_ONLY));
    },

    async readSteps(start, end) {
      // Health data is encrypted while the phone is locked (background task case).
      if (!isProtectedDataAvailable()) throw new Error('health data locked');
      const result = await queryStatisticsForQuantity(HK_STEP_COUNT, ['cumulativeSum'], {
        unit: 'count',
        filter: { date: { startDate: start, endDate: end } },
      });
      const steps = result.sumQuantity?.quantity ?? 0;
      return Number.isFinite(steps) && steps > 0 ? Math.floor(steps) : 0;
    },

    async openSettings() {
      // iOS has no deep link to Health → Data Access; the app's Settings page is the
      // closest public entry (the overlay text names the Health path for a parent).
      await Linking.openSettings();
    },

    async openStore() {
      // Apple Health ships with iOS.
    },
  };
}
