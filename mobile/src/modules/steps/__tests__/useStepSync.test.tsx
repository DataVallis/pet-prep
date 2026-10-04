/**
 * M1-14 step sync: today's steps → POST /api/child/pet/steps (ISO with offset), on
 * start, on foreground and every 5 minutes; Android from the live counter.
 * "Today" = the FAMILY-local day (PR #18 review M1 / B1): the device here runs on UTC.
 */
import type { ReactNode } from 'react';
import { AppState, type AppStateStatus } from 'react-native';
import { act, renderHook } from '@testing-library/react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { api } from '@/api/client';
import { childPetKey, writeChildState } from '@/hooks/queries/useChildPet';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { liveStepsKey, type KeyValueStore } from '@/modules/steps/stepCounter';
import { STEP_SYNC_INTERVAL_MS, useStepSync, type StepSyncDeps } from '@/modules/steps/useStepSync';
import { useAppStore } from '@/store/appStore';
import { makeLiveChildState, makePet } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, syncSteps: jest.fn() } };
});

const syncSteps = api.syncSteps as jest.Mock;
const SERVER_ISO = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/;
const KEY = liveStepsKey(2);

type AppStateListener = (state: AppStateStatus) => void;
let appStateListeners: AppStateListener[] = [];

function makePedometer(overrides: Partial<StepSyncDeps['pedometer']> = {}) {
  let watchCallback: ((r: { steps: number }) => void) | null = null;
  const pedometer = {
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

interface Props {
  mine: number;
  enabled: boolean;
  serverTime: string;
  timezone: string;
}

function setup(deps: Partial<StepSyncDeps>, initial: Partial<Props> = {}) {
  const props: Props = {
    mine: 1250,
    enabled: true,
    serverTime: '2026-10-04T12:00:00+02:00',
    timezone: 'Europe/Ljubljana',
    ...initial,
  };
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false, gcTime: Infinity } },
  });
  writeChildState(client, makeLiveChildState());
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  const hook = renderHook(
    (p: Props) =>
      useStepSync({ enabled: p.enabled, myStepsToday: p.mine, serverTime: p.serverTime, timezone: p.timezone, deps }),
    { wrapper, initialProps: props },
  );
  return { client, props, ...hook };
}

const flush = () =>
  act(async () => {
    await jest.advanceTimersByTimeAsync(0);
  });

function fireAppState(state: AppStateStatus) {
  appStateListeners.forEach((l) => l(state));
}

