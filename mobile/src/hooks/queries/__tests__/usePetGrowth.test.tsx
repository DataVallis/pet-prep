/**
 * "Album rasti" queries (M5-R04 part 2): fetched only while enabled (album open), no
 * polling, reused until 1 min before the signed URLs expire, then fetched again.
 */
import type { ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react-native';

import { api } from '@/api/client';
import { GROWTH_REFRESH_MIN_GAP_MS, childPetGrowthKey, useChildPetGrowth, useGrowthRefresh, useParentPetGrowth } from '@/hooks/queries/usePetGrowth';
import { makeTwoStageGrowth } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getChildPetGrowth: jest.fn(), getParentPetGrowth: jest.fn() } };
});

const getChildPetGrowth = api.getChildPetGrowth as jest.Mock;
const getParentPetGrowth = api.getParentPetGrowth as jest.Mock;

function wrapper() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } });
  return ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

const NOW = Date.parse('2026-10-04T09:00:00Z');

describe('usePetGrowth', () => {
  let now = NOW;
  beforeEach(() => {
    jest.clearAllMocks();
    now = NOW;
    jest.spyOn(Date, 'now').mockImplementation(() => now);
  });
  afterEach(() => jest.restoreAllMocks());

  it('child: nothing while the album is closed; one fetch when it opens', async () => {
    getChildPetGrowth.mockResolvedValue(makeTwoStageGrowth('2026-10-04T09:30:00Z'));
    const { result, rerender } = renderHook(({ open }: { open: boolean }) => useChildPetGrowth(7, open), {
      wrapper: wrapper(),
      initialProps: { open: false },
    });
    expect(getChildPetGrowth).not.toHaveBeenCalled();

    rerender({ open: true });
    await waitFor(() => expect(result.current.data?.entries).toHaveLength(2));
    expect(getChildPetGrowth).toHaveBeenCalledTimes(1);
    expect(result.current.data?.entries[1].isCurrent).toBe(true);
  });

  it('reopening before the URLs expire reuses the data; after expiry it fetches fresh URLs', async () => {
    getChildPetGrowth.mockResolvedValue(makeTwoStageGrowth('2026-10-04T09:30:00Z'));
    const { result, rerender } = renderHook(({ open }: { open: boolean }) => useChildPetGrowth(7, open), {
      wrapper: wrapper(),
      initialProps: { open: true },
    });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    rerender({ open: false });
    now = NOW + 10 * 60_000;
    rerender({ open: true });
    await waitFor(() => expect(result.current.isFetching).toBe(false));
    expect(getChildPetGrowth).toHaveBeenCalledTimes(1);

    rerender({ open: false });
    now = Date.parse('2026-10-04T09:29:30Z'); // within the 1-min lead
    rerender({ open: true });
    await waitFor(() => expect(getChildPetGrowth).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(result.current.isFetching).toBe(false));
  });

  it('parent: fetches the pet of the child detail; not without a pet', async () => {
    getParentPetGrowth.mockResolvedValue(makeTwoStageGrowth());
    const { result, rerender } = renderHook(({ petId, open }: { petId: number | null; open: boolean }) => useParentPetGrowth(petId, open), {
      wrapper: wrapper(),
      initialProps: { petId: null as number | null, open: true },
    });
    expect(getParentPetGrowth).not.toHaveBeenCalled();
    rerender({ petId: 7, open: true });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(getParentPetGrowth).toHaveBeenCalledWith(7);
  });

  it('an error is reported (the album hides the section) and not retried', async () => {
    getChildPetGrowth.mockRejectedValue(new Error('offline'));
    const { result } = renderHook(() => useChildPetGrowth(7, true), { wrapper: wrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(getChildPetGrowth).toHaveBeenCalledTimes(1);
  });

  it('refresh requests from the album are throttled to one per minute (clock-skew loop guard)', async () => {
    getChildPetGrowth.mockResolvedValue(makeTwoStageGrowth('2026-10-04T09:30:00Z'));
    const { result } = renderHook(
      () => ({ query: useChildPetGrowth(7, true), refresh: useGrowthRefresh(childPetGrowthKey(7)) }),
      { wrapper: wrapper() },
    );
    await waitFor(() => expect(result.current.query.isSuccess).toBe(true));

    act(() => result.current.refresh());
    await waitFor(() => expect(getChildPetGrowth).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(result.current.query.isFetching).toBe(false));
    act(() => result.current.refresh());
    expect(getChildPetGrowth).toHaveBeenCalledTimes(2);

    now += GROWTH_REFRESH_MIN_GAP_MS;
    act(() => result.current.refresh());
    await waitFor(() => expect(getChildPetGrowth).toHaveBeenCalledTimes(3));
    await waitFor(() => expect(result.current.query.isFetching).toBe(false));
  });

  it('child key is per pet: a new pet (after game over) never shows the old album from cache', async () => {
    getChildPetGrowth.mockResolvedValueOnce(makeTwoStageGrowth('2026-10-04T09:30:00Z'));
    const { result, rerender } = renderHook(({ petId }: { petId: number }) => useChildPetGrowth(petId, true), {
      wrapper: wrapper(),
      initialProps: { petId: 7 },
    });
    await waitFor(() => expect(result.current.data?.entries).toHaveLength(2));

    getChildPetGrowth.mockResolvedValueOnce({ pet_id: 8, growth: [], expires_at: null });
    rerender({ petId: 8 });
    // Pet 8 has its own cache entry: the old pet's pictures are not shown meanwhile.
    expect(result.current.data).toBeUndefined();
    await waitFor(() => expect(result.current.data?.petId).toBe(8));
    expect(result.current.data?.entries).toEqual([]);
    expect(getChildPetGrowth).toHaveBeenCalledTimes(2);
  });

  it("an answer for another pet (the pet changed while loading) is an error, not the old pet's album", async () => {
    getChildPetGrowth.mockResolvedValue(makeTwoStageGrowth()); // pet_id 7
    const { result } = renderHook(() => useChildPetGrowth(8, true), { wrapper: wrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(result.current.data).toBeUndefined();
  });
});
