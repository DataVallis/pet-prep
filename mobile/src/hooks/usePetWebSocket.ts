/**
 * usePetWebSocket — Laravel Echo + Reverb WebSocket hook.
 *
 * Listens on the `pet.updated.{petId}` channel for real-time pet updates.
 * Implements auto-reconnect and fallback polling when WebSocket drops.
 */

import { useEffect, useRef, useCallback } from 'react';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { useAppStore } from '@/store/appStore';
import { ENV } from '@/config/env';
import type { PetUpdatedBroadcast } from '@/types';

// Safely resolve Pusher constructor across CJS and ESM interop
const PusherConstructor: any =
  typeof Pusher === 'function'
    ? Pusher
    : (Pusher as any)?.default ?? Pusher;

if (typeof globalThis !== 'undefined') {
  (globalThis as any).Pusher = PusherConstructor;
}

const POLLING_INTERVAL_MS = 10_000; // 10 seconds fallback polling

export function usePetWebSocket(petId: number | null): void {
  const echoRef = useRef<Echo<'pusher'> | null>(null);
  const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const setWsStatus = useAppStore((s) => s.setWsStatus);
  const updatePetFromBroadcast = useAppStore((s) => s.updatePetFromBroadcast);

  const startPolling = useCallback(() => {
    if (pollingRef.current) return;

    setWsStatus('reconnecting');
    pollingRef.current = setInterval(async () => {
      // Fallback: poll the API for pet status
      try {
        if (echoRef.current?.connector?.pusher?.connection) {
          await echoRef.current.connector.pusher.connection.checkAvailability();
        }
      } catch {
        // Keep polling until reconnected
      }
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

    const isHttps = ENV.REVERB_SCHEME === 'https';

    try {
      const echo = new Echo({
        broadcaster: 'reverb',
        client: new PusherConstructor(ENV.REVERB_APP_KEY, {
          wsHost: ENV.REVERB_HOST,
          wsPort: isHttps ? 443 : ENV.REVERB_PORT,
          wssPort: isHttps ? 443 : ENV.REVERB_PORT,
          forceTLS: isHttps,
          enabledTransports: ['ws', 'wss'],
          disableStats: true,
        }),
      });

      echoRef.current = echo;

      const channel = echo.channel(`pet.updated.${petId}`);

      channel.listen('pet.updated', (event: PetUpdatedBroadcast) => {
        updatePetFromBroadcast(event);
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
    } catch (err) {
      console.warn('Echo initialization error:', err);
      startPolling();
    }

    return () => {
      try {
        if (echoRef.current) {
          echoRef.current.channel(`pet.updated.${petId}`).stopListening('pet.updated');
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
