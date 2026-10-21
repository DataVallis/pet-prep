/**
 * The health step source of this device (M3-04 / M3-05), or null when there is none:
 * web, Expo Go (no custom native code), or a build without the native module (an older
 * binary). The platform implementation is `require`d lazily so the other platform's
 * native library is never loaded — and Jest mocks this module (`__mocks__/healthAdapter.ts`).
 */

import { Platform } from 'react-native';
import Constants, { ExecutionEnvironment } from 'expo-constants';

import type { HealthMeasure, HealthStepsAdapter } from './types';

const cached: Partial<Record<HealthMeasure, HealthStepsAdapter | null>> = {};

function create(measure: HealthMeasure): HealthStepsAdapter | null {
  if (Constants.executionEnvironment === ExecutionEnvironment.StoreClient) return null;
  try {
    if (Platform.OS === 'ios') {
      const { createHealthKitAdapter } = require('./healthKitAdapter') as typeof import('./healthKitAdapter');
      return createHealthKitAdapter(measure);
    }
    if (Platform.OS === 'android') {
      const { createHealthConnectAdapter } = require('./healthConnectAdapter') as typeof import('./healthConnectAdapter');
      return createHealthConnectAdapter(undefined, measure);
    }
  } catch {
    // Native module missing in this binary → keep the motion-sensor path.
  }
  return null;
}

/** M5-T12: `pushes` = the wheelchair-push source of the same health store (adapted mode). */
export function getHealthAdapter(measure: HealthMeasure = 'steps'): HealthStepsAdapter | null {
  if (!(measure in cached)) cached[measure] = create(measure);
  return cached[measure] ?? null;
}
