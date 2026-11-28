import { useEffect, useState } from 'react';

import type { WebSocketStatus } from '@/store/appStore';
import { WS_OFFLINE_DEBOUNCE_MS } from '@/modules/hud/wsBadge';

/**
 * True once the socket has been not connected for `delayMs` without a break (David 2026-11-28):
 * the header's offline icon. A reconnect within the delay never shows it; connected hides it at once.
 */
export function useWsOfflineIndicator(status: WebSocketStatus, delayMs: number = WS_OFFLINE_DEBOUNCE_MS): boolean {
  const connected = status === 'connected';
  const [offline, setOffline] = useState(false);
  useEffect(() => {
    if (connected) {
      setOffline(false);
      return undefined;
    }
    const id = setTimeout(() => setOffline(true), delayMs);
    return () => clearTimeout(id);
  }, [connected, delayMs]);
  return offline && !connected;
}
