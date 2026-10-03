import * as SecureStore from 'expo-secure-store';

import { ApiError, api, setUnauthorizedHandler } from '@/api/client';

const getItem = SecureStore.getItemAsync as jest.Mock;

function mockFetch(status: number, body: unknown, headers: Record<string, string> = {}) {
  const fetchMock = jest.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    headers: { get: (name: string) => headers[name] ?? null },
    json: () => (body === undefined ? Promise.reject(new SyntaxError('no body')) : Promise.resolve(body)),
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  return fetchMock;
}

describe('api client', () => {
  afterEach(() => {
    setUnauthorizedHandler(null);
    jest.clearAllMocks();
  });

  it('GET /api/user returns the flat user object with the bearer token', async () => {
    getItem.mockResolvedValueOnce('tok');
    const fetchMock = mockFetch(200, { id: 1, name: 'Starš', email: 'p@x.si', role: 'parent', pet: null });

    await expect(api.getUser()).resolves.toEqual({ id: 1, name: 'Starš', email: 'p@x.si', role: 'parent', pet: null });
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit & { headers: Record<string, string> }];
    expect(url).toMatch(/\/api\/user$/);
    expect(init.headers.Authorization).toBe('Bearer tok');
  });

  it('exposes Retry-After on a 429', async () => {
    getItem.mockResolvedValueOnce('tok');
    mockFetch(429, { message: 'Too Many Attempts.' }, { 'Retry-After': '37' });

    const error = await api.generatePin().catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(429);
    expect((error as ApiError).retryAfterSeconds).toBe(37);
    expect((error as ApiError).message).toBe('Too Many Attempts.');
  });

  it('does not crash on a non-JSON error body', async () => {
    getItem.mockResolvedValueOnce('tok');
    mockFetch(502, undefined);

    const error = (await api.getUser().catch((e: unknown) => e)) as ApiError;
    expect(error.status).toBe(502);
    expect(error.message).toBe('An error occurred');
  });

  it('calls the unauthorized handler on a 401 for an authenticated request', async () => {
    const handler = jest.fn();
    setUnauthorizedHandler(handler);
    getItem.mockResolvedValueOnce('revoked').mockResolvedValueOnce('revoked');
    mockFetch(401, { message: 'Unauthenticated.' });

    await expect(api.getUser()).rejects.toBeInstanceOf(ApiError);
    expect(handler).toHaveBeenCalledTimes(1);
  });

  it('ignores a late 401 for a token that is no longer the stored one (re-login in between)', async () => {
    const handler = jest.fn();
    setUnauthorizedHandler(handler);
    getItem.mockResolvedValueOnce('old-token').mockResolvedValueOnce('new-token');
    mockFetch(401, { message: 'Unauthenticated.' });

    await expect(api.getUser()).rejects.toBeInstanceOf(ApiError);
    expect(handler).not.toHaveBeenCalled();
  });

  it('ignores a 401 after the token was already cleared (logout in between)', async () => {
    const handler = jest.fn();
    setUnauthorizedHandler(handler);
    getItem.mockResolvedValueOnce('old-token').mockResolvedValueOnce(null);
    mockFetch(401, { message: 'Unauthenticated.' });

    await expect(api.getUser()).rejects.toBeInstanceOf(ApiError);
    expect(handler).not.toHaveBeenCalled();
  });

  it('passes an abort signal through to fetch (logout timeout)', async () => {
    getItem.mockResolvedValueOnce('tok');
    const fetchMock = mockFetch(200, { message: 'Logged out' });
    const controller = new AbortController();

    await api.logout(controller.signal);
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(init.signal).toBe(controller.signal);
  });

  it('keeps Retry-After: 0 as 0 (pin.ts decides what to show)', async () => {
    getItem.mockResolvedValueOnce('tok');
    mockFetch(429, { message: 'Too Many Attempts.' }, { 'Retry-After': '0' });

    const error = (await api.generatePin().catch((e: unknown) => e)) as ApiError;
    expect(error.retryAfterSeconds).toBe(0);
  });

  it('does not call the unauthorized handler when no token was sent (e.g. wrong password)', async () => {
    const handler = jest.fn();
    setUnauthorizedHandler(handler);
    getItem.mockResolvedValueOnce(null);
    mockFetch(401, { message: 'Invalid credentials.' });

    await expect(api.login('a@b.si', 'x')).rejects.toBeInstanceOf(ApiError);
    expect(handler).not.toHaveBeenCalled();
  });
});
