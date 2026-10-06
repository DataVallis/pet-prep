/**
 * Hotfix 2026-10-06 — TestFlight 1.24.4 incident: free legacy mutt (pet 3) at the vet
 * until 10:41:01 Ljubljana, `GET /api/child/pet` answered 429 (throttle:api, 60/min per
 * user), then the dead "Kužka ni bilo mogoče naložiti" screen.
 *
 * These tests count `api.getChildPet` calls per minute with fake timers around the end
 * of the vet visit (the server keeps answering "ill" for a while after it), under every
 * automatic trigger at once, and check the 429 / error recovery.
 */
import type { ReactNode } from 'react';
import { act, renderHook } from '@testing-library/react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { ApiError, api, type ChildPetState } from '@/api/client';
import { childPetKey, useChildPet } from '@/hooks/queries/useChildPet';
import * as childPetView from '@/modules/childPet/childPetView';
import { useAppStore } from '@/store/appStore';
import incident from '@/test-utils/incidentState20261006.json';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getChildPet: jest.fn() } };
});

const getChildPet = api.getChildPet as jest.Mock;

const ILLNESS_END = '2026-10-06T10:41:01+02:00';
const ILLNESS_END_MS = Date.parse(ILLNESS_END);

/** Server clock as the family-offset ISO string the API sends (whole seconds). */
function serverIso(ms: number): string {
  return new Date(Math.floor(ms / 1000) * 1000 + 2 * 3_600_000).toISOString().replace('.000Z', '+02:00');
}

/**
 * The real post-recovery payload (redacted), or the in-illness payload David saw before:
 * locked "ill" until 10:41:01, sick, hygiene 0 (mess), thirst / energy 0, escalation 2.
 */
function incidentState(nowMs: number, ill: boolean): ChildPetState {
  const base = JSON.parse(JSON.stringify(incident)) as ChildPetState & { pet: Record<string, unknown> };
  base.server_time = serverIso(nowMs);
  if (!ill) return base;
  return {
    ...base,
    pet: {
      ...base.pet,
      hunger_level: 25,
      hygiene_level: 0,
      needs_cleaning: true,
      pet_state: 'sick',
      is_ill: true,
      illness_until: ILLNESS_END,
    },
    lock: { is_locked: true, reason: 'ill', until: ILLNESS_END },
    water: { ...base.water, can_water: false },
  } as unknown as ChildPetState;
}

