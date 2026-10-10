import * as SecureStore from 'expo-secure-store';

import { CAT_UI_READY } from '@/config/features';
import { ApiError, CLIENT_FEATURES, api, breedCataloguePath, clientFeatures, generatePinBody, setUnauthorizedHandler } from '@/api/client';

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

  it('unregisters a push device with the token in the POST body, never in the URL (M3-02, PR #35)', async () => {
    getItem.mockResolvedValueOnce('tok');
    const fetchMock = mockFetch(204, undefined);
    const token = 'ExponentPushToken[abcdefghijklmnop]';

    await expect(api.unregisterDevice(token)).resolves.toBeNull();
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toMatch(/\/api\/devices\/unregister$/);
    expect(url).not.toContain('Exponent');
    expect(init.method).toBe('POST');
    expect(JSON.parse(String(init.body))).toEqual({ expo_push_token: token });
  });

  it('exposes Retry-After on a 429', async () => {
    getItem.mockResolvedValueOnce('tok');
    mockFetch(429, { message: 'Too Many Attempts.' }, { 'Retry-After': '37' });

    const error = await api.generatePin({ child_id: 3 }).catch((e: unknown) => e);
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

    const error = (await api.generatePin({ child_id: 3 }).catch((e: unknown) => e)) as ApiError;
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

  describe('M2-02 endpoints', () => {
    type Init = RequestInit & { headers: Record<string, string> };

    it('pin-login is anonymous: no Bearer even when a token is stored, body {pin, device_name, features}', async () => {
      getItem.mockResolvedValue('stale-token');
      const fetchMock = mockFetch(200, { token: 't' });

      await api.pinLogin('734912', 'iPhone');
      const [url, init] = fetchMock.mock.calls[0] as [string, Init];
      expect(url).toMatch(/\/api\/child\/pin-login$/);
      expect(init.method).toBe('POST');
      expect(init.headers.Authorization).toBeUndefined();
      // M5-R02: the child's device always declares what it can show (M5-R06-09: cats too).
      expect(JSON.parse(String(init.body))).toEqual({ pin: '734912', device_name: 'iPhone', features: ['behaviour_events', 'training', 'species_cat'] });
      getItem.mockReset();
    });

    it('a 422 on pin-login never triggers the unauthorized handler', async () => {
      const handler = jest.fn();
      setUnauthorizedHandler(handler);
      mockFetch(422, { message: 'This code is not valid.', reason: 'invalid_pin' });

      const error = (await api.pinLogin('000000', 'Telefon').catch((e: unknown) => e)) as ApiError;
      expect(error.status).toBe(422);
      expect(error.data).toEqual({ message: 'This code is not valid.', reason: 'invalid_pin' });
      expect(handler).not.toHaveBeenCalled();
    });

    it('createChild posts the nickname and birth year (null when omitted)', async () => {
      getItem.mockResolvedValueOnce('tok');
      const fetchMock = mockFetch(201, { child: { id: 5 } });

      await api.createChild({ display_name: 'Maja' });
      const [url, init] = fetchMock.mock.calls[0] as [string, Init];
      expect(url).toMatch(/\/api\/parent\/children$/);
      expect(JSON.parse(String(init.body))).toEqual({ display_name: 'Maja', birth_year: null });
    });

    it('generatePin sends child_id, and pet_id only when joining a pet', async () => {
      getItem.mockResolvedValue('tok');
      const fetchMock = mockFetch(200, { pin: '123456' });

      await api.generatePin({ child_id: 5 });
      await api.generatePin({ child_id: 5, pet_id: 9 });
      const bodies = fetchMock.mock.calls.map(([, init]) => JSON.parse(String((init as Init).body)));
      expect(bodies).toEqual([{ child_id: 5 }, { child_id: 5, pet_id: 9 }]);
      getItem.mockReset();
    });

    it('generatePin sends the full picker set for a new pet, never with pet_id (M5-R04)', async () => {
      getItem.mockResolvedValue('tok');
      const fetchMock = mockFetch(200, { pin: '123456' });
      const profile = { species: 'dog', breed: 'mutt', origin: 'adopted', age_stage: 'senior', plan: 'challenge' } as const;

      await api.generatePin({ child_id: 5, pet_id: null, profile });
      await api.generatePin({ child_id: 5, pet_id: 9, profile });
      const bodies = fetchMock.mock.calls.map(([, init]) => JSON.parse(String((init as Init).body)));
      expect(bodies).toEqual([
        { child_id: 5, species: 'dog', breed: 'mutt', origin: 'adopted', age_stage: 'senior', plan: 'challenge', features: ['behaviour_events', 'training', 'species_cat'] },
        { child_id: 5, pet_id: 9 },
      ]);
      getItem.mockReset();
    });

    it('generatePinBody declares features only with the profile (M5-R02 gate)', () => {
      const profile = { species: 'dog', breed: 'mutt', origin: 'bought', age_stage: 'puppy', plan: 'challenge' } as const;
      expect(generatePinBody({ child_id: 5, profile })).toEqual({
        child_id: 5,
        species: 'dog',
        breed: 'mutt',
        origin: 'bought',
        age_stage: 'puppy',
        plan: 'challenge',
        features: ['behaviour_events', 'training', 'species_cat'],
      });
      // M5-R06-02: the breed is sent as chosen — no hard-coded mutt; the picker puts the
      // species' free breed on the free plan (a free cat is the domestic cat, never a mutt).
      expect(
        generatePinBody({ child_id: 5, profile: { species: 'cat', breed: 'domestic_cat', origin: 'adopted', age_stage: 'puppy', plan: 'free' } }),
      ).toEqual(expect.objectContaining({ species: 'cat', breed: 'domestic_cat', plan: 'free' }));
      // Joining a pet: the shared pet keeps what its creating app declared.
      expect(generatePinBody({ child_id: 5, pet_id: 9, profile })).toEqual({ child_id: 5, pet_id: 9 });
      // Legacy / re-login call without a profile: no features.
      expect(generatePinBody({ child_id: 5 })).toEqual({ child_id: 5 });
      expect(generatePinBody({ child_id: 5, pet_id: null, profile: null })).toEqual({ child_id: 5 });
      // A fresh array each time (the constant is never shared / mutated).
      const a = generatePinBody({ child_id: 1, profile }).features;
      expect(a).not.toBe(CLIENT_FEATURES);
    });

    it('M5-R06-02: species_cat is declared only when the cat UI is ready (CAT_UI_READY)', () => {
      // M5-R06-09: this build has the cat HUD → it declares species_cat. Whether a cat is
      // offered is the server's call (PETPREP_CATS_ENABLED); with it off the server ignores it.
      expect(CAT_UI_READY).toBe(true);
      expect(CLIENT_FEATURES).toEqual(['behaviour_events', 'training', 'species_cat']);
      expect(clientFeatures(false)).toEqual(['behaviour_events', 'training']);
      expect(clientFeatures(true)).toEqual(['behaviour_events', 'training', 'species_cat']);
      const profile = { species: 'cat', breed: 'maine_coon', origin: 'bought', age_stage: 'puppy', plan: 'challenge' } as const;
      expect(generatePinBody({ child_id: 5, profile }, clientFeatures(true))).toEqual({
        child_id: 5,
        species: 'cat',
        breed: 'maine_coon',
        origin: 'bought',
        age_stage: 'puppy',
        plan: 'challenge',
        features: ['behaviour_events', 'training', 'species_cat'],
      });
    });

    it('getBreedCatalogue: GET /api/breeds with this build\'s features[] (M5-R06-02)', async () => {
      getItem.mockResolvedValue('tok');
      const body = { species: ['dog'], breeds: [] };
      const fetchMock = mockFetch(200, body);

      await expect(api.getBreedCatalogue()).resolves.toEqual(body);
      const [url, init] = fetchMock.mock.calls[0] as [string, Init];
      expect(url).toMatch(/\/api\/breeds\?features\[\]=behaviour_events&features\[\]=training&features\[\]=species_cat$/);
      expect(init.method).toBe('GET');
      expect(breedCataloguePath(clientFeatures(true))).toBe(
        '/api/breeds?features[]=behaviour_events&features[]=training&features[]=species_cat',
      );
      expect(breedCataloguePath([], 'cat')).toBe('/api/breeds?species=cat');
      getItem.mockReset();
    });

    it('revokeChildTokens deletes /api/parent/children/{id}/tokens', async () => {
      getItem.mockResolvedValueOnce('tok');
      const fetchMock = mockFetch(200, { revoked_tokens: 2, revoked_pins: 0 });

      await expect(api.revokeChildTokens(5)).resolves.toEqual({ revoked_tokens: 2, revoked_pins: 0 });
      const [url, init] = fetchMock.mock.calls[0] as [string, Init];
      expect(url).toMatch(/\/api\/parent\/children\/5\/tokens$/);
      expect(init.method).toBe('DELETE');
    });
  });
});

describe('deletion confirm word (M1-18)', () => {
  it('sends confirm_word only when given', async () => {
    (SecureStore.getItemAsync as jest.Mock).mockResolvedValue('tok');
    const fetchMock = mockFetch(200, { deleted: true });
    await api.deleteAccount('pw', 'DELETE');
    await api.deleteChild(3, 'pw');
    expect(JSON.parse(String((fetchMock.mock.calls[0][1] as RequestInit).body))).toEqual({
      password: 'pw',
      confirm: true,
      confirm_word: 'DELETE',
    });
    expect(JSON.parse(String((fetchMock.mock.calls[1][1] as RequestInit).body))).toEqual({ password: 'pw', confirm: true });
  });
});
