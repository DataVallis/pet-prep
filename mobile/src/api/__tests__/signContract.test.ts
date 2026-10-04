/**
 * M1-07b: `api.signContract()` — POST /api/child/contract.
 */
import * as SecureStore from 'expo-secure-store';

import { ApiError, api } from '@/api/client';
import { makeChildState } from '@/test-utils/fixtures';

const getItem = SecureStore.getItemAsync as jest.Mock;

function mockFetch(status: number, body: unknown) {
  const fetchMock = jest.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    headers: { get: () => null },
    json: () => Promise.resolve(body),
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  return fetchMock;
}

const PATH = 'M10 10 L40 40 L80 20';

describe('api.signContract', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    getItem.mockResolvedValue('child-token');
  });

  it('POSTs the svg_path signature with the bearer token and returns the state (201)', async () => {
    const state = makeChildState();
    const fetchMock = mockFetch(201, { status: 'accepted', state });

    await expect(api.signContract({ signature_format: 'svg_path', signature: PATH })).resolves.toEqual({
      status: 'accepted',
      state,
    });

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit & { headers: Record<string, string> }];
    expect(url).toMatch(/\/api\/child\/contract$/);
    expect(init.method).toBe('POST');
    expect(init.headers.Authorization).toBe('Bearer child-token');
    expect(JSON.parse(String(init.body))).toEqual({ signature_format: 'svg_path', signature: PATH });
  });

  it('throws ApiError 409 carrying the state when already signed', async () => {
    const state = makeChildState();
    mockFetch(409, { message: 'The contract is already signed.', status: 'refused', reason: 'contract_already_signed', state });

    const error = (await api.signContract({ signature_format: 'svg_path', signature: PATH }).catch((e: unknown) => e)) as ApiError;
    expect(error).toBeInstanceOf(ApiError);
    expect(error.status).toBe(409);
    expect(error.data).toMatchObject({ reason: 'contract_already_signed', state });
  });

  it('throws ApiError 422 on a validation error', async () => {
    mockFetch(422, { message: 'The SVG path may only contain path commands…', errors: { signature: ['…'] } });

    const error = (await api.signContract({ signature_format: 'svg_path', signature: 'x' }).catch((e: unknown) => e)) as ApiError;
    expect(error.status).toBe(422);
  });

  it('throws ApiError 423 with the lock reason', async () => {
    mockFetch(423, { message: 'The pet is locked right now.', status: 'locked', reason: 'hard_stopped', locked_until: null, state: makeChildState({ born_at: null, awaiting_contract: true }) });

    const error = (await api.signContract({ signature_format: 'svg_path', signature: PATH }).catch((e: unknown) => e)) as ApiError;
    expect(error.status).toBe(423);
    expect(error.data).toMatchObject({ reason: 'hard_stopped' });
  });

  it('rejects with the network error when offline', async () => {
    globalThis.fetch = jest.fn().mockRejectedValue(new TypeError('Network request failed')) as unknown as typeof fetch;

    await expect(api.signContract({ signature_format: 'svg_path', signature: PATH })).rejects.toThrow('Network request failed');
  });
});
