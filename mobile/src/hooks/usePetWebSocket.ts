/**
 * usePetWebSocket — Laravel Echo + Reverb WebSocket hook.
 *
 * Subscribes to the PRIVATE channel `private-pet.{petId}` (M1-08). pusher-js
 * gets its subscription signature from `POST /api/broadcasting/auth` with the
 * Sanctum Bearer token (`modules/realtime/echoConfig`). Only the child who
 * owns the pet and that child's parent are authorized.
 * Implements auto-reconnect and a fallback polling slot when the socket drops.
 */

import { useEffect, useRef, useCallback } from 'react';
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

const POLLING_INTERVAL_MS = 10_000; // 10 seconds fallback polling

export function usePetWebSocket(petId: number | null): void {
  const echoRef = useRef<Echo<'reverb'> | null>(null);
  const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const setWsStatus = useAppStore((s) => s.setWsStatus);
  const updatePetFromBroadcast = useAppStore((s) => s.updatePetFromBroadcast);

  const startPolling = useCallback(() => {
    if (pollingRef.current) return;

    setWsStatus('reconnecting');
    pollingRef.current = setInterval(() => {
      // TODO(M1-15): refetch pet state via TanStack Query (`GET /api/child/pet`)
      // while the socket is down. pusher-js reconnects on its own; the
      // 'connected' handler below calls stopPolling().
    }, POLLING_INTERVAL_MS);
  }, [setWsStatus]);

  const stopPolling = useCallback(() => {
    if (pollingRef.current) {
      clearInterval(pollingRef.current);
      pollingRef.current = null;
    }
  }, []);

  useEffect(() => {
    if (!petId) return;

    setWsStatus('connecting');
    const channelName = petChannelName(petId);

    try {
      const echo = new Echo<'reverb'>({
        broadcaster: 'reverb',
        client: new PusherClient(ENV.REVERB_APP_KEY, buildPusherOptions(ENV, api.authorizeChannel)),
      });

      echoRef.current = echo;

      const channel = echo.private(channelName);

      // Leading dot: the backend uses a custom name (PetUpdated::broadcastAs).
      channel.listen(PET_UPDATED_EVENT, (event: PetUpdatedBroadcast) => {
        updatePetFromBroadcast(event);
      });

      // 403 (not this user's pet) / auth error → rely on polling.
      channel.error(() => {
        startPolling();
      });

      // Connection state handlers
      echo.connector.pusher.connection.bind('connected', () => {
        setWsStatus('connected');
        stopPolling();
      });

      echo.connector.pusher.connection.bind('disconnected', () => {
        setWsStatus('disconnected');
        startPolling();
      });

      echo.connector.pusher.connection.bind('failed', () => {
        setWsStatus('disconnected');
        startPolling();
      });
    } catch {
      startPolling();
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
      stopPolling();
      setWsStatus('disconnected');
    };
  }, [petId, setWsStatus, updatePetFromBroadcast, startPolling, stopPolling]);
}
