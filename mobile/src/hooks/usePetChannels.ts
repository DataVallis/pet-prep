/**
 * usePetChannels — the parent's live connection (M2-05 review M2): ONE Echo /
 * websocket with one private channel per family pet (`private-pet.{id}`), and a
 * status per pet. `wsStatus` in the store is `connected` only when EVERY subscribed
 * pet's channel is connected — one failed channel (403, auth error) keeps the
 * dashboard polling, so that pet never goes stale while another pet is live.
 *
 * Events older than the last one of the same pet (`emitted_at`) are dropped.
 */

import { useEffect, useRef } from 'react';

import { createBroadcastGate, createEcho, type BroadcastHandler, type ResubscribeHandler } from '@/hooks/usePetWebSocket';
import { createSubscriptionTracker, petChannelName, PET_UPDATED_EVENT } from '@/modules/realtime/echoConfig';
import { useAppStore, type WebSocketStatus } from '@/store/appStore';
import type { PetUpdatedBroadcast } from '@/types';

/** All channels connected → connected; any still connecting (none failed) → connecting; else reconnecting / disconnected. */
export function aggregateChannelStatus(statuses: WebSocketStatus[]): WebSocketStatus {
  if (statuses.length === 0) return 'disconnected';
  if (statuses.every((s) => s === 'connected')) return 'connected';
  if (statuses.some((s) => s === 'disconnected')) return 'disconnected';
  if (statuses.some((s) => s === 'reconnecting')) return 'reconnecting';
  return 'connecting';
}

/** Stable key for a set of pet ids (order-insensitive). */
function idsKey(petIds: number[]): string {
  return [...new Set(petIds)].sort((a, b) => a - b).join(',');
}

export function usePetChannels(petIds: number[], onBroadcast: BroadcastHandler, onResubscribed?: ResubscribeHandler): void {
  const handlerRef = useRef<BroadcastHandler>(onBroadcast);
  handlerRef.current = onBroadcast;
  const resubscribedRef = useRef<ResubscribeHandler | undefined>(onResubscribed);
  resubscribedRef.current = onResubscribed;
  const setWsStatus = useAppStore((s) => s.setWsStatus);
  const key = idsKey(petIds);

  useEffect(() => {
    const ids = key === '' ? [] : key.split(',').map(Number);
    if (ids.length === 0) {
      setWsStatus('disconnected');
      return;
    }

    const statuses = new Map<number, WebSocketStatus>(ids.map((id) => [id, 'connecting']));
    const publish = () => setWsStatus(aggregateChannelStatus([...statuses.values()]));
    const setAll = (status: WebSocketStatus) => {
      for (const id of ids) statuses.set(id, status);
      publish();
    };
    publish();

    let echo: ReturnType<typeof createEcho> | null = null;
    let active = true;
    try {
      echo = createEcho({ isActive: () => active });
      for (const id of ids) {
        const passes = createBroadcastGate();
        const tracker = createSubscriptionTracker();
        const channel = echo.private(petChannelName(id));
        channel.listen(PET_UPDATED_EVENT, (event: PetUpdatedBroadcast) => {
          if (passes(event)) handlerRef.current(event);
        });
        // Fires again after every reconnect (pusher re-subscribes).
        channel.subscribed(() => {
          statuses.set(id, 'connected');
          publish();
          // M5-F13: a re-subscription after a reconnect — events of the gap are lost.
          if (tracker.subscribed()) resubscribedRef.current?.(id);
        });
        channel.error(() => {
          statuses.set(id, 'reconnecting');
          publish();
        });
      }
      const connection = echo.connector.pusher.connection;
      connection.bind('disconnected', () => setAll('disconnected'));
      connection.bind('unavailable', () => setAll('reconnecting'));
      connection.bind('failed', () => setAll('disconnected'));
    } catch {
      setWsStatus('disconnected');
    }

    return () => {
      active = false;
      try {
        if (echo) {
          for (const id of ids) echo.leave(petChannelName(id));
          echo.disconnect();
        }
      } catch {
        // cleanup safety
      }
      setWsStatus('disconnected');
    };
  }, [key, setWsStatus]);
}
