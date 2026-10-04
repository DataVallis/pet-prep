/**
 * M1-14 step sync: today's steps → POST /api/child/pet/steps (ISO with offset), on
 * start, on foreground and every 5 minutes; Android from the live counter.
 */
import type { ReactNode } from 'react';
import { AppState, type AppStateStatus } from 'react-native';
import { act, renderHook } from '@testing-library/react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { api } from '@/api/client';
import { childPetKey, writeChildState } from '@/hooks/queries/useChildPet';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { LIVE_STEPS_KEY, type KeyValueStore } from '@/modules/steps/stepCounter';
import { STEP_SYNC_INTERVAL_MS, useStepSync, type StepSyncDeps } from '@/modules/steps/useStepSync';
import { makeLiveChildState } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, syncSteps: jest.fn() } };
});

const syncSteps = api.syncSteps as jest.Mock;
const SERVER_ISO = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/;

type AppStateListener = (state: AppStateStatus) => void;
let appStateListeners: AppStateListener[] = [];

function makePedometer(overrides: Partial<StepSyncDeps['pedometer']> = {}) {
  let watchCallback: ((r: { steps: number }) => void) | null = null;
  const pedometer: StepSyncDeps['pedometer'] = {
    isAvailableAsync: jest.fn(async () => true),
    getPermissionsAsync: jest.fn(async () => ({ status: 'granted', granted: true, canAskAgain: true, expires: 'never' })),
    requestPermissionsAsync: jest.fn(async () => ({ status: 'granted', granted: true, canAskAgain: true, expires: 'never' })),
    getStepCountAsync: jest.fn(async () => ({ steps: 2400 })),
    watchStepCount: jest.fn((cb: (r: { steps: number }) => void) => {
      watchCallback = cb;
      return { remove: jest.fn() };
    }),
    ...overrides,
  } as unknown as StepSyncDeps['pedometer'];
  return { pedometer, emit: (steps: number) => watchCallback?.({ steps }) };
}

function memoryStore(initial: Record<string, string> = {}): KeyValueStore & { data: Record<string, string> } {
  const data = { ...initial };
  return {
    data,
    getItemAsync: jest.fn(async (key: string) => data[key] ?? null),
    setItemAsync: jest.fn(async (key: string, value: string) => {
      data[key] = value;
    }),
  };
}

function setup(deps: Partial<StepSyncDeps>, myStepsToday = 1250, enabled = true) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false, gcTime: Infinity } },
  });
  writeChildState(client, makeLiveChildState());
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  const hook = renderHook(
    (props: { mine: number; enabled: boolean }) => useStepSync({ enabled: props.enabled, myStepsToday: props.mine, deps }),
    { wrapper, initialProps: { mine: myStepsToday, enabled } },
  );
  return { client, ...hook };
}

const flush = () =>
  act(async () => {
    await jest.advanceTimersByTimeAsync(0);
  });

