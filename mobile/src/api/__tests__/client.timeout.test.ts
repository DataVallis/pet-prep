/**
 * Boot-hang fix 2026-10-19: `GET /api/user` with `timeoutMs` gives up (and aborts the fetch)
 * when the request never settles — expo/fetch sets no request timeout on iOS.
 */
import * as SecureStore from 'expo-secure-store';

import { ApiError, api, setUnauthorizedHandler } from '@/api/client';
import { TimeoutError } from '@/utils/withTimeout';

const getItem = SecureStore.getItemAsync as jest.Mock;

describe('api client request timeout', () => {
  const realFetch = globalThis.fetch;
  beforeEach(() => {
    jest.useFakeTimers();
    getItem.mockResolvedValue('tok');
  });
  afterEach(() => {
    jest.useRealTimers();
    globalThis.fetch = realFetch;
    getItem.mockReset();
  });

  it('rejects with TimeoutError and aborts the fetch when it never answers', async () => {
    let signal: AbortSignal | undefined;
    globalThis.fetch = jest.fn((_url: unknown, init?: RequestInit) => {
      signal = init?.signal ?? undefined;
      return new Promise<Response>(() => undefined);
    }) as unknown as typeof fetch;

    const caught = api.getUser({ timeoutMs: 10_000 }).catch((error: unknown) => error);
    await jest.advanceTimersByTimeAsync(9_999);
    expect(signal?.aborted).toBe(false);
    await jest.advanceTimersByTimeAsync(1);
    expect(await caught).toBeInstanceOf(TimeoutError);
    expect(signal?.aborted).toBe(true);
    expect(jest.getTimerCount()).toBe(0);
  });

  it('a hanging Keychain token read is bounded by the same timeout', async () => {
    getItem.mockImplementation(() => new Promise<never>(() => undefined));
    const fetchMock = jest.fn();
    globalThis.fetch = fetchMock as unknown as typeof fetch;
    const caught = api.getUser({ timeoutMs: 3_000 }).catch((error: unknown) => error);
    await jest.advanceTimersByTimeAsync(3_000);
    expect(await caught).toBeInstanceOf(TimeoutError);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('without timeoutMs the request is unchanged (no timer)', async () => {
    globalThis.fetch = jest.fn(async () => ({
      ok: true,
      status: 200,
      headers: { get: () => null },
      json: async () => ({ id: 1, name: 'x', email: null, role: 'parent', pet: null }),
    })) as unknown as typeof fetch;
    await expect(api.getUser()).resolves.toMatchObject({ id: 1 });
    expect(jest.getTimerCount()).toBe(0);
  });

  it('QA m2: a 401 that arrives after the timeout aborted the request never calls the logout handler', async () => {
    const handler = jest.fn();
    setUnauthorizedHandler(handler);
    let respond: (value: unknown) => void = () => undefined;
    globalThis.fetch = jest.fn(() => new Promise((resolve) => (respond = resolve))) as unknown as typeof fetch;
    const caught = api.getUser({ timeoutMs: 1_000 }).catch((error: unknown) => error);
    await jest.advanceTimersByTimeAsync(1_000);
    expect(await caught).toBeInstanceOf(TimeoutError);
    // The native fetch ignored the abort and the server answers 401 late.
    respond({ ok: false, status: 401, headers: { get: () => null }, json: async () => ({ message: 'Unauthenticated.' }) });
    await jest.advanceTimersByTimeAsync(0);
    expect(handler).not.toHaveBeenCalled();
    setUnauthorizedHandler(null);
  });

  it('a 401 within the timeout still calls the logout handler', async () => {
    const handler = jest.fn();
    setUnauthorizedHandler(handler);
    globalThis.fetch = jest.fn(async () => ({ ok: false, status: 401, headers: { get: () => null }, json: async () => ({}) })) as unknown as typeof fetch;
    await expect(api.getUser({ timeoutMs: 1_000 })).rejects.toBeInstanceOf(ApiError);
    expect(handler).toHaveBeenCalledTimes(1);
    setUnauthorizedHandler(null);
  });
});
