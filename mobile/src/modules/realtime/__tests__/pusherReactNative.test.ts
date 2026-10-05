/**
 * Regression tests against the REAL pusher-js React Native build — the file Metro
 * bundles on a phone (`pusher-js/dist/react-native/pusher.js`). Both TestFlight bugs of
 * 2026-10-05 ("BREZ POVEZAVE" forever) were invisible to the mocked hook tests:
 *  1. the RN build exports `{ Pusher }` (no default) → our import shim got `undefined`;
 *  2. `enabledTransports: ['wss']` + `forceTLS` → no usable transport → state `failed`.
 */

import type Pusher from 'pusher-js';

import { buildPusherOptions, resolvePusherConstructor, type RealtimeEnv } from '@/modules/realtime/echoConfig';

jest.unmock('pusher-js');
// The RN build reads connectivity from NetInfo (native module → not available in Jest).
jest.mock('@react-native-community/netinfo', () => ({
  fetch: jest.fn(() => Promise.resolve({ type: 'wifi' })),
  addEventListener: jest.fn(() => jest.fn()),
}));

/** Records the URL pusher-js opens instead of touching the network. */
class FakeWebSocket {
  static urls: string[] = [];
  readyState = 0;
  onopen: (() => void) | null = null;
  onclose: (() => void) | null = null;
  onerror: (() => void) | null = null;
  onmessage: (() => void) | null = null;
  constructor(url: string) {
    FakeWebSocket.urls.push(url);
  }
  send(): void {}
  close(): void {}
}

const PROD_ENV: RealtimeEnv = {
  REVERB_APP_KEY: 'app-key',
  REVERB_HOST: 'api.petprep.si',
  REVERB_PORT: 443,
  REVERB_SCHEME: 'https',
};

const authorize = jest.fn();
const originalWebSocket = globalThis.WebSocket;
const clients: Pusher[] = [];

function loadReactNativeBuild(): unknown {
  // Exactly what `import Pusher from 'pusher-js'` gives Babel's interop on device.
  return jest.requireActual<object>('pusher-js/dist/react-native/pusher.js');
}

function connect(options: ReturnType<typeof buildPusherOptions>): Pusher {
  const PusherClient = resolvePusherConstructor<Pusher>(loadReactNativeBuild());
  const client = new PusherClient(PROD_ENV.REVERB_APP_KEY, options);
  clients.push(client);
  return client;
}

beforeEach(() => {
  jest.useFakeTimers();
  FakeWebSocket.urls = [];
  (globalThis as { WebSocket: unknown }).WebSocket = FakeWebSocket;
});

afterEach(() => {
  for (const client of clients.splice(0)) client.disconnect();
  jest.clearAllTimers();
  jest.useRealTimers();
  (globalThis as { WebSocket: unknown }).WebSocket = originalWebSocket;
});

describe('pusher-js React Native build', () => {
  it('exports the constructor as a named `Pusher`, without a default export', () => {
    const mod = loadReactNativeBuild() as { Pusher?: unknown; default?: unknown };
    expect(typeof mod).toBe('object');
    expect(typeof mod.Pusher).toBe('function');
    expect(mod.default).toBeUndefined();
  });

  it('resolves to a working constructor', () => {
    expect(() => resolvePusherConstructor(loadReactNativeBuild())).not.toThrow();
  });

  it('connects over wss://api.petprep.si:443/app/{key} with the production options', () => {
    const client = connect(buildPusherOptions(PROD_ENV, authorize));
    jest.advanceTimersByTime(0);

    expect(client.connection.state).toBe('connecting');
    expect(FakeWebSocket.urls[0]).toMatch(/^wss:\/\/api\.petprep\.si:443\/app\/app-key\?protocol=7/);
  });

  it("fails immediately with enabledTransports ['wss'] alone (the old config)", () => {
    const client = connect({ ...buildPusherOptions(PROD_ENV, authorize), enabledTransports: ['wss'] });

    expect(client.connection.state).toBe('failed');
    expect(FakeWebSocket.urls).toHaveLength(0);
  });

  it('uses plain ws for local http development', () => {
    connect(buildPusherOptions({ ...PROD_ENV, REVERB_HOST: '10.0.2.2', REVERB_PORT: 8080, REVERB_SCHEME: 'http' }, authorize));
    jest.advanceTimersByTime(0);

    expect(FakeWebSocket.urls[0]).toMatch(/^ws:\/\/10\.0\.2\.2:8080\/app\/app-key/);
  });
});

describe('resolvePusherConstructor', () => {
  class Fake {}

  it('accepts the class itself (web / CJS default)', () => {
    expect(resolvePusherConstructor(Fake)).toBe(Fake);
  });

  it('accepts { Pusher } (React Native build)', () => {
    expect(resolvePusherConstructor({ Pusher: Fake })).toBe(Fake);
  });

  it('accepts { default } (ESM interop)', () => {
    expect(resolvePusherConstructor({ default: Fake })).toBe(Fake);
  });

  it('accepts a default that wraps { Pusher }', () => {
    expect(resolvePusherConstructor({ default: { Pusher: Fake } })).toBe(Fake);
  });

  it('throws a clear error for anything else', () => {
    expect(() => resolvePusherConstructor({})).toThrow(/no Pusher constructor/);
    expect(() => resolvePusherConstructor(undefined)).toThrow(/no Pusher constructor/);
  });
});
