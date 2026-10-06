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
import { useAppStore } from '@/store/appStore';
import { ENV } from '@/config/env';
import { api } from '@/api/client';
import { channelAuthGate, gatedCall } from '@/modules/childPet/refetchGovernor';
import {
  buildPusherOptions,
  petChannelName,
  PET_UPDATED_EVENT,
  resolvePusherConstructor,
} from '@/modules/realtime/echoConfig';
import type { PetUpdatedBroadcast } from '@/types';

export type BroadcastHandler = (event: PetUpdatedBroadcast) => void;

/** One Echo (= one websocket) for the app's Reverb, authorizing private channels with the Bearer token. */
export function createEcho(): Echo<'reverb'> {
  // Resolved per call (not at import): on device the import is `{ Pusher }` (RN build).
  const PusherClient = resolvePusherConstructor<Pusher>(Pusher);
  return new Echo<'reverb'>({
    broadcaster: 'reverb',
    // Gated: reconnect storms must not drain the per-user API limit (hotfix 2026-10-06).
    client: new PusherClient(
      ENV.REVERB_APP_KEY,
      buildPusherOptions(ENV, (socketId, channelName) =>
        gatedCall(channelAuthGate, () => api.authorizeChannel(socketId, channelName)),
      ),
    ),
  });
}

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
      const echo = createEcho();

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
