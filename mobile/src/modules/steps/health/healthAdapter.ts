/**
 * The health step source of this device (M3-04 / M3-05), or null when there is none:
 * web, Expo Go (no custom native code), or a build without the native module (an older
 * binary). The platform implementation is `require`d lazily so the other platform's
 * native library is never loaded — and Jest mocks this module (`__mocks__/healthAdapter.ts`).
 */

import { Platform } from 'react-native';
import Constants, { ExecutionEnvironment } from 'expo-constants';

import type { HealthStepsAdapter } from './types';

let cached: HealthStepsAdapter | null | undefined;

function create(): HealthStepsAdapter | null {
  if (Constants.executionEnvironment === ExecutionEnvironment.StoreClient) return null;
  try {
    if (Platform.OS === 'ios') {
      const { createHealthKitAdapter } = require('./healthKitAdapter') as typeof import('./healthKitAdapter');
      return createHealthKitAdapter();
    }
    if (Platform.OS === 'android') {
      const { createHealthConnectAdapter } = require('./healthConnectAdapter') as typeof import('./healthConnectAdapter');
      return createHealthConnectAdapter();
    }
  } catch {
    // Native module missing in this binary → keep the motion-sensor path.
  }
  return null;
}

export function getHealthAdapter(): HealthStepsAdapter | null {
  if (cached === undefined) cached = create();
  return cached;
}
