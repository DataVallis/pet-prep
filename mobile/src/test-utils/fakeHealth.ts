/**
 * A scriptable Apple Health / Health Connect adapter for Jest (M3-04 / M3-05). The real
 * adapters wrap native libraries that can't run in Jest; hooks and the background task
 * take this through `deps.health`.
 */

import type { HealthAccess, HealthAvailability, HealthSource, HealthStepsAdapter } from '@/modules/steps/health/types';

export interface FakeHealthOptions {
  source?: HealthSource;
  availability?: HealthAvailability;
  access?: HealthAccess;
  /** What `requestAccess()` resolves to (default `connected`). */
  afterRequest?: HealthAccess;
  steps?: number;
}

export type FakeHealth = HealthStepsAdapter & {
  availability: jest.Mock<Promise<HealthAvailability>, []>;
  access: jest.Mock<Promise<HealthAccess>, []>;
  requestAccess: jest.Mock<Promise<HealthAccess>, []>;
  readSteps: jest.Mock<Promise<number>, [Date, Date]>;
  openSettings: jest.Mock<Promise<void>, []>;
  openStore: jest.Mock<Promise<void>, []>;
};

export function makeFakeHealth(options: FakeHealthOptions = {}): FakeHealth {
  let access: HealthAccess = options.access ?? 'connected';
  return {
    source: options.source ?? 'healthkit',
    availability: jest.fn(async () => options.availability ?? 'available'),
    access: jest.fn(async () => access),
    requestAccess: jest.fn(async () => {
      access = options.afterRequest ?? 'connected';
      return access;
    }),
    readSteps: jest.fn(async (_start: Date, _end: Date) => options.steps ?? 0),
    openSettings: jest.fn(async () => undefined),
    openStore: jest.fn(async () => undefined),
  };
}