function setup() {
  const client = new QueryClient({
    defaultOptions: { queries: { gcTime: Infinity, staleTime: 30_000 }, mutations: { retry: false, gcTime: Infinity } },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return { client, wrapper };
}

/** Advance `minutes` minutes in 1 s steps (running `each` every second); GET calls per minute. */
async function countPerMinute(minutes: number, each?: (second: number) => void): Promise<number[]> {
  const perMinute: number[] = [];
  let second = 0;
  for (let m = 0; m < minutes; m++) {
    const before = getChildPet.mock.calls.length;
    for (let i = 0; i < 60; i++, second++) {
      await act(async () => {
        each?.(second);
        await jest.advanceTimersByTimeAsync(1_000);
      });
    }
    perMinute.push(getChildPet.mock.calls.length - before);
  }
  return perMinute;
}

describe('useChildPet — refetch limits (hotfix 2026-10-06)', () => {
  beforeEach(() => {
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    // 10:40:00 Ljubljana: one minute before the vet visit ends.
    jest.setSystemTime(new Date('2026-10-06T08:40:00Z'));
    getChildPet.mockReset();
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  afterEach(() => {
    jest.restoreAllMocks();
    jest.useRealTimers();
  });

  it.each(['connected', 'reconnecting'] as const)(
    'incident: vet visit ends while the server still says "ill" for 20 s → ≤ 10 GETs per minute, unlocked after (%s)',
    async (ws) => {
      useAppStore.getState().setWsStatus(ws);
      getChildPet.mockImplementation(async () => {
        const now = Date.now();
        return incidentState(now, now < ILLNESS_END_MS + 20_000);
      });
      const { wrapper } = setup();
      const { result } = renderHook(() => useChildPet(), { wrapper });

      const perMinute = await countPerMinute(5);
      for (const n of perMinute) expect(n).toBeLessThanOrEqual(10);
      // The illness end is a boundary now: back to an unlocked HUD within a minute, even
      // without the recovery broadcast (websocket "connected" but silent).
      expect(result.current.data?.lock.is_locked).toBe(false);
      expect(result.current.data?.pet.hygiene_level).toBe(100);
    },
  );

  it('defence in depth: even a boundary that is "due now" on every answer stays ≤ 2 GETs per minute', async () => {
    useAppStore.getState().setWsStatus('connected');
    jest.spyOn(childPetView, 'nextRefreshDelay').mockReturnValue(0);
    getChildPet.mockImplementation(async () => incidentState(Date.now(), true));
    const { wrapper } = setup();
    renderHook(() => useChildPet(), { wrapper });

    const perMinute = await countPerMinute(6);
    expect(Math.max(...perMinute)).toBeLessThanOrEqual(3);
    // The state never changes → the gap grows (30 s, 60 s, 120 s, 240 s, 300 s).
    expect(perMinute.slice(3).reduce((a, b) => a + b, 0)).toBeLessThanOrEqual(2);
  });

  it('defence in depth: an invalidation storm (every 200 ms) is capped by the fetch gate', async () => {
    useAppStore.getState().setWsStatus('reconnecting');
    getChildPet.mockImplementation(async () => incidentState(Date.now(), true));
    const { client, wrapper } = setup();
    renderHook(() => useChildPet(), { wrapper });

    const perMinute = await countPerMinute(3, () => {
      for (let i = 0; i < 5; i++) void client.invalidateQueries({ queryKey: childPetKey });
    });
    expect(perMinute[0]).toBeLessThanOrEqual(13);
    expect(perMinute[1]).toBeLessThanOrEqual(10);
    expect(perMinute[2]).toBeLessThanOrEqual(10);
  });

  it('429 with a cached view: the view stays, nothing is asked before Retry-After, then it recovers', async () => {
    useAppStore.getState().setWsStatus('connected');
    getChildPet.mockImplementation(async () => incidentState(Date.now(), true));
    const { client, wrapper } = setup();
    const { result } = renderHook(() => useChildPet(), { wrapper });
    await act(async () => {
      await jest.advanceTimersByTimeAsync(0);
    });
    expect(result.current.data?.lock.reason).toBe('ill');

    getChildPet.mockRejectedValueOnce(new ApiError('Too Many Attempts.', 429, undefined, 20));
    await act(async () => {
      void client.invalidateQueries({ queryKey: childPetKey });
      await jest.advanceTimersByTimeAsync(0);
    });
    expect(getChildPet).toHaveBeenCalledTimes(2);
    // Retrying (not failed): the HUD keeps the last view and shows no error.
    expect(result.current.isError).toBe(false);
    expect(result.current.data?.lock.reason).toBe('ill');

    await act(async () => {
      await jest.advanceTimersByTimeAsync(19_000);
    });
    expect(getChildPet).toHaveBeenCalledTimes(2);
    await act(async () => {
      await jest.advanceTimersByTimeAsync(1_500);
    });
    expect(getChildPet).toHaveBeenCalledTimes(3);
    expect(result.current.isError).toBe(false);
    expect(result.current.isSuccess).toBe(true);
  });

  it('no cached view and the server keeps refusing: error state, then it keeps retrying on its own and recovers', async () => {
    useAppStore.getState().setWsStatus('connected');
    getChildPet.mockRejectedValue(new ApiError('Too Many Attempts.', 429, undefined, 10));
    const { wrapper } = setup();
    const { result } = renderHook(() => useChildPet(), { wrapper });

    // First try + 2 retries, 10 s apart (Retry-After).
    await act(async () => {
      await jest.advanceTimersByTimeAsync(21_000);
    });
    expect(getChildPet).toHaveBeenCalledTimes(3);
    expect(result.current.isError).toBe(true);
    expect(result.current.data).toBeUndefined();

    // The hook retries by itself (Retry-After, at least 15 s) — no button press needed.
    getChildPet.mockImplementation(async () => incidentState(Date.now(), false));
    await act(async () => {
      await jest.advanceTimersByTimeAsync(16_000);
    });
    expect(getChildPet).toHaveBeenCalledTimes(4);
    expect(result.current.isError).toBe(false);
    expect(result.current.data?.lock.is_locked).toBe(false);
  });

  it('auth errors are not retried (the 401 handler logs out)', async () => {
    useAppStore.getState().setWsStatus('connected');
    getChildPet.mockRejectedValue(new ApiError('Unauthenticated.', 401));
    const { wrapper } = setup();
    const { result } = renderHook(() => useChildPet(), { wrapper });
    await act(async () => {
      await jest.advanceTimersByTimeAsync(5 * 60_000);
    });
    expect(getChildPet).toHaveBeenCalledTimes(1);
    expect(result.current.isError).toBe(true);
  });
});
