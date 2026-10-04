/**
 * M1-13 / M1-14: feed / water / clean mutations — optimistic 100 %, then the server's
 * state (success, 422 refusal, 423 lock); rollback when the answer has no state.
 */
import type { ReactNode } from 'react';
import { act, renderHook, waitFor } from '@testing-library/react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { ApiError, api } from '@/api/client';
import { useClean, useFeed, useSyncSteps, useWater } from '@/hooks/queries/useChildActions';
import { applyBroadcastToCache, childPetKey, writeChildState } from '@/hooks/queries/useChildPet';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { useAppStore } from '@/store/appStore';
import { makeBroadcast, makeLiveChildState, makePet } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, feedPet: jest.fn(), waterPet: jest.fn(), cleanPet: jest.fn(), syncSteps: jest.fn() },
  };
});

const feedPet = api.feedPet as jest.Mock;
const waterPet = api.waterPet as jest.Mock;
const cleanPet = api.cleanPet as jest.Mock;
const syncSteps = api.syncSteps as jest.Mock;

function setup() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false, gcTime: Infinity } },
  });
  // Feeding window open, a mess not yet there.
  writeChildState(client, makeLiveChildState({ feeding: { can_feed: true } }));
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  const cached = () => client.getQueryData<ChildPetView>(childPetKey);
  return { client, wrapper, cached };
}

function signInChild(id = 2) {
  useAppStore.getState().signIn({
    token: `t${id}`,
    user: { id, name: 'Maja', email: null, role: 'child' },
    pet: makePet(),
    awaitingContract: false,
  });
}

function deferred<T>() {
  let resolve: (value: T) => void = () => undefined;
  let reject: (error: unknown) => void = () => undefined;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

describe('care action mutations', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
    signInChild();
  });

  it('feed: optimistic hunger 100 while in flight, then the server state', async () => {
    const call = deferred<unknown>();
    feedPet.mockReturnValueOnce(call.promise);
    const { wrapper, cached } = setup();
    const { result } = renderHook(() => useFeed(), { wrapper });

    act(() => result.current.mutate());
    await waitFor(() => expect(cached()?.pet.hunger_level).toBe(100));
    expect(cached()?.feeding.can_feed).toBe(false);

    const serverState = makeLiveChildState({
      pet: { hunger_level: 100, thirst_level: 48 },
      feeding: { can_feed: false, fed_in_current_window: true },
      server_time: '2026-10-04T12:00:01+02:00',
    });
    await act(async () => call.resolve({ status: 'accepted', state: serverState }));
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(cached()?.pet.thirst_level).toBe(48);
    expect(cached()?.feeding.fed_in_current_window).toBe(true);
  });

  it.each([
    ['outside_feed_window', '2026-10-04T17:00:00+02:00'],
    ['already_fed_this_window', '2026-10-04T17:00:00+02:00'],
    ['needs_cleaning', null],
  ])('feed 422 %s → optimistic value replaced by the refusal state', async (reason, next) => {
    const refusalState = makeLiveChildState({ pet: { hunger_level: 58 }, feeding: { can_feed: false } });
    feedPet.mockRejectedValueOnce(
      new ApiError('refused', 422, { status: 'refused', reason, next_allowed_at: next, state: refusalState }),
    );
    const { wrapper, cached } = setup();
    const { result } = renderHook(() => useFeed(), { wrapper });

    act(() => result.current.mutate());
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(cached()?.pet.hunger_level).toBe(58);
    expect(cached()?.feeding.can_feed).toBe(false);
  });

  it.each([
    ['water_daily_limit', '2026-10-05T00:00:00+02:00'],
    ['water_too_soon', '2026-10-04T15:30:00+02:00'],
  ])('water 422 %s → refusal state with next_allowed_at in the cache', async (reason, next) => {
    const refusalState = makeLiveChildState({ water: { can_water: false, next_allowed_at: next } });
    waterPet.mockRejectedValueOnce(new ApiError('refused', 422, { status: 'refused', reason, next_allowed_at: next, state: refusalState }));
    const { wrapper, cached } = setup();
    const { result } = renderHook(() => useWater(), { wrapper });

    act(() => result.current.mutate());
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(cached()?.pet.thirst_level).toBe(50);
    expect(cached()?.water.can_water).toBe(false);
    expect(cached()?.water.next_allowed_at).toBe(next);
  });

  it.each([
    ['hard_stopped', null, { is_hard_stopped: true }],
    ['ill', '2026-10-04T18:30:00+02:00', { is_ill: true, illness_until: '2026-10-04T18:30:00+02:00' }],
    ['game_over', null, { is_game_over: true }],
    ['contract_required', null, { awaiting_contract: true }],
  ] as const)('423 %s → the locked state lands in the cache', async (reason, until, petFlags) => {
    const lockedState = makeLiveChildState({
      pet: { ...petFlags },
      lock: { is_locked: true, reason, until },
      feeding: { can_feed: false },
      water: { can_water: false },
    });
    cleanPet.mockRejectedValueOnce(new ApiError('locked', 423, { status: 'locked', reason, locked_until: until, state: lockedState }));
    const { wrapper, cached } = setup();
    const { result } = renderHook(() => useClean(), { wrapper });

    act(() => result.current.mutate());
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(cached()?.lock.reason).toBe(reason);
    expect(cached()?.pet.hygiene_level).toBe(80); // optimistic 100 rolled back by the server state
  });

  it.each([
    ['offline', new TypeError('Network request failed')],
    ['5xx', new ApiError('Server Error', 500, null)],
    ['429', new ApiError('Too Many Attempts.', 429, { message: 'Too Many Attempts.' })],
  ])('%s → optimistic change rolled back to the previous state', async (_label, error) => {
    waterPet.mockRejectedValueOnce(error);
    const { wrapper, cached } = setup();
    const { result } = renderHook(() => useWater(), { wrapper });

    act(() => result.current.mutate());
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(cached()?.pet.thirst_level).toBe(50);
    expect(cached()?.water.can_water).toBe(true);
  });

  it('M4: failure without state keeps a broadcast that landed meanwhile, restores flags, refetches', async () => {
    const call = deferred<unknown>();
    feedPet.mockReturnValueOnce(call.promise);
    const { client, wrapper, cached } = setup();
    const invalidate = jest.spyOn(client, 'invalidateQueries');
    const { result } = renderHook(() => useFeed(), { wrapper });

    act(() => result.current.mutate());
    await waitFor(() => expect(cached()?.pet.hunger_level).toBe(100));
    // A tick broadcast arrives during the request: server truth for hunger (58) and thirst (30).
    act(() => {
      applyBroadcastToCache(client, makeBroadcast({ hunger_level: 58, thirst_level: 30, emitted_at: '2026-10-04T10:00:20.000Z' }));
    });

    await act(async () => call.reject(new TypeError('Network request failed')));
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(cached()?.pet.thirst_level).toBe(30); // broadcast kept
    expect(cached()?.pet.hunger_level).toBe(58); // newer than the optimistic value → kept
    expect(cached()?.feeding.can_feed).toBe(true); // flag restored
    expect(cached()?.feeding.fed_in_current_window).toBe(false);
    expect(invalidate).toHaveBeenCalledWith({ queryKey: childPetKey });
  });

  it('M4: without a broadcast in between the metric itself is restored from before the tap', async () => {
    waterPet.mockRejectedValueOnce(new ApiError('Server Error', 503, null));
    const { client, wrapper, cached } = setup();
    const { result } = renderHook(() => useWater(), { wrapper });
    act(() => result.current.mutate());
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(cached()?.pet.thirst_level).toBe(50);
    expect(cached()?.water.can_water).toBe(true);
    expect(client.getQueryState(childPetKey)?.isInvalidated).toBe(true);
  });

  it('m5: an answer that arrives after the session changed is not written', async () => {
    const call = deferred<unknown>();
    feedPet.mockReturnValueOnce(call.promise);
    const { wrapper, cached } = setup();
    const { result } = renderHook(() => useFeed(), { wrapper });
    act(() => result.current.mutate());
    await waitFor(() => expect(cached()?.pet.hunger_level).toBe(100));

    act(() => signInChild(3)); // another child signs in on this phone
    await act(async () =>
      call.resolve({ status: 'accepted', state: makeLiveChildState({ pet: { thirst_level: 11 } }) }),
    );
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(cached()?.pet.thirst_level).toBe(50);
  });

  it('clean: success with status unchanged still replaces the cache', async () => {
    cleanPet.mockResolvedValueOnce({ status: 'unchanged', state: makeLiveChildState({ pet: { hygiene_level: 100 } }) });
    const { wrapper, cached } = setup();
    const { result } = renderHook(() => useClean(), { wrapper });

    act(() => result.current.mutate());
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.status).toBe('unchanged');
    expect(cached()?.pet.hygiene_level).toBe(100);
  });
});

