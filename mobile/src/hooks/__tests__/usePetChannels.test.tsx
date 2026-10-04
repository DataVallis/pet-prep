/**
 * usePetChannels (PR #20 review M2): one Echo for all pets, a status per pet, and
 * `connected` only while EVERY pet channel is subscribed.
 */
import { act, renderHook } from '@testing-library/react-native';
import Echo from 'laravel-echo';

import { aggregateChannelStatus, usePetChannels } from '@/hooks/usePetChannels';
import { livePollInterval, PARENT_POLL_MS } from '@/modules/family/live';
import { useAppStore } from '@/store/appStore';
import { makeBroadcast } from '@/test-utils/fixtures';

type Callback = (...args: unknown[]) => void;

interface FakeChannel {
  listeners: Record<string, Callback>;
  subscribed: Callback | null;
  error: Callback | null;
}

function installFakeEcho() {
  const channels: Record<string, FakeChannel> = {};
  const connection: Record<string, Callback> = {};
  const instances = { count: 0 };
  const leave = jest.fn();
  const disconnect = jest.fn();
  (Echo as unknown as jest.Mock).mockImplementation(() => {
    instances.count += 1;
    return {
      private: jest.fn((name: string) => {
        const ch: FakeChannel = { listeners: {}, subscribed: null, error: null };
        channels[name] = ch;
        const api = {
          listen: (event: string, cb: Callback) => {
            ch.listeners[event] = cb;
            return api;
          },
          subscribed: (cb: Callback) => {
            ch.subscribed = cb;
            return api;
          },
          error: (cb: Callback) => {
            ch.error = cb;
            return api;
          },
        };
        return api;
      }),
      leave,
      disconnect,
      connector: { pusher: { connection: { bind: (name: string, cb: Callback) => (connection[name] = cb) } } },
    };
  });
  return { channels, connection, instances, leave, disconnect };
}

const status = () => useAppStore.getState().wsStatus;

describe('aggregateChannelStatus', () => {
  it('connected only when all are', () => {
    expect(aggregateChannelStatus(['connected', 'connected'])).toBe('connected');
    expect(aggregateChannelStatus(['connected', 'connecting'])).toBe('connecting');
    expect(aggregateChannelStatus(['connected', 'reconnecting'])).toBe('reconnecting');
    expect(aggregateChannelStatus(['connected', 'disconnected'])).toBe('disconnected');
    expect(aggregateChannelStatus([])).toBe('disconnected');
  });
});

describe('usePetChannels', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  it('one Echo with two private channels; one errors, the other subscribes → still polling', () => {
    const fake = installFakeEcho();
    renderHook(() => usePetChannels([7, 8], jest.fn()));

    expect(fake.instances.count).toBe(1);
    expect(Object.keys(fake.channels).sort()).toEqual(['pet.7', 'pet.8']);
    expect(status()).toBe('connecting');

    act(() => fake.channels['pet.7'].error?.({ status: 403 }));
    act(() => fake.channels['pet.8'].subscribed?.());
    expect(status()).toBe('reconnecting');
    expect(livePollInterval(status())).toBe(PARENT_POLL_MS);

    // Pet 7 recovers → everything live.
    act(() => fake.channels['pet.7'].subscribed?.());
    expect(status()).toBe('connected');

    // Whole connection drops → all channels down.
    act(() => fake.connection.disconnected?.());
    expect(status()).toBe('disconnected');
  });

  it('routes events with a per-pet ordering gate', () => {
    const fake = installFakeEcho();
    const handler = jest.fn();
    renderHook(() => usePetChannels([7, 8], handler));

    const emit = (name: string, emittedAt: string) =>
      fake.channels[name].listeners['.pet.updated']?.(makeBroadcast({ emitted_at: emittedAt }));
    act(() => emit('pet.7', '2026-10-04T10:00:05.000+00:00'));
    // Older event of ANOTHER pet still passes (gates are per pet).
    act(() => emit('pet.8', '2026-10-04T10:00:01.000+00:00'));
    // Older event of the same pet is dropped.
    act(() => emit('pet.7', '2026-10-04T10:00:04.000+00:00'));
    expect(handler).toHaveBeenCalledTimes(2);
  });

  it('re-subscribes once when the pet set changes (order-insensitive) and cleans up', () => {
    const fake = installFakeEcho();
    const { rerender, unmount } = renderHook(({ ids }: { ids: number[] }) => usePetChannels(ids, jest.fn()), {
      initialProps: { ids: [8, 7] },
    });
    rerender({ ids: [7, 8] });
    expect(fake.instances.count).toBe(1);

    rerender({ ids: [7] });
    expect(fake.instances.count).toBe(2);
    expect(fake.leave).toHaveBeenCalledWith('pet.7');
    expect(fake.leave).toHaveBeenCalledWith('pet.8');

    unmount();
    expect(fake.disconnect).toHaveBeenCalledTimes(2);
    expect(status()).toBe('disconnected');
  });

  it('no pets → no connection', () => {
    const fake = installFakeEcho();
    renderHook(() => usePetChannels([], jest.fn()));
    expect(fake.instances.count).toBe(0);
    expect(status()).toBe('disconnected');
  });
});
