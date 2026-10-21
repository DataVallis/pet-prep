/**
 * Apple Health adapter (M3-04) on `@kingstinct/react-native-healthkit` (Nitro module,
 * New Architecture). Read permission for `HKQuantityTypeIdentifierStepCount` ONLY — no
 * share (write) types, no other data. M5-T12 (adapted mode): the `pushes` adapter reads
 * `HKQuantityTypeIdentifierPushCount` ONLY instead (wheelchair pushes — Apple records them
 * when "Wheelchair" is on in Health's details and an Apple Watch counts them). The entitlement and the usage text come from the
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

import type { HealthAccess, HealthMeasure, HealthStepsAdapter } from './types';

export const HK_STEP_COUNT = 'HKQuantityTypeIdentifierStepCount' as const;
/** M5-T12: wheelchair pushes (iOS 10+). */
export const HK_PUSH_COUNT = 'HKQuantityTypeIdentifierPushCount' as const;

const TYPE_OF = { steps: HK_STEP_COUNT, pushes: HK_PUSH_COUNT } as const;

function accessFrom(status: AuthorizationRequestStatus): HealthAccess {
  // `unnecessary` = the sheet was already answered (granted OR denied — HealthKit hides which).
  return status === AuthorizationRequestStatus.unnecessary ? 'connected' : 'undetermined';
}

export function createHealthKitAdapter(measure: HealthMeasure = 'steps'): HealthStepsAdapter {
  const type = TYPE_OF[measure];
  /** The only thing this adapter ever asks HealthKit for. */
  const READ_ONLY = { toRead: [type] } as const;
  return {
    source: 'healthkit',
    measure,

    async availability() {
      return (await isHealthDataAvailableAsync()) ? 'available' : 'unavailable';
    },

    async access() {
      return accessFrom(await getRequestStatusForAuthorization(READ_ONLY));
    },

    async requestAccess() {
      await requestAuthorization(READ_ONLY);
      return accessFrom(await getRequestStatusForAuthorization(READ_ONLY));
    },

    async readSteps(start, end) {
      // Health data is encrypted while the phone is locked (background task case).
      if (!isProtectedDataAvailable()) throw new Error('health data locked');
      const result = await queryStatisticsForQuantity(type, ['cumulativeSum'], {
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
