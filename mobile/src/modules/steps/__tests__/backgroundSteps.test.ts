/**
 * M3-06 background step sync (iOS, best effort): what one task run does.
 */
import * as BackgroundTask from 'expo-background-task';
import * as TaskManager from 'expo-task-manager';
import { Platform } from 'react-native';

import type { ChildPetState } from '@/api/client';
import {
  defineStepSyncTask,
  registerStepSyncTask,
  runBackgroundStepSync,
  setNativeModuleProbeForTests,
  STEP_SYNC_TASK,
  stepSyncTaskBody,
  unregisterStepSyncTask,
  type BackgroundSyncDeps,
} from '@/modules/steps/backgroundSteps';
import { makeFakeHealth } from '@/test-utils/fakeHealth';
import { makeLiveChildState } from '@/test-utils/fixtures';

const NOW = new Date('2026-10-04T10:00:00Z'); // 12:00 in Ljubljana

function childState(overrides: Parameters<typeof makeLiveChildState>[0] = {}): ChildPetState {
  return makeLiveChildState({ server_time: '2026-10-04T11:55:00+02:00', timezone: 'Europe/Ljubljana', ...overrides });
}

function deps(overrides: Partial<BackgroundSyncDeps> = {}): Partial<BackgroundSyncDeps> & { syncSteps: jest.Mock } {
  const syncSteps = jest.fn(async () => ({}));
  return {
    platform: 'ios',
    health: makeFakeHealth({ steps: 6400 }),
    pedometer: {
      getPermissionsAsync: jest.fn(async () => ({ status: 'granted', granted: true, canAskAgain: true, expires: 'never' })),
      getStepCountAsync: jest.fn(async () => ({ steps: 6100 })),
    } as unknown as BackgroundSyncDeps['pedometer'],
    getToken: jest.fn(async () => 'child-token'),
    getChildPet: jest.fn(async () => childState({ steps: { my_steps_today: 2000 } })),
    now: () => NOW,
    syncSteps,
    ...overrides,
  } as Partial<BackgroundSyncDeps> & { syncSteps: jest.Mock };
}

describe('runBackgroundStepSync', () => {
  it('sends the max-merged total of today (family day) with an ISO offset', async () => {
    const d = deps();
    expect(await runBackgroundStepSync(d)).toBe('synced');
    expect(d.syncSteps).toHaveBeenCalledWith({
      steps_today: 6400,
      source: 'healthkit',
      recorded_at: '2026-10-04T10:00:00+00:00',
    });
    const health = d.health as ReturnType<typeof makeFakeHealth>;
    expect(health.readSteps.mock.calls[0][0].toISOString()).toBe('2026-10-03T22:00:00.000Z'); // family midnight
  });

  it('nothing new → no request', async () => {
    const d = deps({ getChildPet: jest.fn(async () => childState({ steps: { my_steps_today: 6400 } })) });
    expect(await runBackgroundStepSync(d)).toBe('up_to_date');
    expect(d.syncSteps).not.toHaveBeenCalled();
  });

  it('a server count from yesterday (state fetched before midnight) is not credited to today', async () => {
    const d = deps({
      now: () => new Date('2026-10-04T22:20:00Z'), // 00:20 on 5 Oct in Ljubljana
      health: makeFakeHealth({ steps: 90 }),
      pedometer: {
        getPermissionsAsync: jest.fn(async () => ({ status: 'denied', granted: false, canAskAgain: false, expires: 'never' })),
        getStepCountAsync: jest.fn(),
      } as unknown as BackgroundSyncDeps['pedometer'],
      getChildPet: jest.fn(async () => childState({ server_time: '2026-10-04T23:59:00+02:00', steps: { my_steps_today: 8000 } })),
    });
    expect(await runBackgroundStepSync(d)).toBe('synced');
    expect(d.syncSteps).toHaveBeenCalledWith(expect.objectContaining({ steps_today: 90 }));
  });

  it.each([
    ['Android (Health Connect has no background read permission)', { platform: 'android' as const }],
    ['no health store', { health: null }],
    ['signed out (no token)', { getToken: jest.fn(async () => null) }],
    ['Health not connected', { health: makeFakeHealth({ access: 'undetermined' }) }],
  ])('skips: %s', async (_label, overrides) => {
    const d = deps(overrides);
    expect(await runBackgroundStepSync(d)).toBe('skipped');
    expect(d.syncSteps).not.toHaveBeenCalled();
  });

  it('skips while the pet is locked (the server would answer 423 anyway)', async () => {
    const d = deps({
      getChildPet: jest.fn(async () => childState({ lock: { is_locked: true, reason: 'hard_stop', until: null } })),
    });
    expect(await runBackgroundStepSync(d)).toBe('skipped');
    expect(d.syncSteps).not.toHaveBeenCalled();
  });

  it('offline / server error → failed, never throws', async () => {
    const d = deps({ getChildPet: jest.fn(async () => Promise.reject(new TypeError('Network request failed'))) });
    expect(await runBackgroundStepSync(d)).toBe('failed');
  });

  it('phone locked (Health encrypted) and no sensor → failed, nothing sent', async () => {
    const health = makeFakeHealth();
    health.readSteps.mockRejectedValue(new Error('health data locked'));
    const d = deps({
      health,
      pedometer: {
        getPermissionsAsync: jest.fn(async () => ({ status: 'denied', granted: false, canAskAgain: false, expires: 'never' })),
        getStepCountAsync: jest.fn(),
      } as unknown as BackgroundSyncDeps['pedometer'],
    });
    expect(await runBackgroundStepSync(d)).toBe('failed');
    expect(d.syncSteps).not.toHaveBeenCalled();
  });
});

