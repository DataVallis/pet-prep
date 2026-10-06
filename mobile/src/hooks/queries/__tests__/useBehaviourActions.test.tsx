/**
 * M5-R02: "Pelji ven" / "Pospravi in daj igračo" mutations — optimistic, then ALWAYS the
 * server's state (200, 422 take_out_not_needed with state); rollback without an answer.
 */
import type { ReactNode } from 'react';
import { act, renderHook, waitFor } from '@testing-library/react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { ApiError, api } from '@/api/client';
import { useResolveChewing, useTakeOut } from '@/hooks/queries/useChildActions';
import { childPetKey, writeChildState } from '@/hooks/queries/useChildPet';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { useAppStore } from '@/store/appStore';
import { makeBehaviourEvent, makeLiveChildState, makePet, makeTakeOut } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, takeOutPet: jest.fn(), resolveChewing: jest.fn(), getChildPet: jest.fn() } };
});

const takeOutPet = api.takeOutPet as jest.Mock;
const resolveChewing = api.resolveChewing as jest.Mock;
const getChildPet = api.getChildPet as jest.Mock;

function setup(state = makeLiveChildState({ behaviour: { take_out: makeTakeOut(), can_take_out: true } })) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false, gcTime: Infinity } },
  });
  writeChildState(client, state);
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  return { client, wrapper, cached: () => client.getQueryData<ChildPetView>(childPetKey) };
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

describe('behaviour action mutations', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({
      token: 't2',
      user: { id: 2, name: 'Maja', email: null, role: 'child' },
      pet: makePet(),
      awaitingContract: false,
    });
  });

  it('take-out: clock restarts at once, then the server state', async () => {
    const call = deferred<unknown>();
    takeOutPet.mockReturnValueOnce(call.promise);
    const { wrapper, cached } = setup();
    const before = cached()?.behaviour.take_out?.clock_started_at;
    const { result } = renderHook(() => useTakeOut(), { wrapper });

    act(() => result.current.mutate());
    await waitFor(() => expect(cached()?.behaviour.take_out?.clock_started_at).not.toBe(before));

    const server = makeLiveChildState({
      behaviour: { take_out: makeTakeOut({ next_due_at: '2026-10-04T14:00:00+02:00' }), can_take_out: true },
    });
    await act(async () => call.resolve({ status: 'accepted', state: server }));
    await waitFor(() => expect(cached()?.behaviour.take_out?.next_due_at).toBe('2026-10-04T14:00:00+02:00'));
  });

  it('take-out 422 take_out_not_needed: the refusal state replaces the cache', async () => {
    const refusalState = makeLiveChildState({ behaviour: { take_out: null, can_take_out: false } });
    takeOutPet.mockRejectedValueOnce(new ApiError('x', 422, { reason: 'take_out_not_needed', state: refusalState }));
    const { wrapper, cached } = setup();
    const { result } = renderHook(() => useTakeOut(), { wrapper });

    act(() => result.current.mutate());
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(cached()?.behaviour.take_out).toBeNull();
    expect(cached()?.behaviour.can_take_out).toBe(false);
  });

  it('take-out offline: clock restored, state refetched', async () => {
    takeOutPet.mockRejectedValueOnce(new TypeError('Network request failed'));
    getChildPet.mockReturnValue(new Promise(() => undefined));
    const { wrapper, cached } = setup();
    const before = cached()?.behaviour;
    const { result } = renderHook(() => useTakeOut(), { wrapper });

    act(() => result.current.mutate());
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(cached()?.behaviour).toEqual(before);
  });

  it('resolve-chewing: slipper gone and clean at once, then the server state', async () => {
    const dirty = makeLiveChildState({
      pet: { hygiene_level: 0, needs_cleaning: true, pet_state: 'sick' },
      behaviour: { active_events: [makeBehaviourEvent('chewing')], scene: 'chewing', can_resolve_chewing: true },
    });
    const call = deferred<unknown>();
    resolveChewing.mockReturnValueOnce(call.promise);
    const { wrapper, cached } = setup(dirty);
    const { result } = renderHook(() => useResolveChewing(), { wrapper });

    act(() => result.current.mutate());
    await waitFor(() => expect(cached()?.pet.needs_cleaning).toBe(false));
    expect(cached()?.behaviour.scene).toBeNull();

    await act(async () =>
      call.resolve({ status: 'accepted', state: makeLiveChildState({ pet: { hygiene_level: 100, thirst_level: 33 } }) }),
    );
    await waitFor(() => expect(cached()?.pet.thirst_level).toBe(33));
    expect(resolveChewing).toHaveBeenCalledTimes(1);
  });
});
