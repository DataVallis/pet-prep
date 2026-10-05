/**
 * Realtime (Laravel Reverb via pusher-js) configuration — M1-08 / M1-15.
 *
 * - Pet updates arrive on the PRIVATE channel `private-pet.{petId}`
 *   (`echo.private('pet.{petId}')`). The backend authorizes only the child
 *   who owns the pet and that child's parent.
 * - pusher-js asks our authorizer for a signature; the authorizer calls
 *   `api.authorizeChannel()` → `POST {API}/api/broadcasting/auth` with the
 *   Sanctum Bearer token (read fresh on every subscribe, 401 → session
 *   logout handler, like every other API call).
 * - Production uses wss on 443 (forceTLS); ws only for local http dev.
 *
 * Pure functions, no React — unit-tested in `__tests__/echoConfig.test.ts`.
 */

import type {
  ChannelAuthorizationCallback,
  ChannelAuthorizationHandler,
  Options as PusherOptions,
} from 'pusher-js';

/** Anything with pusher-js' constructor signature (the real class, or a test double). */
export type PusherConstructor<T> = new (appKey: string, options: PusherOptions) => T;

/**
 * The pusher-js constructor from whatever `import Pusher from 'pusher-js'` produced.
 *
 * Metro resolves pusher-js' `react-native` build (`dist/react-native/pusher.js`), which
 * ends in `module.exports.Pusher = Pusher` — no default export and no `__esModule`.
 * Babel's interop then makes the default import the whole exports object
 * `{ Pusher }`, so the old `.default` fallback produced `undefined`, `new` threw and
 * the hooks reported `disconnected` forever (TestFlight, 2026-10-05). The web / node
 * builds export the class directly or as `default` — all three shapes are accepted.
 */
export function resolvePusherConstructor<T>(imported: unknown): PusherConstructor<T> {
  if (typeof imported === 'function') return imported as PusherConstructor<T>;
  if (imported !== null && typeof imported === 'object') {
    const mod = imported as { Pusher?: unknown; default?: unknown };
    if (typeof mod.Pusher === 'function') return mod.Pusher as PusherConstructor<T>;
    if (typeof mod.default === 'function') return mod.default as PusherConstructor<T>;
    if (mod.default !== null && typeof mod.default === 'object') {
      return resolvePusherConstructor<T>(mod.default);
    }
  }
  throw new Error('pusher-js: no Pusher constructor found in the imported module.');
}

/** Event name the backend uses (`PetUpdated::broadcastAs`). Echo needs the leading dot for custom names. */
export const PET_UPDATED_EVENT = '.pet.updated';

/** The subset of ENV the realtime client needs. */
export interface RealtimeEnv {
  REVERB_APP_KEY: string;
  REVERB_HOST: string;
  REVERB_PORT: number;
  REVERB_SCHEME: string;
}

/** Signature from the backend's channel auth endpoint. */
export interface ChannelAuthorization {
  auth: string;
  channel_data?: string;
}

/** Asks the backend to sign a subscription (`api.authorizeChannel` in the app). */
export type ChannelAuthorize = (socketId: string, channelName: string) => Promise<ChannelAuthorization>;

/** Channel name for `echo.private()` — Echo adds the `private-` prefix. */
export function petChannelName(petId: number): string {
  return `pet.${petId}`;
}

/** Wire name pusher-js subscribes to. */
export function petPrivateChannelName(petId: number): string {
  return `private-${petChannelName(petId)}`;
}

/** wss (TLS) for https/wss, plain ws only for local http development. */
export function usesTls(env: Pick<RealtimeEnv, 'REVERB_SCHEME'>): boolean {
  return env.REVERB_SCHEME === 'https' || env.REVERB_SCHEME === 'wss';
}

/**
 * pusher-js `channelAuthorization.customHandler`. Errors (not signed in,
 * 401/403, network, odd body) go to the callback, so pusher-js reports a
 * subscription error instead of throwing.
 */
export function createChannelAuthorizer(authorize: ChannelAuthorize): ChannelAuthorizationHandler {
  return ({ socketId, channelName }, callback: ChannelAuthorizationCallback) => {
    authorize(socketId, channelName)
      .then((data) => {
        if (typeof data?.auth !== 'string' || data.auth.length === 0) {
          callback(new Error(`Channel authorization for ${channelName} returned no signature.`), null);
          return;
        }
        callback(null, data.channel_data ? { auth: data.auth, channel_data: data.channel_data } : { auth: data.auth });
      })
      .catch((err: unknown) => {
        callback(err instanceof Error ? err : new Error(String(err)), null);
      });
  };
}

/**
 * Transports pusher-js may use — ALWAYS both names, TLS is chosen by `forceTLS`.
 *
 * pusher-js 8 (`getDefaultStrategy`) with `useTLS` builds its only websocket strategy
 * from the transport NAMED `ws` (it connects to `wss://` because TLS is on); the
 * transport named `wss` exists only as a fallback in the non-TLS branch. With
 * `['wss']` alone and `forceTLS: true` every transport is disabled, the strategy is
 * unsupported and the connection goes straight to `failed` — the TestFlight build's
 * permanent "BREZ POVEZAVE" (2026-10-05). Laravel's Reverb docs use `['ws', 'wss']`.
 */
export const ENABLED_TRANSPORTS = ['ws', 'wss'] as const;

/**
 * pusher-js client options for Reverb. https → wss on the configured port
 * (443 behind Caddy in production) with TLS forced; http → ws (local dev).
 */
export function buildPusherOptions(env: RealtimeEnv, authorize: ChannelAuthorize): PusherOptions {
  const tls = usesTls(env);

  return {
    // Reverb ignores the cluster; pusher-js' type requires the field.
    cluster: '',
    wsHost: env.REVERB_HOST,
    wsPort: env.REVERB_PORT,
    wssPort: env.REVERB_PORT,
    forceTLS: tls,
    enabledTransports: [...ENABLED_TRANSPORTS],
    disableStats: true,
    channelAuthorization: {
      customHandler: createChannelAuthorizer(authorize),
    },
  };
}
