/**
 * M3-04 / M3-05 / M3-06: Apple Health / Health Connect as step sources in `useStepSync`
 * — source selection + fallback, max-not-sum merge, family-day boundary, throttling,
 * permission-denied path, background registration. Device clock = UTC (jest.config).
 */
import type { ReactNode } from 'react';
import { AppState, type AppStateStatus } from 'react-native';
import { act, renderHook } from '@testing-library/react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { api } from '@/api/client';
import { writeChildState } from '@/hooks/queries/useChildPet';
import { liveStepsKey, type KeyValueStore } from '@/modules/steps/stepCounter';
import { MIN_AUTO_SYNC_GAP_MS } from '@/modules/steps/todaySteps';
import { useStepSync, type StepSyncDeps } from '@/modules/steps/useStepSync';
import { useAppStore } from '@/store/appStore';
import { makeFakeHealth, type FakeHealth } from '@/test-utils/fakeHealth';
import { makeLiveChildState, makePet } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, syncSteps: jest.fn() } };
});

const syncSteps = api.syncSteps as jest.Mock;
type AppStateListener = (state: AppStateStatus) => void;
let appStateListeners: AppStateListener[] = [];

type PermissionStatus = 'granted' | 'denied' | 'undetermined';

function makePedometer(status: PermissionStatus = 'granted', steps = 2400) {
  let watchCallback: ((r: { steps: number }) => void) | null = null;
  const response = { status, granted: status === 'granted', canAskAgain: true, expires: 'never' };
  const pedometer = {
    isAvailableAsync: jest.fn(async () => true),
    getPermissionsAsync: jest.fn(async () => response),
    requestPermissionsAsync: jest.fn(async () => response),
    getStepCountAsync: jest.fn(async () => ({ steps })),
    watchStepCount: jest.fn((cb: (r: { steps: number }) => void) => {
      watchCallback = cb;
      return { remove: jest.fn() };
    }),
  } as unknown as StepSyncDeps['pedometer'];
  return { pedometer, emit: (n: number) => watchCallback?.({ steps: n }) };
}

function memoryStore(initial: Record<string, string> = {}): KeyValueStore {
  const data = { ...initial };
  return {
    getItemAsync: jest.fn(async (key: string) => data[key] ?? null),
    setItemAsync: jest.fn(async (key: string, value: string) => {
      data[key] = value;
    }),
  };
}

interface Props {
  mine: number;
  serverTime: string;
  timezone: string;
}

function setup(deps: Partial<StepSyncDeps>, initial: Partial<Props> = {}) {
  const props: Props = { mine: 0, serverTime: '2026-10-04T12:00:00+02:00', timezone: 'Europe/Ljubljana', ...initial };
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false, gcTime: Infinity } },
  });
  writeChildState(client, makeLiveChildState());
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  const registerBackground = jest.fn(async () => true);
  const hook = renderHook(
    (p: Props) =>
      useStepSync({
        enabled: true,
        myStepsToday: p.mine,
        serverTime: p.serverTime,
        timezone: p.timezone,
        deps: { registerBackground, ...deps },
      }),
    { wrapper, initialProps: props },
  );
  return { ...hook, props, registerBackground };
}

const flush = async () => {
  for (let i = 0; i < 3; i++) {
    await act(async () => {
      await jest.advanceTimersByTimeAsync(0);
    });
  }
};

const fireAppState = (state: AppStateStatus) => appStateListeners.forEach((l) => l(state));
const lastBody = () => syncSteps.mock.calls[syncSteps.mock.calls.length - 1][0] as { steps_today: number; source: string };

