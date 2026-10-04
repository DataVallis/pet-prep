/**
 * M1-13 / M1-15: the child state query, its websocket-dependent polling and how
 * realtime events land in the cache (ordering by emitted_at).
 */
import type { ReactNode } from 'react';
import { act, renderHook, waitFor } from '@testing-library/react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { api } from '@/api/client';
import {
  applyBroadcastToCache,
  BOUNDARY_MARGIN_MS,
  CHILD_PET_POLL_MS,
  childPetKey,
  useChildPet,
  writeChildState,
} from '@/hooks/queries/useChildPet';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { useAppStore } from '@/store/appStore';
import { makeBroadcast, makeLiveChildState } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getChildPet: jest.fn() } };
});

const getChildPet = api.getChildPet as jest.Mock;

function setup() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false, gcTime: Infinity } },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return { client, wrapper };
}

describe('useChildPet', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  it('loads GET /api/child/pet into a normalised view', async () => {
    getChildPet.mockResolvedValue(makeLiveChildState());
    const { wrapper } = setup();
    const { result } = renderHook(() => useChildPet(), { wrapper });

    await waitFor(() => expect(result.current.data).toBeDefined());
    expect(result.current.data?.pet.hunger_level).toBe(60);
    expect(result.current.data?.feeding.can_feed).toBe(false);
    expect(result.current.data?.water.can_water).toBe(true);
  });

  describe('polling fallback (fake timers)', () => {
    beforeEach(() => jest.useFakeTimers());
    afterEach(() => jest.useRealTimers());

    it('polls every 10 s while the websocket is not connected', async () => {
      getChildPet.mockResolvedValue(makeLiveChildState());
      useAppStore.getState().setWsStatus('reconnecting');
      const { wrapper } = setup();
      renderHook(() => useChildPet(), { wrapper });

      await act(async () => {
        await jest.advanceTimersByTimeAsync(0);
      });
      expect(getChildPet).toHaveBeenCalledTimes(1);

      await act(async () => {
        await jest.advanceTimersByTimeAsync(CHILD_PET_POLL_MS);
      });
      expect(getChildPet).toHaveBeenCalledTimes(2);

      await act(async () => {
        await jest.advanceTimersByTimeAsync(CHILD_PET_POLL_MS);
      });
      expect(getChildPet).toHaveBeenCalledTimes(3);
    });

    it('stops polling once the channel is live, resumes when it drops', async () => {
      getChildPet.mockResolvedValue(makeLiveChildState());
      useAppStore.getState().setWsStatus('connected');
      const { wrapper } = setup();
      renderHook(() => useChildPet(), { wrapper });

      await act(async () => {
        await jest.advanceTimersByTimeAsync(CHILD_PET_POLL_MS * 3);
      });
      expect(getChildPet).toHaveBeenCalledTimes(1);

      act(() => useAppStore.getState().setWsStatus('disconnected'));
      await act(async () => {
        await jest.advanceTimersByTimeAsync(CHILD_PET_POLL_MS);
      });
      expect(getChildPet).toHaveBeenCalledTimes(2);

      act(() => useAppStore.getState().setWsStatus('connected'));
      await act(async () => {
        await jest.advanceTimersByTimeAsync(CHILD_PET_POLL_MS * 3);
      });
      expect(getChildPet).toHaveBeenCalledTimes(2);
    });
  });

  describe('time-based refresh (M3, fake timers)', () => {
    beforeEach(() => {
      jest.useFakeTimers();
      jest.setSystemTime(new Date('2026-10-04T10:00:00Z')); // 12:00 Ljubljana
    });
    afterEach(() => jest.useRealTimers());

    it('refetches once when the water gap ends (earliest boundary), even with a live socket', async () => {
      useAppStore.getState().setWsStatus('connected');
      getChildPet.mockResolvedValue(
        makeLiveChildState({ water: { can_water: false, next_allowed_at: '2026-10-04T12:30:00+02:00' } }),
      );
      const { wrapper } = setup();
      renderHook(() => useChildPet(), { wrapper });
      await act(async () => {
        await jest.advanceTimersByTimeAsync(0);
      });
      expect(getChildPet).toHaveBeenCalledTimes(1);

      getChildPet.mockResolvedValue(makeLiveChildState({ server_time: '2026-10-04T12:30:01+02:00' })); // next: 17:00 window
      await act(async () => {
        await jest.advanceTimersByTimeAsync(30 * 60_000 - 1_000);
      });
      expect(getChildPet).toHaveBeenCalledTimes(1);
      await act(async () => {
        await jest.advanceTimersByTimeAsync(2_000 + BOUNDARY_MARGIN_MS);
      });
      expect(getChildPet).toHaveBeenCalledTimes(2);

      // Rescheduled for the 17:00 window, not repeated before it.
      await act(async () => {
        await jest.advanceTimersByTimeAsync(60 * 60_000);
      });
      expect(getChildPet).toHaveBeenCalledTimes(2);
    });

    it('the family midnight refetches too; unmount cancels the timer', async () => {
      jest.setSystemTime(new Date('2026-10-04T21:59:00Z')); // 23:59 family
      useAppStore.getState().setWsStatus('connected');
      getChildPet.mockResolvedValue(
        makeLiveChildState({ server_time: '2026-10-04T23:59:00+02:00', feeding: { next_feed_window: null } }),
      );
      const { wrapper } = setup();
      const { unmount } = renderHook(() => useChildPet(), { wrapper });
      await act(async () => {
        await jest.advanceTimersByTimeAsync(0);
      });
      await act(async () => {
        await jest.advanceTimersByTimeAsync(60_000 + BOUNDARY_MARGIN_MS);
      });
      expect(getChildPet).toHaveBeenCalledTimes(2);

      unmount();
      await act(async () => {
        await jest.advanceTimersByTimeAsync(48 * 3_600_000);
      });
      expect(getChildPet).toHaveBeenCalledTimes(2);
    });
  });

  it('a poll answered after a newer broadcast does not roll the broadcast back', async () => {
    useAppStore.getState().setWsStatus('connected');
    let resolvePoll: (value: unknown) => void = () => undefined;
    getChildPet
      .mockResolvedValueOnce(makeLiveChildState())
      .mockReturnValueOnce(new Promise((r) => (resolvePoll = r)));
    const { client, wrapper } = setup();
    const { result } = renderHook(() => useChildPet(), { wrapper });
    await waitFor(() => expect(result.current.data).toBeDefined());

    // Refetch starts (server answers with its 12:00:03 snapshot) …
    // TanStack notifies observers via setTimeout(0): let it run inside act.
    const tick = () => new Promise<void>((resolve) => setTimeout(resolve, 0));
    await act(async () => {
      void result.current.refetch();
      await tick();
    });
    // … meanwhile a broadcast from 10:00:05.250Z (12:00:05 local) arrives.
    await act(async () => {
      applyBroadcastToCache(client, makeBroadcast({ hunger_level: 33, event_type: 'metric_changed' }));
      await tick();
    });
    expect(client.getQueryData<ChildPetView>(childPetKey)?.pet.hunger_level).toBe(33);

    await act(async () => resolvePoll(makeLiveChildState({ server_time: '2026-10-04T12:00:03+02:00' })));
    expect(getChildPet).toHaveBeenCalledTimes(2);
    await waitFor(() => expect(client.getQueryState(childPetKey)?.fetchStatus).toBe('idle'));
    expect(client.getQueryData<ChildPetView>(childPetKey)?.pet.hunger_level).toBe(33);
    expect(result.current.data?.pet.hunger_level).toBe(33);
  });
});

