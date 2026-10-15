/**
 * M5-F13: the API client reports every answer to the "Updating PetPrep" banner, keeps the
 * 401 logout exactly as before, and the `/up` probe never throws.
 */
import * as SecureStore from 'expo-secure-store';

import { ApiError, api, probeServer, setUnauthorizedHandler } from '@/api/client';
import { useServerStatusStore } from '@/store/serverStatusStore';

const getItem = SecureStore.getItemAsync as jest.Mock;

function respond(status: number, body: unknown, headers: Record<string, string> = {}) {
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: { get: (name: string) => headers[name] ?? null },
    json: () => (body === undefined ? Promise.reject(new SyntaxError('no body')) : Promise.resolve(body)),
  };
}

function mockFetch(...answers: (ReturnType<typeof respond> | Error)[]) {
  const fetchMock = jest.fn();
  for (const answer of answers) {
    if (answer instanceof Error) fetchMock.mockRejectedValueOnce(answer);
    else fetchMock.mockResolvedValueOnce(answer);
  }
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  return fetchMock;
}

const outage = () => useServerStatusStore.getState().outage;

describe('api client ↔ server outage (M5-F13)', () => {
  beforeEach(() => {
    getItem.mockResolvedValue('tok');
  });
  afterEach(() => {
    setUnauthorizedHandler(null);
    jest.clearAllMocks();
    getItem.mockReset();
  });

  it('a maintenance 503 shows the banner with its Retry-After; the next answer hides it', async () => {
    mockFetch(
      respond(503, { message: 'Service Unavailable' }, { 'Retry-After': '15' }),
      respond(200, { id: 1, name: 'P', email: null, role: 'parent', pet: null }),
    );

    const error = (await api.getUser().catch((e: unknown) => e)) as ApiError;
    expect(error).toBeInstanceOf(ApiError);
    expect(error.status).toBe(503);
    expect(error.retryAfterSeconds).toBe(15);
    expect(useServerStatusStore.getState()).toMatchObject({ outage: true, cause: 'maintenance', retryAfterMs: 15_000 });

    await api.getUser();
    expect(outage()).toBe(false);
  });

  it('502 / 504 show it too', async () => {
    mockFetch(respond(502, undefined));
    await api.getChildPet().catch(() => undefined);
    expect(useServerStatusStore.getState().cause).toBe('gateway');
  });

  it('a network failure alone does not show it, but keeps an outage up', async () => {
    mockFetch(new TypeError('Network request failed'));
    await expect(api.getChildPet()).rejects.toBeInstanceOf(TypeError);
    expect(outage()).toBe(false);

    useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000);
    mockFetch(new TypeError('Network request failed'));
    await expect(api.getChildPet()).rejects.toBeInstanceOf(TypeError);
    expect(outage()).toBe(true);
  });

  it('D19: Caddy 502 (container swap, no maintenance) → banner; connection refused keeps it; 200 ends it', async () => {
    mockFetch(respond(502, undefined), new TypeError('Network request failed'), respond(200, { id: 1, name: 'P', email: null, role: 'parent', pet: null }));
    await api.getUser().catch(() => undefined);
    expect(useServerStatusStore.getState().cause).toBe('gateway');
    expect(outage()).toBe(true);
    await api.getUser().catch(() => undefined);
    expect(outage()).toBe(true);
    await api.getUser();
    expect(outage()).toBe(false);
  });

  it('a 503 with a reason (social sign-in) is not a deploy', async () => {
    mockFetch(respond(503, { message: 'x', code: 'provider_unavailable' }));
    await api.getUser().catch(() => undefined);
    expect(outage()).toBe(false);
  });

  it('a 503 never logs the user out; a 401 still does (and also ends the banner — the server answered)', async () => {
    const handler = jest.fn();
    setUnauthorizedHandler(handler);

    mockFetch(respond(503, { message: 'Service Unavailable' }, { 'Retry-After': '15' }));
    await api.getUser().catch(() => undefined);
    expect(handler).not.toHaveBeenCalled();
    expect(outage()).toBe(true);

    mockFetch(respond(401, { message: 'Unauthenticated.' }));
    await api.getUser().catch(() => undefined);
    expect(handler).toHaveBeenCalledTimes(1);
    expect(outage()).toBe(false);
  });

  it('a 423 lock / 422 refusal answer is unchanged (status + body) and counts as "server up"', async () => {
    useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000);
    mockFetch(respond(423, { message: 'Locked', reason: 'ill', state: { id: 1 } }));
    const error = (await api.feedPet().catch((e: unknown) => e)) as ApiError;
    expect(error.status).toBe(423);
    expect(error.data).toEqual({ message: 'Locked', reason: 'ill', state: { id: 1 } });
    expect(outage()).toBe(false);
  });

  describe('probeServer (GET /up)', () => {
    it('200 → true and clears the banner; no Bearer token', async () => {
      useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000);
      const fetchMock = mockFetch(respond(200, undefined));
      await expect(probeServer()).resolves.toBe(true);
      const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit & { headers: Record<string, string> }];
      expect(url).toMatch(/\/up$/);
      expect(init.headers.Authorization).toBeUndefined();
      expect(outage()).toBe(false);
    });

    it('503 → false (banner stays); a network failure → false, never throws', async () => {
      mockFetch(respond(503, undefined, { 'Retry-After': '15' }));
      await expect(probeServer()).resolves.toBe(false);
      expect(outage()).toBe(true);

      mockFetch(new TypeError('Network request failed'));
      await expect(probeServer()).resolves.toBe(false);
      expect(outage()).toBe(true);
    });
  });
});
