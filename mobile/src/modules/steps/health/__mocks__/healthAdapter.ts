/**
 * Jest manual mock (registered in `jest.setup.ts`): no health store by default, so every
 * existing test keeps the motion-sensor path. Health tests pass their own adapter
 * through `deps.health` (see `test-utils/fakeHealth.ts`).
 */

import type { HealthMeasure, HealthStepsAdapter } from '../types';

export const getHealthAdapter = jest.fn((_measure?: HealthMeasure): HealthStepsAdapter | null => null);