describe('useStepSync', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-10-04T10:00:00Z'));
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({
      token: 't',
      user: { id: 2, name: 'Maja', email: null, role: 'child' },
      pet: makePet(),
      awaitingContract: false,
    });
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

  it('the device clock really is UTC here', () => {
    expect(new Date('2026-10-04T10:00:00Z').getTimezoneOffset()).toBe(0);
  });

  it('iOS, family Ljubljana: steps since the FAMILY midnight (22:00Z the day before), ISO offset payload', async () => {
    const { pedometer } = makePedometer();
    const { client } = setup({ platform: 'ios', pedometer });
    await flush();
    await flush();

    const [start, end] = (pedometer.getStepCountAsync as jest.Mock).mock.calls[0] as [Date, Date];
    expect(start.toISOString()).toBe('2026-10-03T22:00:00.000Z');
    expect(end.getTime()).toBe(Date.parse('2026-10-04T10:00:00Z'));

    expect(syncSteps).toHaveBeenCalledTimes(1);
    const body = syncSteps.mock.calls[0][0] as { steps_today: number; source: string; recorded_at: string };
    expect(body.steps_today).toBe(2400);
    expect(body.source).toBe('pedometer');
    expect(body.recorded_at).toMatch(SERVER_ISO);
    expect(Date.parse(body.recorded_at)).toBe(Date.parse('2026-10-04T10:00:00Z'));
    expect(client.getQueryData<ChildPetView>(childPetKey)?.steps.steps_today).toBe(2400);
  });

  it('iOS, family New York: at 02:00Z the family day is still 3 Oct → from 04:00Z on 3 Oct', async () => {
    jest.setSystemTime(new Date('2026-10-04T02:00:00Z'));
    const { pedometer } = makePedometer();
    setup(
      { platform: 'ios', pedometer },
      { timezone: 'America/New_York', serverTime: '2026-10-03T21:59:00-04:00', mine: 0 },
    );
    await flush();
    await flush();
    const [start] = (pedometer.getStepCountAsync as jest.Mock).mock.calls[0] as [Date, Date];
    expect(start.toISOString()).toBe('2026-10-03T04:00:00.000Z');
  });

  it('syncs again on foreground and every 5 minutes, only when there are new steps', async () => {
    const { pedometer } = makePedometer();
    const step = pedometer.getStepCountAsync as jest.Mock;
    const { rerender, props } = setup({ platform: 'ios', pedometer });
    await flush();
    await flush();
    expect(syncSteps).toHaveBeenCalledTimes(1);
    rerender({ ...props, mine: 2400 }); // the server now has 2400

    await act(async () => {
      await jest.advanceTimersByTimeAsync(STEP_SYNC_INTERVAL_MS);
    });
    expect(step).toHaveBeenCalledTimes(2);
    expect(syncSteps).toHaveBeenCalledTimes(1);

    step.mockResolvedValueOnce({ steps: 3100 });
    await act(async () => {
      fireAppState('active');
      await jest.advanceTimersByTimeAsync(0);
    });
    await flush();
    expect(syncSteps).toHaveBeenCalledTimes(2);
    expect(syncSteps.mock.calls[1][0].steps_today).toBe(3100);
    rerender({ ...props, mine: 3100 });

    step.mockResolvedValueOnce({ steps: 3500 });
    await act(async () => {
      await jest.advanceTimersByTimeAsync(STEP_SYNC_INTERVAL_MS);
    });
    await flush();
    expect(syncSteps).toHaveBeenCalledTimes(3);
    expect(syncSteps.mock.calls[2][0].steps_today).toBe(3500);
  });

  it('B1 iOS: after the family midnight a cached count from yesterday never blocks today’s steps', async () => {
    // Cached state from 23:50 family time with 5000 steps yesterday.
    const { pedometer } = makePedometer({ getStepCountAsync: jest.fn(async () => ({ steps: 5000 })) } as Partial<
      StepSyncDeps['pedometer']
    >);
    jest.setSystemTime(new Date('2026-10-04T21:50:00Z'));
    setup({ platform: 'ios', pedometer }, { serverTime: '2026-10-04T23:50:00+02:00', mine: 5000 });
    await flush();
    await flush();
    expect(syncSteps).not.toHaveBeenCalled(); // same day, nothing new

    // Midnight passes in the background; 300 steps after it.
    jest.setSystemTime(new Date('2026-10-04T22:30:00Z')); // 00:30 on 5 Oct in Ljubljana
    (pedometer.getStepCountAsync as jest.Mock).mockResolvedValueOnce({ steps: 300 });
    await act(async () => {
      fireAppState('background');
      fireAppState('active');
      await jest.advanceTimersByTimeAsync(0);
    });
    await flush();
    const [start] = (pedometer.getStepCountAsync as jest.Mock).mock.calls[1] as [Date, Date];
    expect(start.toISOString()).toBe('2026-10-04T22:00:00.000Z');
    expect(syncSteps).toHaveBeenCalledTimes(1);
    expect(syncSteps.mock.calls[0][0].steps_today).toBe(300);
  });

  it('B1 Android: midnight while backgrounded → foreground → no yesterday steps credited', async () => {
    jest.setSystemTime(new Date('2026-10-04T21:50:00Z')); // 23:50 family
    const { pedometer, emit } = makePedometer();
    const store = memoryStore({ [KEY]: JSON.stringify({ date: '2026-10-04', steps: 3000 }) });
    setup(
      { platform: 'android', pedometer, storage: store },
      { serverTime: '2026-10-04T23:50:00+02:00', mine: 3000 },
    );
    await flush();
    await flush();
    expect(syncSteps).not.toHaveBeenCalled();

    act(() => fireAppState('background'));
    jest.setSystemTime(new Date('2026-10-04T22:30:00Z')); // 00:30 on 5 Oct, state still yesterday's
    act(() => emit(120)); // walking after midnight, delivered on return
    await act(async () => {
      fireAppState('active');
      await jest.advanceTimersByTimeAsync(0);
    });
    await flush();

    expect(syncSteps).toHaveBeenCalledTimes(1);
    expect(syncSteps.mock.calls[0][0].steps_today).toBe(120);
    expect(Date.parse(syncSteps.mock.calls[0][0].recorded_at)).toBe(Date.parse('2026-10-04T22:30:00Z'));
    expect(JSON.parse(store.data[KEY])).toEqual({ date: '2026-10-05', steps: 120 });
  });

  it('Android: live counter (saved total + sensor deltas) under the child’s own key, never history', async () => {
    const { pedometer, emit } = makePedometer();
    const store = memoryStore({ [KEY]: JSON.stringify({ date: '2026-10-04', steps: 1500 }) });
    const { result } = setup({ platform: 'android', pedometer, storage: store });
    await flush();
    await flush();
    expect(pedometer.getStepCountAsync).not.toHaveBeenCalled();
    expect(store.getItemAsync).toHaveBeenCalledWith('petprep_live_steps_today_2');

    act(() => {
      emit(40);
      emit(100);
    });
    await act(async () => {
      await result.current.syncNow();
    });
    expect(syncSteps).toHaveBeenLastCalledWith(expect.objectContaining({ steps_today: 1600, source: 'pedometer' }));
    await flush();
    expect(JSON.parse(store.data[KEY])).toEqual({ date: '2026-10-04', steps: 1600 });
  });

  it('N3: after logout deleted the key, the HUD unmount does not write it back', async () => {
    const { pedometer, emit } = makePedometer();
    const store = memoryStore({ [KEY]: JSON.stringify({ date: '2026-10-04', steps: 1500 }) });
    const { unmount } = setup({ platform: 'android', pedometer, storage: store }, { mine: 1500 });
    await flush();
    await flush();
    act(() => emit(80));
    (store.setItemAsync as jest.Mock).mockClear();

    // logout(): delete the child's key, then reset the session; the HUD unmounts afterwards.
    delete store.data[KEY];
    act(() => useAppStore.getState().reset());
    unmount();
    await flush();

    expect(store.setItemAsync).not.toHaveBeenCalled();
    expect(store.data[KEY]).toBeUndefined();
  });

  it('N3 control: a signed-in unmount (leaving the HUD) still saves the total', async () => {
    const { pedometer, emit } = makePedometer();
    const store = memoryStore({ [KEY]: JSON.stringify({ date: '2026-10-04', steps: 1500 }) });
    const { unmount } = setup({ platform: 'android', pedometer, storage: store }, { mine: 1580 });
    await flush();
    await flush();
    act(() => emit(80));
    unmount();
    await flush();
    expect(JSON.parse(store.data[KEY])).toEqual({ date: '2026-10-04', steps: 1660 }); // server 1580 + 80 live
  });

  it('m2: a new counter loads only after the previous counter’s persist finished', async () => {
    const { pedometer, emit } = makePedometer();
    const store = memoryStore({ [KEY]: JSON.stringify({ date: '2026-10-04', steps: 1000 }) });
    let releasePersist: () => void = () => undefined;
    (store.setItemAsync as jest.Mock).mockImplementationOnce(
      (key: string, value: string) =>
        new Promise<void>((resolve) => {
          releasePersist = () => {
            store.data[key] = value;
            resolve();
          };
        }),
    );
    const { rerender, props } = setup({ platform: 'android', pedometer, storage: store }, { mine: 1000 });
    await flush();
    await flush();
    act(() => emit(50)); // 1050 in memory

    rerender({ ...props, enabled: false }); // cleanup → slow persist(1050)
    rerender({ ...props, enabled: true }); // new counter must wait for it
    await flush();
    expect(store.getItemAsync).toHaveBeenCalledTimes(1);

    await act(async () => {
      releasePersist();
      await jest.advanceTimersByTimeAsync(0);
    });
    await flush();
    expect(store.getItemAsync).toHaveBeenCalledTimes(2);
    expect(syncSteps).toHaveBeenLastCalledWith(expect.objectContaining({ steps_today: 1050 }));
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
    setup({ platform: 'ios', pedometer }, { mine: 0, enabled: false });
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
});