describe('task registration', () => {
  const originalOS = Platform.OS;
  const setOS = (os: typeof Platform.OS) => Object.defineProperty(Platform, 'OS', { value: os, configurable: true });

  beforeEach(() => {
    jest.clearAllMocks();
    setOS('ios');
    setNativeModuleProbeForTests(() => true);
  });
  afterAll(() => {
    setOS(originalOS);
    setNativeModuleProbeForTests(null);
  });

  it('old binary / dev client without the native task modules: define, register and unregister are no-ops (no crash)', async () => {
    const probe = jest.fn((name: string) => name !== 'ExpoBackgroundTask');
    setNativeModuleProbeForTests(probe);
    expect(() => defineStepSyncTask()).not.toThrow();
    expect(await registerStepSyncTask()).toBe(false);
    await expect(unregisterStepSyncTask()).resolves.toBeUndefined();
    expect(probe).toHaveBeenCalledWith('ExpoBackgroundTask');
    expect(TaskManager.defineTask).not.toHaveBeenCalled();
    expect(TaskManager.isTaskRegisteredAsync).not.toHaveBeenCalled();
    expect(BackgroundTask.registerTaskAsync).not.toHaveBeenCalled();
    expect(BackgroundTask.unregisterTaskAsync).not.toHaveBeenCalled();
  });

  it('a probe that throws counts as missing', async () => {
    setNativeModuleProbeForTests(() => {
      throw new Error('no expo-modules-core');
    });
    expect(await registerStepSyncTask()).toBe(false);
  });

  it('defines the task once, at module scope, on iOS only', () => {
    defineStepSyncTask();
    expect(TaskManager.defineTask).toHaveBeenCalledWith(STEP_SYNC_TASK, stepSyncTaskBody);
    setOS('android');
    (TaskManager.defineTask as jest.Mock).mockClear();
    defineStepSyncTask();
    expect(TaskManager.defineTask).not.toHaveBeenCalled();
  });

  it('registers with a 15-minute minimum interval, idempotently', async () => {
    expect(await registerStepSyncTask()).toBe(true);
    expect(BackgroundTask.registerTaskAsync).toHaveBeenCalledWith(STEP_SYNC_TASK, { minimumInterval: 15 });
    (TaskManager.isTaskRegisteredAsync as jest.Mock).mockResolvedValueOnce(true);
    (BackgroundTask.registerTaskAsync as jest.Mock).mockClear();
    expect(await registerStepSyncTask()).toBe(true);
    expect(BackgroundTask.registerTaskAsync).not.toHaveBeenCalled();
  });

  it('does not register when Background App Refresh is off, nor on Android', async () => {
    (BackgroundTask.getStatusAsync as jest.Mock).mockResolvedValueOnce(BackgroundTask.BackgroundTaskStatus.Restricted);
    expect(await registerStepSyncTask()).toBe(false);
    setOS('android');
    expect(await registerStepSyncTask()).toBe(false);
    expect(BackgroundTask.registerTaskAsync).not.toHaveBeenCalled();
  });

  it('unregisters on logout only when registered; never throws', async () => {
    await unregisterStepSyncTask();
    expect(BackgroundTask.unregisterTaskAsync).not.toHaveBeenCalled();
    (TaskManager.isTaskRegisteredAsync as jest.Mock).mockResolvedValueOnce(true);
    await unregisterStepSyncTask();
    expect(BackgroundTask.unregisterTaskAsync).toHaveBeenCalledWith(STEP_SYNC_TASK);
    (TaskManager.isTaskRegisteredAsync as jest.Mock).mockRejectedValueOnce(new Error('no module'));
    await expect(unregisterStepSyncTask()).resolves.toBeUndefined();
  });

  it('the task body reports Success for a skipped run (Failed is only for errors)', async () => {
    // Default deps in Jest: the health adapter mock returns null → skipped → Success.
    expect(await stepSyncTaskBody()).toBe(1); // BackgroundTaskResult.Success
  });
});
