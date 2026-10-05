import * as SecureStore from 'expo-secure-store';
import type { ChannelAuthorizationHandler } from 'pusher-js';

import { ApiError, api } from '@/api/client';
import {
  buildPusherOptions,
  createChannelAuthorizer,
  PET_UPDATED_EVENT,
  petChannelName,
  petPrivateChannelName,
  usesTls,
  type ChannelAuthorization,
  type ChannelAuthorize,
  type RealtimeEnv,
} from '@/modules/realtime/echoConfig';

const getItem = SecureStore.getItemAsync as jest.Mock;

const PROD_ENV: RealtimeEnv = {
  REVERB_APP_KEY: 'app-key',
  REVERB_HOST: 'api.petprep.si',
  REVERB_PORT: 443,
  REVERB_SCHEME: 'https',
};

const LOCAL_ENV: RealtimeEnv = {
  REVERB_APP_KEY: 'local-key',
  REVERB_HOST: '10.0.2.2',
  REVERB_PORT: 8080,
  REVERB_SCHEME: 'http',
};

type AuthOutcome = { error: Error | null; data: ChannelAuthorization | null };

/** Run a pusher-js authorizer and resolve with what it passed to the callback. */
function runAuthorizer(handler: ChannelAuthorizationHandler, channelName = 'private-pet.7'): Promise<AuthOutcome> {
  return new Promise((resolve) => {
    handler({ socketId: '1234.5678', channelName }, (error, data) => resolve({ error, data }));
  });
}

function customHandlerOf(options: ReturnType<typeof buildPusherOptions>): ChannelAuthorizationHandler {
  const auth = options.channelAuthorization;
  if (!auth || !('customHandler' in auth)) throw new Error('expected a custom channel authorizer');
  return auth.customHandler;
}

describe('channel names', () => {
  it('subscribes to the private pet channel', () => {
    expect(petChannelName(7)).toBe('pet.7');
    expect(petPrivateChannelName(7)).toBe('private-pet.7');
  });

  it('listens to the custom event name with a leading dot', () => {
    expect(PET_UPDATED_EVENT).toBe('.pet.updated');
  });
});

describe('buildPusherOptions', () => {
  const authorize: ChannelAuthorize = jest.fn();

  it('uses wss on 443 with TLS forced in production', () => {
    const options = buildPusherOptions(PROD_ENV, authorize);

    expect(usesTls(PROD_ENV)).toBe(true);
    expect(options).toMatchObject({
      wsHost: 'api.petprep.si',
      wssPort: 443,
      forceTLS: true,
      // NOT ['wss'] alone: with forceTLS pusher-js only uses the transport named `ws`.
      enabledTransports: ['ws', 'wss'],
      disableStats: true,
    });
  });

  it('uses plain ws (no TLS) for local http development', () => {
    const options = buildPusherOptions(LOCAL_ENV, authorize);

    expect(usesTls(LOCAL_ENV)).toBe(false);
    expect(options).toMatchObject({ wsHost: '10.0.2.2', wsPort: 8080, forceTLS: false, enabledTransports: ['ws', 'wss'] });
  });

  it('treats a wss scheme like https', () => {
    expect(usesTls({ REVERB_SCHEME: 'wss' })).toBe(true);
    expect(buildPusherOptions({ ...PROD_ENV, REVERB_SCHEME: 'wss' }, authorize).forceTLS).toBe(true);
  });

  it('authorizes private channels through the given authorize function', async () => {
    const auth = jest.fn<ReturnType<ChannelAuthorize>, Parameters<ChannelAuthorize>>().mockResolvedValue({ auth: 'key:sig' });
    const options = buildPusherOptions(PROD_ENV, auth);

    await expect(runAuthorizer(customHandlerOf(options))).resolves.toEqual({ error: null, data: { auth: 'key:sig' } });
    expect(auth).toHaveBeenCalledWith('1234.5678', 'private-pet.7');
  });
});

describe('createChannelAuthorizer', () => {
  it('passes channel_data through when present', async () => {
    const handler = createChannelAuthorizer(() => Promise.resolve({ auth: 'k:s', channel_data: '{"user_id":1}' }));

    await expect(runAuthorizer(handler)).resolves.toEqual({
      error: null,
      data: { auth: 'k:s', channel_data: '{"user_id":1}' },
    });
  });

  it('reports a rejected authorization (e.g. 403) to pusher-js instead of throwing', async () => {
    const handler = createChannelAuthorizer(() => Promise.reject(new ApiError('Forbidden', 403)));

    const outcome = await runAuthorizer(handler);
    expect(outcome.data).toBeNull();
    expect(outcome.error).toBeInstanceOf(ApiError);
    expect((outcome.error as ApiError).status).toBe(403);
  });

  it('reports a body without a signature as an error', async () => {
    const handler = createChannelAuthorizer(() => Promise.resolve({ auth: '' }));

    const outcome = await runAuthorizer(handler);
    expect(outcome.data).toBeNull();
    expect(outcome.error?.message).toMatch(/no signature/);
  });

  it('wraps non-Error rejections', async () => {
    const handler = createChannelAuthorizer(() => Promise.reject('offline'));

    const outcome = await runAuthorizer(handler);
    expect(outcome.error).toBeInstanceOf(Error);
    expect(outcome.error?.message).toBe('offline');
  });
});

describe('api.authorizeChannel (the authorizer the app uses)', () => {
  afterEach(() => jest.clearAllMocks());

  it('POSTs socket_id + channel_name to /api/broadcasting/auth with the Bearer token', async () => {
    getItem.mockResolvedValueOnce('tok');
    const fetchMock = jest.fn().mockResolvedValue({
      ok: true,
      status: 200,
      headers: { get: () => null },
      json: () => Promise.resolve({ auth: 'app-key:signature' }),
    });
    globalThis.fetch = fetchMock as unknown as typeof fetch;

    const outcome = await runAuthorizer(createChannelAuthorizer(api.authorizeChannel));

    expect(outcome).toEqual({ error: null, data: { auth: 'app-key:signature' } });
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit & { headers: Record<string, string> }];
    expect(url).toMatch(/\/api\/broadcasting\/auth$/);
    expect(init.method).toBe('POST');
    expect(init.headers.Authorization).toBe('Bearer tok');
    expect(init.headers.Accept).toBe('application/json');
    expect(JSON.parse(init.body as string)).toEqual({ socket_id: '1234.5678', channel_name: 'private-pet.7' });
  });

  it('surfaces a 403 (not this user’s pet) as an authorization error', async () => {
    getItem.mockResolvedValueOnce('tok');
    globalThis.fetch = jest.fn().mockResolvedValue({
      ok: false,
      status: 403,
      headers: { get: () => null },
      json: () => Promise.resolve({ message: 'This action is unauthorized.' }),
    }) as unknown as typeof fetch;

    const outcome = await runAuthorizer(createChannelAuthorizer(api.authorizeChannel));

    expect(outcome.data).toBeNull();
    expect((outcome.error as ApiError).status).toBe(403);
  });
});
