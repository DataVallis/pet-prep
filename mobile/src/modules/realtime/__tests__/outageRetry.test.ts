/**
 * M5-F13: channel auth (`POST /api/broadcasting/auth`) during a deploy is retried with
 * backoff instead of leaving the channel unsubscribed; re-subscriptions are recognised.
 */
import { ApiError } from '@/api/client';
import {
  CHANNEL_AUTH_OUTAGE_ATTEMPTS,
  createChannelAuthorizer,
  createSubscriptionTracker,
  withOutageRetry,
  type ChannelAuthorization,
} from '@/modules/realtime/echoConfig';

const SIGNED: ChannelAuthorization = { auth: 'key:sig' };
const maintenance = () => new ApiError('Service Unavailable', 503, { message: 'Service Unavailable' }, 15);

describe('withOutageRetry', () => {
  it('retries a maintenance 503 / 502 / network failure until the server signs', async () => {
    const authorize = jest
      .fn()
      .mockRejectedValueOnce(maintenance())
      .mockRejectedValueOnce(new ApiError('Bad Gateway', 502))
      .mockRejectedValueOnce(new TypeError('Network request failed'))
      .mockResolvedValueOnce(SIGNED);
    const delays: number[] = [];
    const wrapped = withOutageRetry(authorize, { delay: async (ms) => void delays.push(ms) });

    await expect(wrapped('1.2', 'private-pet.7')).resolves.toEqual(SIGNED);
    expect(authorize).toHaveBeenCalledTimes(4);
    // Retry-After 15 s for the 503, then the backoff (attempt 1 → 4 s, attempt 2 → 8 s).
    expect(delays).toEqual([15_000, 4_000, 8_000]);
  });

  it('never retries 401 / 403 (the session / authz answer goes straight to pusher-js)', async () => {
    for (const status of [401, 403]) {
      const authorize = jest.fn().mockRejectedValue(new ApiError('no', status));
      const wrapped = withOutageRetry(authorize, { delay: async () => undefined });
      await expect(wrapped('1.2', 'private-pet.7')).rejects.toMatchObject({ status });
      expect(authorize).toHaveBeenCalledTimes(1);
    }
  });

  it('gives up after the attempt budget (≈ several minutes) with the last error', async () => {
    const authorize = jest.fn().mockRejectedValue(maintenance());
    const wrapped = withOutageRetry(authorize, { delay: async () => undefined });
    await expect(wrapped('1.2', 'private-pet.7')).rejects.toMatchObject({ status: 503 });
    expect(authorize).toHaveBeenCalledTimes(CHANNEL_AUTH_OUTAGE_ATTEMPTS);
  });

  it('stops retrying once the hook was torn down', async () => {
    let active = true;
    const authorize = jest.fn().mockRejectedValue(maintenance());
    const wrapped = withOutageRetry(authorize, {
      delay: async () => {
        active = false;
      },
      isActive: () => active,
    });
    await expect(wrapped('1.2', 'private-pet.7')).rejects.toMatchObject({ status: 503 });
    expect(authorize).toHaveBeenCalledTimes(1);
  });

  it('QA m2: stops retrying when the socket that asked disconnected or reconnected (new socket id)', async () => {
    for (const nowSocket of [null, '9.9']) {
      let current: string | null = '1.2';
      const authorize = jest.fn().mockRejectedValue(maintenance());
      const wrapped = withOutageRetry(authorize, {
        delay: async () => {
          current = nowSocket;
        },
        currentSocketId: () => current,
      });
      await expect(wrapped('1.2', 'private-pet.7')).rejects.toMatchObject({ status: 503 });
      expect(authorize).toHaveBeenCalledTimes(1);
    }
  });

  it('QA m2: keeps retrying while the same socket is connected', async () => {
    const authorize = jest.fn().mockRejectedValueOnce(maintenance()).mockRejectedValueOnce(maintenance()).mockResolvedValueOnce(SIGNED);
    const wrapped = withOutageRetry(authorize, { delay: async () => undefined, currentSocketId: () => '1.2' });
    await expect(wrapped('1.2', 'private-pet.7')).resolves.toEqual(SIGNED);
    expect(authorize).toHaveBeenCalledTimes(3);
  });

  it('through the pusher-js authorizer: a deploy blip still ends in a signature', async () => {
    const authorize = jest.fn().mockRejectedValueOnce(maintenance()).mockResolvedValueOnce(SIGNED);
    const handler = createChannelAuthorizer(withOutageRetry(authorize, { delay: async () => undefined }));
    const callback = jest.fn();
    handler({ socketId: '1.2', channelName: 'private-pet.7' }, callback);
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(callback).toHaveBeenCalledWith(null, SIGNED);
  });
});

describe('createSubscriptionTracker', () => {
  it('first subscription → false, every later one (reconnect) → true', () => {
    const tracker = createSubscriptionTracker();
    expect(tracker.subscribed()).toBe(false);
    expect(tracker.subscribed()).toBe(true);
    expect(tracker.subscribed()).toBe(true);
  });
});
