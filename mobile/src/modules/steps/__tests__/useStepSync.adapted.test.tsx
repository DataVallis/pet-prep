/**
 * M5-T12 adapted mode in `useStepSync`: the child's walk counts wheelchair pushes from the
 * health store only — no motion sensor, no live counter — and syncs them with
 * `unit: pushes` (1 push = 1 step). Switching the mode switches the data type.
 */
import type { ReactNode } from 'react';
import { AppState, type AppStateStatus } from 'react-native';
import { act, renderHook } from '@testing-library/react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { api } from '@/api/client';
import { writeChildState } from '@/hooks/queries/useChildPet';
import type { KeyValueStore } from '@/modules/steps/stepCounter';
import { useStepSync, type StepSyncDeps } from '@/modules/steps/useStepSync';
import { useAppStore } from '@/store/appStore';
import { makeFakeHealth } from '@/test-utils/fakeHealth';
import { makeLiveChildState, makePet } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, syncSteps: jest.fn() } };
});

const syncSteps = api.syncSteps as jest.Mock;

function makePedometer(steps = 2400) {
  const response = { status: 'granted', granted: true, canAskAgain: true, expires: 'never' };
  return {
    isAvailableAsync: jest.fn(async () => true),
    getPermissionsAsync: jest.fn(async () => response),
    requestPermissionsAsync: jest.fn(async () => response),
    getStepCountAsync: jest.fn(async () => ({ steps })),
    watchStepCount: jest.fn(() => ({ remove: jest.fn() })),
  } as unknown as StepSyncDeps['pedometer'] & { getStepCountAsync: jest.Mock; watchStepCount: jest.Mock; requestPermissionsAsync: jest.Mock };
}

const store: KeyValueStore = { getItemAsync: jest.fn(async () => null), setItemAsync: jest.fn(async () => undefined) };

function setup(deps: Partial<StepSyncDeps>, adaptedMode: boolean, myStepsToday = 0) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false, gcTime: Infinity } },
  });
  writeChildState(client, makeLiveChildState());
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  return renderHook(
    ({ adapted }: { adapted: boolean }) =>
      useStepSync({
        enabled: true,
        myStepsToday,
        serverTime: '2026-10-04T12:00:00+02:00',
        timezone: 'Europe/Ljubljana',
        adaptedMode: adapted,
        deps: { registerBackground: jest.fn(async () => true), storage: store, ...deps },
      }),
    { wrapper, initialProps: { adapted: adaptedMode } },
  );
}

const flush = async () => {
  for (let i = 0; i < 4; i++) {
    await act(async () => {
      await jest.advanceTimersByTimeAsync(0);
    });
  }
};