describe('useStepSync', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-10-04T10:00:00Z'));
    jest.clearAllMocks();
    appStateListeners = [];
    jest.spyOn(AppState, 'addEventListener').mockImplementation((_type, listener) => {
      appStateListeners.push(listener as AppStateListener);
      return { remove: jest.fn() } as unknown as ReturnType<typeof AppState.addEventListener>;
    });
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

  it('iOS: reads steps since local midnight and sends them with an ISO offset', async () => {
    const { pedometer } = makePedometer();
    const { client } = setup({ platform: 'ios', pedometer });
    await flush();
    await flush();

    const [start, end] = (pedometer.getStepCountAsync as jest.Mock).mock.calls[0] as [Date, Date];
    expect(start.getHours()).toBe(0);
    expect(start.getMinutes()).toBe(0);
    expect(end.getTime()).toBe(Date.parse('2026-10-04T10:00:00Z'));

    expect(syncSteps).toHaveBeenCalledTimes(1);
    const body = syncSteps.mock.calls[0][0] as { steps_today: number; source: string; recorded_at: string };
    expect(body.steps_today).toBe(2400);
    expect(body.source).toBe('pedometer');
    expect(body.recorded_at).toMatch(SERVER_ISO);
    expect(Date.parse(body.recorded_at)).toBe(Date.parse('2026-10-04T10:00:00Z'));
    expect(client.getQueryData<ChildPetView>(childPetKey)?.steps.steps_today).toBe(2400);
  });

  it('syncs again on foreground and every 5 minutes, only when there are new steps', async () => {
    const { pedometer } = makePedometer();
    const step = pedometer.getStepCountAsync as jest.Mock;
    const { rerender } = setup({ platform: 'ios', pedometer });
    await flush();
    await flush();
    expect(syncSteps).toHaveBeenCalledTimes(1);
    rerender({ mine: 2400, enabled: true }); // the server now has 2400

    // Same count → nothing sent.
    await act(async () => {
      await jest.advanceTimersByTimeAsync(STEP_SYNC_INTERVAL_MS);
    });
    expect(step).toHaveBeenCalledTimes(2);
    expect(syncSteps).toHaveBeenCalledTimes(1);

    // Back from the background with more steps → sent.
    step.mockResolvedValueOnce({ steps: 3100 });
    await act(async () => {
      appStateListeners.forEach((l) => l('active'));
      await jest.advanceTimersByTimeAsync(0);
    });
    await flush();
    expect(syncSteps).toHaveBeenCalledTimes(2);
    expect(syncSteps.mock.calls[1][0].steps_today).toBe(3100);
    rerender({ mine: 3100, enabled: true });

    // Next 5-minute tick with more steps.
    step.mockResolvedValueOnce({ steps: 3500 });
    await act(async () => {
      await jest.advanceTimersByTimeAsync(STEP_SYNC_INTERVAL_MS);
    });
    await flush();
    expect(syncSteps).toHaveBeenCalledTimes(3);
    expect(syncSteps.mock.calls[2][0].steps_today).toBe(3500);
  });

  it('no permission yet → nothing is read until the child allows it', async () => {
    const { pedometer } = makePedometer({
      getPermissionsAsync: jest.fn(async () => ({ status: 'undetermined', granted: false, canAskAgain: true, expires: 'never' })),
    } as Partial<StepSyncDeps['pedometer']>);
    const { result } = setup({ platform: 'ios', pedometer });
    await flush();
    expect(result.current.permission).toBe('undetermined');
    expect(pedometer.getStepCountAsync).not.toHaveBeenCalled();

    await act(async () => {
      await result.current.requestPermission();
    });
    await flush();
    await flush();
    expect(result.current.permission).toBe('granted');
    expect(syncSteps).toHaveBeenCalledTimes(1);
  });

  it('a phone without a step sensor reports unavailable and never syncs', async () => {
    const { pedometer } = makePedometer({ isAvailableAsync: jest.fn(async () => false) } as Partial<StepSyncDeps['pedometer']>);
    const { result } = setup({ platform: 'ios', pedometer });
    await flush();
    expect(result.current.permission).toBe('unavailable');
    expect(syncSteps).not.toHaveBeenCalled();
  });

  it('disabled (locked pet) → no reads, no syncs', async () => {
    const { pedometer } = makePedometer();
    setup({ platform: 'ios', pedometer }, 0, false);
    await act(async () => {
      await jest.advanceTimersByTimeAsync(STEP_SYNC_INTERVAL_MS * 2);
    });
    expect(pedometer.getStepCountAsync).not.toHaveBeenCalled();
    expect(syncSteps).not.toHaveBeenCalled();
  });

  it('a failed sync is quiet and retried on the next trigger', async () => {
    syncSteps.mockRejectedValueOnce(new TypeError('Network request failed'));
    const { pedometer } = makePedometer();
    setup({ platform: 'ios', pedometer });
    await flush();
    await flush();
    expect(syncSteps).toHaveBeenCalledTimes(1);
    await act(async () => {
      await jest.advanceTimersByTimeAsync(STEP_SYNC_INTERVAL_MS);
    });
    await flush();
    expect(syncSteps).toHaveBeenCalledTimes(2);
  });

  it('Android: live counter since midnight (saved total + sensor deltas), never history', async () => {
    const { pedometer, emit } = makePedometer();
    const store = memoryStore({ [LIVE_STEPS_KEY]: JSON.stringify({ date: '2026-10-04', steps: 1500 }) });
    const { result } = setup({ platform: 'android', pedometer, storage: store }, 1250);
    await flush();
    await flush();
    expect(pedometer.getStepCountAsync).not.toHaveBeenCalled();
    expect(pedometer.watchStepCount).toHaveBeenCalledTimes(1);

    act(() => {
      emit(40);
      emit(100);
    });
    await act(async () => {
      await result.current.syncNow();
    });
    expect(syncSteps).toHaveBeenLastCalledWith(
      expect.objectContaining({ steps_today: 1600, source: 'pedometer' }),
    );
    expect(JSON.parse(store.data[LIVE_STEPS_KEY])).toEqual({ date: '2026-10-04', steps: 1600 });
  });
});
