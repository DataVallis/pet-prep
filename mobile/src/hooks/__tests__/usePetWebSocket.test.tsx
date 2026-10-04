/**
 * usePetWebSocket: ordering gate by emitted_at, `connected` only after the private
 * channel subscription succeeds (that's what stops the 10 s polling), handler routing.
 */
import { act, renderHook } from '@testing-library/react-native';
import Echo from 'laravel-echo';

import { createBroadcastGate, usePetWebSocket } from '@/hooks/usePetWebSocket';
import { useAppStore } from '@/store/appStore';
import { makeBroadcast, makePet } from '@/test-utils/fixtures';
import type { PetUpdatedBroadcast } from '@/types';

type Callback = (...args: unknown[]) => void;

interface FakeChannel {
  listeners: Record<string, Callback>;
  subscribedCb: Callback | null;
  errorCb: Callback | null;
}

function installFakeEcho() {
  const channel: FakeChannel = { listeners: {}, subscribedCb: null, errorCb: null };
  const connectionHandlers: Record<string, Callback> = {};
  interface ChannelApi {
    listen: (event: string, cb: Callback) => ChannelApi;
    subscribed: (cb: Callback) => ChannelApi;
    error: (cb: Callback) => ChannelApi;
  }
  const channelApi: ChannelApi = {
    listen: jest.fn((event: string, cb: Callback) => {
      channel.listeners[event] = cb;
      return channelApi;
    }),
    subscribed: jest.fn((cb: Callback) => {
      channel.subscribedCb = cb;
      return channelApi;
    }),
    error: jest.fn((cb: Callback) => {
      channel.errorCb = cb;
      return channelApi;
    }),
  };
  (Echo as unknown as jest.Mock).mockImplementation(() => ({
    private: jest.fn(() => channelApi),
    leave: jest.fn(),
    disconnect: jest.fn(),
    connector: {
      pusher: {
        connection: {
          bind: jest.fn((name: string, cb: Callback) => {
            connectionHandlers[name] = cb;
          }),
        },
      },
    },
  }));
  return { channel, connectionHandlers };
}

describe('createBroadcastGate', () => {
  it('passes newer or equal events and drops older ones', () => {
    const passes = createBroadcastGate();
    expect(passes(makeBroadcast({ emitted_at: '2026-10-04T10:00:05.000+00:00' }))).toBe(true);
    expect(passes(makeBroadcast({ emitted_at: '2026-10-04T10:00:04.999+00:00' }))).toBe(false);
    expect(passes(makeBroadcast({ emitted_at: '2026-10-04T10:00:05.000+00:00' }))).toBe(true);
    expect(passes(makeBroadcast({ emitted_at: 'nope' }))).toBe(false);
  });
});

describe('usePetWebSocket', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  it('is "connected" only after the channel subscription succeeds; errors and drops switch to polling states', () => {
    const { channel, connectionHandlers } = installFakeEcho();
    renderHook(() => usePetWebSocket(7, jest.fn()));
    expect(useAppStore.getState().wsStatus).toBe('connecting');

    act(() => channel.subscribedCb?.());
    expect(useAppStore.getState().wsStatus).toBe('connected');

    act(() => channel.errorCb?.({ status: 403 }));
    expect(useAppStore.getState().wsStatus).toBe('reconnecting');

    act(() => connectionHandlers.unavailable?.());
    expect(useAppStore.getState().wsStatus).toBe('reconnecting');
    act(() => connectionHandlers.disconnected?.());
    expect(useAppStore.getState().wsStatus).toBe('disconnected');
  });

  it('delivers events to the handler in order, dropping out-of-order ones', () => {
    const { channel } = installFakeEcho();
    const handler = jest.fn<void, [PetUpdatedBroadcast]>();
    renderHook(() => usePetWebSocket(7, handler));
    const listen = channel.listeners['.pet.updated'];

    act(() => {
      listen(makeBroadcast({ hunger_level: 40, emitted_at: '2026-10-04T10:00:10.000+00:00' }));
      listen(makeBroadcast({ hunger_level: 90, emitted_at: '2026-10-04T10:00:09.000+00:00' }));
      listen(makeBroadcast({ hunger_level: 35, emitted_at: '2026-10-04T10:00:11.000+00:00' }));
    });
    expect(handler.mock.calls.map(([e]) => e.hunger_level)).toEqual([40, 35]);
  });

  it('without a handler (parent dashboard) events update the session pet', () => {
    const { channel } = installFakeEcho();
    useAppStore.getState().setPet(makePet({ id: 7, hunger_level: 90 }));
    renderHook(() => usePetWebSocket(7));
    act(() => channel.listeners['.pet.updated'](makeBroadcast({ hunger_level: 12 })));
    expect(useAppStore.getState().pet?.hunger_level).toBe(12);
  });

  it('no pet → no socket', () => {
    installFakeEcho();
    renderHook(() => usePetWebSocket(null));
    expect(Echo).not.toHaveBeenCalled();
    expect(useAppStore.getState().wsStatus).toBe('disconnected');
  });
});
