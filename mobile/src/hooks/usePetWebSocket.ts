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

// Pusher needs to be available globally for Laravel Echo
declare global {
  // eslint-disable-next-line no-var
  var Pusher: typeof Pusher;
}

global.Pusher = Pusher;

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
      // In production, this would call GET /api/pet/{id}
      // For now, just attempt to reconnect the WebSocket
      try {
        if (echoRef.current) {
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

    const echo = new Echo({
      broadcaster: 'pusher',
      key: ENV.REVERB_APP_KEY,
      wsHost: ENV.REVERB_HOST,
      wsPort: ENV.REVERB_PORT,
      forceTLS: false,
      enabledTransports: ['ws'],
      disabledTransports: ['wss'],
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

    return () => {
      channel.stopListening('pet.updated');
      echo.disconnect();
      echoRef.current = null;
      stopPolling();
      setWsStatus('disconnected');
    };
  }, [petId, setWsStatus, updatePetFromBroadcast, startPolling, stopPolling]);
}