describe('applyBroadcastToCache', () => {
  beforeEach(() => jest.clearAllMocks());

  it('drops events older than the newest one applied (emitted_at)', () => {
    const { client } = setup();
    writeChildState(client, makeLiveChildState());

    expect(applyBroadcastToCache(client, makeBroadcast({ hunger_level: 20, emitted_at: '2026-10-04T10:02:00.000Z' }))).toBe(true);
    expect(applyBroadcastToCache(client, makeBroadcast({ hunger_level: 90, emitted_at: '2026-10-04T10:01:00.000Z' }))).toBe(false);
    expect(client.getQueryData<ChildPetView>(childPetKey)?.pet.hunger_level).toBe(20);
  });

  it('non-tick events refetch the full state; plain ticks do not', async () => {
    const { client } = setup();
    writeChildState(client, makeLiveChildState());
    const invalidate = jest.spyOn(client, 'invalidateQueries');

    applyBroadcastToCache(client, makeBroadcast({ emitted_at: '2026-10-04T10:00:06.000Z' }));
    expect(invalidate).not.toHaveBeenCalled();

    applyBroadcastToCache(client, makeBroadcast({ emitted_at: '2026-10-04T10:00:07.000Z', event_type: 'fed_pet', hunger_level: 100 }));
    expect(invalidate).toHaveBeenCalledWith({ queryKey: childPetKey });
  });

  it('without a cached state it only asks for a fetch', () => {
    const { client } = setup();
    const invalidate = jest.spyOn(client, 'invalidateQueries');
    expect(applyBroadcastToCache(client, makeBroadcast())).toBe(false);
    expect(invalidate).toHaveBeenCalledWith({ queryKey: childPetKey });
  });

  it('an action response keeps the broadcast clock so older events stay dropped', () => {
    const { client } = setup();
    writeChildState(client, makeLiveChildState());
    applyBroadcastToCache(client, makeBroadcast({ emitted_at: '2026-10-04T10:00:09.000Z' }));
    writeChildState(client, makeLiveChildState({ pet: { hunger_level: 100 }, server_time: '2026-10-04T12:00:09+02:00' }));
    expect(applyBroadcastToCache(client, makeBroadcast({ hunger_level: 10, emitted_at: '2026-10-04T10:00:08.000Z' }))).toBe(false);
    expect(client.getQueryData<ChildPetView>(childPetKey)?.pet.hunger_level).toBe(100);
  });
});