describe('useStepSync — adapted mode (wheelchair pushes)', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-10-04T10:00:00Z'));
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({ token: 't', user: { id: 2, name: 'Maja', email: null, role: 'child' }, pet: makePet(), awaitingContract: false });
    jest.spyOn(AppState, 'addEventListener').mockImplementation(
      (_type, _listener: (s: AppStateStatus) => void) => ({ remove: jest.fn() }) as unknown as ReturnType<typeof AppState.addEventListener>,
    );
    syncSteps.mockImplementation(async ({ steps_today }: { steps_today: number }) => ({
      status: 'accepted',
      accepted_steps: steps_today,
      steps_today,
      energy_level: 60,
      state: makeLiveChildState({ steps: { steps_today, my_steps_today: steps_today, energy_level: 60 } }),
    }));
  });

  afterEach(() => {
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it('iOS: sends the health pushes with unit pushes; the motion sensor is never read', async () => {
    const health = makeFakeHealth({ steps: 5200 });
    const pushesHealth = makeFakeHealth({ steps: 700, measure: 'pushes' });
    const pedometer = makePedometer(4100);
    const { result } = setup({ platform: 'ios', pedometer, health, pushesHealth }, true);
    await flush();

    expect(result.current.adaptedMode).toBe(true);
    expect(result.current.health.measure).toBe('pushes');
    expect(result.current.health.status).toBe('connected');
    expect(syncSteps).toHaveBeenCalledTimes(1);
    expect(syncSteps.mock.calls[0][0]).toEqual(expect.objectContaining({ steps_today: 700, source: 'healthkit', unit: 'pushes' }));
    expect(pedometer.getStepCountAsync).not.toHaveBeenCalled();
    expect(health.readSteps).not.toHaveBeenCalled();
  });

  it('Android: no live step counter in adapted mode', async () => {
    const pushesHealth = makeFakeHealth({ steps: 300, measure: 'pushes', source: 'health_connect' });
    const pedometer = makePedometer();
    setup({ platform: 'android', pedometer, health: makeFakeHealth({ source: 'health_connect' }), pushesHealth }, true);
    await flush();
    expect(pedometer.watchStepCount).not.toHaveBeenCalled();
    expect(syncSteps.mock.calls[0][0]).toEqual(expect.objectContaining({ steps_today: 300, unit: 'pushes' }));
  });

  it('without pushes access nothing syncs (a granted motion sensor does not count)', async () => {
    const pushesHealth = makeFakeHealth({ steps: 700, measure: 'pushes', access: 'undetermined' });
    const { result } = setup({ platform: 'ios', pedometer: makePedometer(), health: makeFakeHealth(), pushesHealth }, true);
    await flush();
    expect(result.current.health.status).toBe('undetermined');
    expect(result.current.canSync).toBe(false);
    expect(syncSteps).not.toHaveBeenCalled();
  });

  it('connect() asks for pushes only and not for the motion sensor', async () => {
    const pushesHealth = makeFakeHealth({ measure: 'pushes', access: 'undetermined', steps: 50 });
    const health = makeFakeHealth({ access: 'undetermined' });
    const response = { status: 'undetermined', granted: false, canAskAgain: true, expires: 'never' };
    const pedometer = makePedometer();
    (pedometer.getPermissionsAsync as unknown as jest.Mock).mockResolvedValue(response);
    const { result } = setup({ platform: 'ios', pedometer, health, pushesHealth }, true);
    await flush();
    await act(async () => {
      await result.current.health.connect();
    });
    await flush();
    expect(pushesHealth.requestAccess).toHaveBeenCalledTimes(1);
    expect(health.requestAccess).not.toHaveBeenCalled();
    expect(pedometer.requestPermissionsAsync).not.toHaveBeenCalled();
    expect(result.current.health.status).toBe('connected');
  });

  it('switching the mode off goes back to steps (no unit)', async () => {
    const health = makeFakeHealth({ steps: 5200 });
    const pushesHealth = makeFakeHealth({ steps: 700, measure: 'pushes' });
    const hook = setup({ platform: 'ios', pedometer: makePedometer(4100), health, pushesHealth }, true);
    await flush();
    expect(syncSteps.mock.calls[0][0]).toEqual(expect.objectContaining({ unit: 'pushes' }));

    hook.rerender({ adapted: false });
    await flush();
    await act(async () => {
      await hook.result.current.syncNow();
    });
    const last = syncSteps.mock.calls[syncSteps.mock.calls.length - 1][0] as Record<string, unknown>;
    expect(hook.result.current.health.measure).toBe('steps');
    expect(last).toEqual(expect.objectContaining({ steps_today: 5200, source: 'healthkit' }));
    expect(last).not.toHaveProperty('unit');
  });

  it('M5-T12 QA M1: watch pushes above the device count sync even when a parent-logged walk makes the total higher', async () => {
    // Server: my_steps_today 3000 = 2000 logged by the parent + 1000 device; the hook gets the device part.
    const pushesHealth = makeFakeHealth({ steps: 1500, measure: 'pushes' });
    setup({ platform: 'ios', pedometer: makePedometer(), health: makeFakeHealth(), pushesHealth }, true, 1000);
    await flush();
    expect(syncSteps).toHaveBeenCalledTimes(1);
    expect(syncSteps.mock.calls[0][0]).toEqual(expect.objectContaining({ steps_today: 1500, unit: 'pushes' }));
  });
});
