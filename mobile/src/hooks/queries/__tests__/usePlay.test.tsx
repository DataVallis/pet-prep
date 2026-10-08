/**
 * M5-R05 `usePlay`: optimistic happy dog while in flight, then ALWAYS the server's state
 * (200, 422 `play_not_available`, 423 lock); a lost connection is retried, and without
 * any server answer the optimistic `play` is undone.
 */
import type { ReactNode } from 'react';
import { act, renderHook, waitFor } from '@testing-library/react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { ApiError, api } from '@/api/client';
import { PLAY_RETRIES, PlayResponseError, shouldRetryPlay, usePlay } from '@/hooks/queries/usePlay';
import { childPetKey, writeChildState } from '@/hooks/queries/useChildPet';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { useAppStore } from '@/store/appStore';
import { makeLiveChildState, makePet, makePlayState } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, playWithPet: jest.fn() } };
});

const playWithPet = api.playWithPet as jest.Mock;
const INVITE = { id: 3, kind: 'play' as const, expires_at: '2099-01-01T00:00:00+00:00' };

function setup() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false, gcTime: Infinity } },
  });
  writeChildState(client, makeLiveChildState({ play: makePlayState({ invitation: INVITE }) }));
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  const cached = () => client.getQueryData<ChildPetView>(childPetKey);
  return { client, wrapper, cached };
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

describe('usePlay', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    playWithPet.mockReset();
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({
      token: 't2',
      user: { id: 2, name: 'Maja', email: null, role: 'child' },
      pet: makePet(),
      awaitingContract: false,
    });
  });

  it('optimistic happy + invitation done while in flight, then the server state', async () => {
    const call = deferred<unknown>();
    playWithPet.mockReturnValueOnce(call.promise);
    const { wrapper, cached } = setup();
    const { result } = renderHook(() => usePlay(), { wrapper });

    act(() => result.current.mutate('play'));
    await waitFor(() => expect(cached()?.play?.mood.scene).toBe('playing'));
    expect(cached()?.play?.invitation).toBeNull();
    expect(playWithPet).toHaveBeenCalledWith('play');

    const serverState = makeLiveChildState({
      play: makePlayState({ mood: { happy_until: '2026-10-04T12:30:00+02:00', scene: 'playing' } }),
    });
    await act(async () => call.resolve({ status: 'accepted', play: { kind: 'play', source: 'invitation' }, state: serverState }));
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.play).toEqual({ kind: 'play', source: 'invitation' });
    expect(cached()?.play?.mood.happy_until).toBe('2026-10-04T12:30:00+02:00');
  });

  it('422 play_not_available: the refusal state replaces the optimistic one', async () => {
    const refusedState = makeLiveChildState({ play: makePlayState({ can_play: false }), pet: { pet_state: 'sleeping' } });
    playWithPet.mockRejectedValueOnce(
      new ApiError('refused', 422, { status: 'refused', reason: 'play_not_available', next_allowed_at: '2026-10-05T07:00:00+02:00', state: refusedState }),
    );
    const { wrapper, cached } = setup();
    const { result } = renderHook(() => usePlay(), { wrapper });
    act(() => result.current.mutate('cuddle'));
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(cached()?.play?.can_play).toBe(false);
    expect(cached()?.play?.mood.happy_until).toBeNull();
    expect(playWithPet).toHaveBeenCalledTimes(1);
  });

  it('423 lock: the lock state lands in the cache', async () => {
    const lockedState = makeLiveChildState({ play: null, lock: { is_locked: true, reason: 'hard_stopped' }, pet: { is_hard_stopped: true } });
    playWithPet.mockRejectedValueOnce(new ApiError('locked', 423, { status: 'locked', reason: 'hard_stopped', locked_until: null, state: lockedState }));
    const { wrapper, cached } = setup();
    const { result } = renderHook(() => usePlay(), { wrapper });
    act(() => result.current.mutate('play'));
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(cached()?.lock.reason).toBe('hard_stopped');
    expect(cached()?.play).toBeNull();
  });

  it('a lost connection is retried, then the optimistic play is undone', async () => {
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    try {
      playWithPet.mockRejectedValue(new TypeError('Network request failed'));
      const { wrapper, cached } = setup();
      const before = cached()?.play;
      const { result } = renderHook(() => usePlay(), { wrapper });
      act(() => result.current.mutate('play'));
      await act(async () => {
        await jest.advanceTimersByTimeAsync(10_000);
      });
      expect(playWithPet).toHaveBeenCalledTimes(1 + PLAY_RETRIES);
      expect(result.current.isError).toBe(true);
      expect(cached()?.play).toEqual(before);
    } finally {
      jest.useRealTimers();
    }
  });

  it('retry only after a lost connection', () => {
    expect(shouldRetryPlay(0, new TypeError('offline'))).toBe(true);
    expect(shouldRetryPlay(PLAY_RETRIES, new TypeError('offline'))).toBe(false);
    expect(shouldRetryPlay(0, new ApiError('x', 422, {}))).toBe(false);
    expect(shouldRetryPlay(0, new ApiError('x', 500, {}))).toBe(false);
    expect(shouldRetryPlay(0, new PlayResponseError(null))).toBe(false);
  });

  it('an unexpected 200 body with a state still lands; the session changed → ignored', async () => {
    const state = makeLiveChildState({ play: makePlayState({ can_play: false }) });
    playWithPet.mockResolvedValueOnce({ weird: true, state });
    const { wrapper, cached } = setup();
    const { result } = renderHook(() => usePlay(), { wrapper });
    act(() => result.current.mutate('play'));
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(cached()?.play?.can_play).toBe(false);

    // Another child signs in while a request is in flight: its answer is not written.
    const call = deferred<unknown>();
    playWithPet.mockReturnValueOnce(call.promise);
    act(() => result.current.mutate('play'));
    await waitFor(() => expect(playWithPet).toHaveBeenCalledTimes(2));
    useAppStore.getState().signIn({ token: 't9', user: { id: 9, name: 'Ana', email: null, role: 'child' }, pet: makePet(), awaitingContract: false });
    const other = makeLiveChildState({ play: makePlayState({ mood: { happy_until: '2026-10-04T13:00:00+02:00', scene: 'playing' } }) });
    await act(async () => call.resolve({ status: 'accepted', play: { kind: 'play', source: 'free' }, state: other }));
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(cached()?.play?.mood.happy_until).not.toBe('2026-10-04T13:00:00+02:00');
  });
});