describe('useSyncSteps', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
    signInChild();
  });

  it.each(['accepted', 'capped', 'rejected', 'unchanged', 'stale'] as const)(
    '%s → state from the response replaces the cache',
    async (status) => {
      syncSteps.mockResolvedValueOnce({
        status,
        accepted_steps: 0,
        steps_today: 2000,
        energy_level: 50,
        state: makeLiveChildState({ steps: { steps_today: 2000, my_steps_today: 2000, energy_level: 50 } }),
      });
      const { wrapper, cached } = setup();
      const { result } = renderHook(() => useSyncSteps(), { wrapper });

      act(() => result.current.mutate({ stepsToday: 2100, recordedAt: '2026-10-04T12:05:00+02:00' }));
      await waitFor(() => expect(result.current.isSuccess).toBe(true));
      expect(syncSteps).toHaveBeenCalledWith({
        steps_today: 2100,
        source: 'pedometer',
        recorded_at: '2026-10-04T12:05:00+02:00',
      });
      expect(cached()?.steps.steps_today).toBe(2000);
    },
  );

  it('m5: a step answer after logout is not written', async () => {
    const call = deferred<unknown>();
    syncSteps.mockReturnValueOnce(call.promise);
    const { wrapper, cached } = setup();
    const { result } = renderHook(() => useSyncSteps(), { wrapper });
    act(() => result.current.mutate({ stepsToday: 9000, recordedAt: '2026-10-04T12:05:00+02:00' }));
    act(() => useAppStore.getState().reset());
    await act(async () =>
      call.resolve({ status: 'accepted', accepted_steps: 9000, steps_today: 9000, energy_level: 100, state: makeLiveChildState({ steps: { steps_today: 9000 } }) }),
    );
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(cached()?.steps.steps_today).toBe(1250);
  });

  it('423 while locked → locked state cached, no throw outside the mutation', async () => {
    const lockedState = makeLiveChildState({ pet: { is_hard_stopped: true }, lock: { is_locked: true, reason: 'hard_stopped' } });
    syncSteps.mockRejectedValueOnce(new ApiError('locked', 423, { reason: 'hard_stopped', state: lockedState }));
    const { wrapper, cached } = setup();
    const { result } = renderHook(() => useSyncSteps(), { wrapper });

    act(() => result.current.mutate({ stepsToday: 10, recordedAt: '2026-10-04T12:05:00+02:00' }));
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(cached()?.lock.reason).toBe('hard_stopped');
  });
});