describe('useStepSync with Apple Health / Health Connect', () => {
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

  describe('source selection and max-merge', () => {
    it('iOS: Apple Health higher than the motion sensor → sends the Health total (not the sum), source healthkit', async () => {
      const health = makeFakeHealth({ steps: 5200 });
      const { pedometer } = makePedometer('granted', 4100);
      const { result } = setup({ platform: 'ios', pedometer, health });
      await flush();

      expect(result.current.health.status).toBe('connected');
      expect(syncSteps).toHaveBeenCalledTimes(1);
      expect(lastBody()).toEqual(expect.objectContaining({ steps_today: 5200, source: 'healthkit' }));
    });

    it('iOS: a denied Health read (0 samples) → the motion sensor total is sent', async () => {
      const health = makeFakeHealth({ steps: 0 });
      const { pedometer } = makePedometer('granted', 4100);
      setup({ platform: 'ios', pedometer, health });
      await flush();
      expect(lastBody()).toEqual(expect.objectContaining({ steps_today: 4100, source: 'pedometer' }));
    });

    it('iOS: motion permission denied but Health connected → syncs from Health alone', async () => {
      const health = makeFakeHealth({ steps: 3300 });
      const { pedometer } = makePedometer('denied');
      const { result } = setup({ platform: 'ios', pedometer, health });
      await flush();
      expect(result.current.permission).toBe('denied');
      expect(result.current.canSync).toBe(true);
      expect(pedometer.getStepCountAsync).not.toHaveBeenCalled();
      expect(lastBody()).toEqual(expect.objectContaining({ steps_today: 3300, source: 'healthkit' }));
    });

    it('Android: Health Connect history (walked while closed) beats the live counter, never added to it', async () => {
      const health = makeFakeHealth({ source: 'health_connect', steps: 7000 });
      const { pedometer, emit } = makePedometer('granted');
      const store = memoryStore({ [liveStepsKey(2)]: JSON.stringify({ date: '2026-10-04', steps: 1500 }) });
      setup({ platform: 'android', pedometer, health, storage: store });
      await flush();
      expect(lastBody()).toEqual(expect.objectContaining({ steps_today: 7000, source: 'health_connect' }));

      act(() => emit(200)); // live 1700 — still below Health Connect
      health.readSteps.mockResolvedValueOnce(7150);
      jest.setSystemTime(Date.now() + MIN_AUTO_SYNC_GAP_MS);
      await act(async () => {
        fireAppState('active');
        await jest.advanceTimersByTimeAsync(0);
      });
      await flush();
      expect(lastBody().steps_today).toBe(7150);
    });

    it('a Health read that throws falls back to the sensor this time', async () => {
      const health = makeFakeHealth({ steps: 9000 });
      health.readSteps.mockRejectedValueOnce(new Error('Protected health data is inaccessible'));
      const { pedometer } = makePedometer('granted', 2400);
      setup({ platform: 'ios', pedometer, health });
      await flush();
      expect(lastBody()).toEqual(expect.objectContaining({ steps_today: 2400, source: 'pedometer' }));
    });

    it('no health store on the device → the old sensor path, unchanged', async () => {
      const { pedometer } = makePedometer('granted', 2400);
      const { result } = setup({ platform: 'ios', pedometer, health: null });
      await flush();
      expect(result.current.health.status).toBe('unavailable');
      expect(lastBody()).toEqual(expect.objectContaining({ steps_today: 2400, source: 'pedometer' }));
    });

    it('nothing higher than the server has → nothing sent', async () => {
      const health = makeFakeHealth({ steps: 1200 });
      const { pedometer } = makePedometer('denied');
      setup({ platform: 'ios', pedometer, health }, { mine: 1200 });
      await flush();
      expect(health.readSteps).toHaveBeenCalled();
      expect(syncSteps).not.toHaveBeenCalled();
    });
  });

  describe('family-day boundary', () => {
    it('reads Health from the FAMILY midnight (New York) while the device runs on UTC', async () => {
      jest.setSystemTime(new Date('2026-10-04T02:00:00Z')); // 22:00 on 3 Oct in New York
      const health = makeFakeHealth({ steps: 800 });
      const { pedometer } = makePedometer('denied');
      setup({ platform: 'ios', pedometer, health }, { timezone: 'America/New_York', serverTime: '2026-10-03T21:59:00-04:00' });
      await flush();
      const [start, end] = health.readSteps.mock.calls[0];
      expect(start.toISOString()).toBe('2026-10-03T04:00:00.000Z');
      expect(end.toISOString()).toBe('2026-10-04T02:00:00.000Z');
    });

    it('after the family midnight yesterday’s server count is 0 and Health reads from the new midnight', async () => {
      jest.setSystemTime(new Date('2026-10-04T21:50:00Z')); // 23:50 Ljubljana
      const health = makeFakeHealth({ steps: 9000 });
      const { pedometer } = makePedometer('denied');
      setup({ platform: 'ios', pedometer, health }, { mine: 9000, serverTime: '2026-10-04T23:50:00+02:00' });
      await flush();
      expect(syncSteps).not.toHaveBeenCalled();

      jest.setSystemTime(new Date('2026-10-04T22:30:00Z')); // 00:30 on 5 Oct
      health.readSteps.mockResolvedValueOnce(150);
      await act(async () => {
        fireAppState('active');
        await jest.advanceTimersByTimeAsync(0);
      });
      await flush();
      const [start] = health.readSteps.mock.calls[1];
      expect(start.toISOString()).toBe('2026-10-04T22:00:00.000Z');
      expect(lastBody().steps_today).toBe(150);
    });
  });

  describe('throttling', () => {
    it('automatic triggers within a minute do not read again; "Osveži" (syncNow) always does', async () => {
      const health = makeFakeHealth({ steps: 1000 });
      const { pedometer } = makePedometer('denied');
      const { result } = setup({ platform: 'ios', pedometer, health });
      await flush();
      expect(health.readSteps).toHaveBeenCalledTimes(1);

      // iOS permission sheets / app switcher flap the AppState: ignored for a minute.
      await act(async () => {
        fireAppState('inactive');
        fireAppState('active');
        fireAppState('active');
        await jest.advanceTimersByTimeAsync(0);
      });
      expect(health.readSteps).toHaveBeenCalledTimes(1);

      await act(async () => {
        await result.current.syncNow();
      });
      expect(health.readSteps).toHaveBeenCalledTimes(2);

      jest.setSystemTime(Date.now() + MIN_AUTO_SYNC_GAP_MS);
      await act(async () => {
        fireAppState('active');
        await jest.advanceTimersByTimeAsync(0);
      });
      expect(health.readSteps).toHaveBeenCalledTimes(3);
    });
  });

  describe('permission flow', () => {
    it('undetermined → pre-permission card state; connect() asks for steps, then syncs and registers the iOS background task', async () => {
      const health = makeFakeHealth({ access: 'undetermined', steps: 4321 });
      const { pedometer } = makePedometer('undetermined');
      const { result, registerBackground } = setup({ platform: 'ios', pedometer, health });
      await flush();
      expect(result.current.health.status).toBe('undetermined');
      expect(result.current.canSync).toBe(false);
      expect(health.readSteps).not.toHaveBeenCalled();
      expect(health.requestAccess).not.toHaveBeenCalled(); // never prompts on its own
      expect(registerBackground).not.toHaveBeenCalled();

      await act(async () => {
        await result.current.health.connect();
      });
      await flush();
      expect(health.requestAccess).toHaveBeenCalledTimes(1);
      expect(result.current.health.status).toBe('connected');
      expect(lastBody()).toEqual(expect.objectContaining({ steps_today: 4321, source: 'healthkit' }));
      expect(registerBackground).toHaveBeenCalledTimes(1);
    });

    it('Android denied → keeps the live counter; openSettings opens Health Connect; access re-checked on foreground', async () => {
      const health = makeFakeHealth({ source: 'health_connect', access: 'undetermined', afterRequest: 'denied', steps: 5000 });
      const { pedometer, emit } = makePedometer('granted');
      const store = memoryStore({ [liveStepsKey(2)]: JSON.stringify({ date: '2026-10-04', steps: 900 }) });
      const { result, registerBackground } = setup({ platform: 'android', pedometer, health, storage: store });
      await flush();
      await act(async () => {
        await result.current.health.connect();
      });
      expect(result.current.health.status).toBe('denied');
      expect(health.readSteps).not.toHaveBeenCalled();
      act(() => emit(100));
      await act(async () => {
        await result.current.syncNow();
      });
      expect(lastBody()).toEqual(expect.objectContaining({ steps_today: 1000, source: 'pedometer' }));
      expect(registerBackground).not.toHaveBeenCalled(); // Android: no background task

      await act(async () => {
        await result.current.health.openSettings();
      });
      expect(health.openSettings).toHaveBeenCalledTimes(1);

      // The parent allowed "Steps" in Health Connect → back in the app it's a source.
      health.access.mockResolvedValue('connected');
      jest.setSystemTime(Date.now() + MIN_AUTO_SYNC_GAP_MS);
      await act(async () => {
        fireAppState('active');
        await jest.advanceTimersByTimeAsync(0);
      });
      await flush();
      expect(result.current.health.status).toBe('connected');
      await act(async () => {
        await result.current.syncNow();
      });
      expect(lastBody()).toEqual(expect.objectContaining({ steps_today: 5000, source: 'health_connect' }));
    });

    it('Health Connect missing / outdated → needs_update; openStore goes to Google Play; sensor path still works', async () => {
      const health = makeFakeHealth({ source: 'health_connect', availability: 'needs_update' });
      const { pedometer } = makePedometer('granted');
      const { result } = setup({ platform: 'android', pedometer, health, storage: memoryStore() });
      await flush();
      expect(result.current.health.status).toBe('needs_update');
      expect(health.access).not.toHaveBeenCalled();
      expect(result.current.canSync).toBe(true);
      await act(async () => {
        await result.current.health.openStore();
      });
      expect(health.openStore).toHaveBeenCalledTimes(1);
    });

    it('a health check that throws degrades to unavailable (sensor path only)', async () => {
      const health: FakeHealth = makeFakeHealth();
      health.availability.mockRejectedValue(new Error('module missing'));
      const { pedometer } = makePedometer('granted', 2000);
      const { result } = setup({ platform: 'ios', pedometer, health });
      await flush();
      expect(result.current.health.status).toBe('unavailable');
      expect(lastBody()).toEqual(expect.objectContaining({ steps_today: 2000, source: 'pedometer' }));
    });
  });
});
