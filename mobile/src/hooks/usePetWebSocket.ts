/**
 * usePetWebSocket — Laravel Echo + Reverb WebSocket hook.
 *
 * Subscribes to the PRIVATE channel `private-pet.{petId}` (M1-08). pusher-js
 * gets its subscription signature from `POST /api/broadcasting/auth` with the
 * Sanctum Bearer token (`modules/realtime/echoConfig`).
 *
 * The hook only delivers events and reports `wsStatus`; `connected` means the
 * channel subscription succeeded. Anything else makes `useChildPet` poll
 * `GET /api/child/pet` every 10 s (M1-15 fallback) until the socket is back
 * (pusher-js reconnects on its own).
 *
 * Events older than the last delivered one (`emitted_at`) are dropped here, so
 * no consumer sees a stale snapshot after a newer one.
 */

import { useEffect, useRef } from 'react';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import type { Options as PusherOptions } from 'pusher-js';
import { useAppStore } from '@/store/appStore';
import { ENV } from '@/config/env';
import { api } from '@/api/client';
import { buildPusherOptions, petChannelName, PET_UPDATED_EVENT } from '@/modules/realtime/echoConfig';
import type { PetUpdatedBroadcast } from '@/types';

type PusherConstructor = new (appKey: string, options: PusherOptions) => Pusher;

// Safely resolve the Pusher constructor across CJS and ESM interop.
const pusherInterop: unknown = Pusher;
const PusherClient: PusherConstructor =
  typeof pusherInterop === 'function'
    ? (pusherInterop as PusherConstructor)
    : (pusherInterop as { default: PusherConstructor }).default;

export type BroadcastHandler = (event: PetUpdatedBroadcast) => void;

/**
 * Pass events through only if not older than the last one passed (by `emitted_at`).
 * Exported for tests.
 */
export function createBroadcastGate(): (event: PetUpdatedBroadcast) => boolean {
  let lastMs = 0;
  return (event) => {
    const ms = Date.parse(event.emitted_at);
    if (Number.isNaN(ms) || ms < lastMs) return false;
    lastMs = ms;
    return true;
  };
}

/**
 * @param onBroadcast where events go — the child HUD writes them into the TanStack
 *   cache; default (parent dashboard) maps them onto the session pet in the store.
 */
export function usePetWebSocket(petId: number | null, onBroadcast?: BroadcastHandler): void {
  const echoRef = useRef<Echo<'reverb'> | null>(null);
  const handlerRef = useRef<BroadcastHandler | undefined>(onBroadcast);
  handlerRef.current = onBroadcast;

  const setWsStatus = useAppStore((s) => s.setWsStatus);

  useEffect(() => {
    if (!petId) return;

    setWsStatus('connecting');
    const channelName = petChannelName(petId);
    const passes = createBroadcastGate();

    try {
      const echo = new Echo<'reverb'>({
        broadcaster: 'reverb',
        client: new PusherClient(ENV.REVERB_APP_KEY, buildPusherOptions(ENV, api.authorizeChannel)),
      });

      echoRef.current = echo;

      const channel = echo.private(channelName);

      // Leading dot: the backend uses a custom name (PetUpdated::broadcastAs).
      channel.listen(PET_UPDATED_EVENT, (event: PetUpdatedBroadcast) => {
        if (!passes(event)) return;
        const handler = handlerRef.current;
        if (handler) handler(event);
        else useAppStore.getState().updatePetFromBroadcast(event);
      });

      // Live only once the private channel is authorized (also after a reconnect).
      channel.subscribed(() => {
        setWsStatus('connected');
      });

      // 403 (not this user's pet) / auth error → polling keeps the state fresh.
      channel.error(() => {
        setWsStatus('reconnecting');
      });

      const connection = echo.connector.pusher.connection;
      connection.bind('disconnected', () => setWsStatus('disconnected'));
      connection.bind('unavailable', () => setWsStatus('reconnecting'));
      connection.bind('failed', () => setWsStatus('disconnected'));
    } catch {
      setWsStatus('disconnected');
    }

    return () => {
      try {
        if (echoRef.current) {
          echoRef.current.leave(channelName);
          echoRef.current.disconnect();
          echoRef.current = null;
        }
      } catch {
        // cleanup safety
      }
      setWsStatus('disconnected');
    };
  }, [petId, setWsStatus]);
}
