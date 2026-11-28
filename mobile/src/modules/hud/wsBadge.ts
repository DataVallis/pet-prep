/**
 * Realtime (websocket) status of the child HUD — pure, tested in `__tests__/wsBadge.test.ts`.
 *
 * David 2026-11-28: the green »V ŽIVO« pill in the header read like »the dog is alive«, not
 * »connected to the server«. So the status is a system line in the settings sheet, next to the
 * app version (»Povezava s strežnikom: v živo« / »povezujem …« / »samodejno osveževanje«), and
 * the header shows NOTHING while connected. Only when the socket has not been connected for
 * `WS_OFFLINE_DEBOUNCE_MS` (`useWsOfflineIndicator`) a small amber icon-only `WifiOff` appears
 * in the header (»Ni povezave s strežnikom«), so a child knows why nothing updates — brief
 * reconnects never flash. Without the socket `useChildPet` still polls every 10 s; a real API
 * outage is the amber »Ni povezave — prikazujem zadnje stanje.« banner. Never »BREZ POVEZAVE«.
 */

import type { WebSocketStatus } from '@/store/appStore';
import { strings } from '@/i18n/strings';

/** User-visible strings (`child:ws`, M1-18). */
export const WS_BADGE_STRINGS = strings('child', 'ws');

/** How long the socket must be not connected before the header shows the offline icon. */
export const WS_OFFLINE_DEBOUNCE_MS = 5_000;

export type WsTone = 'live' | 'connecting' | 'polling';

export interface WsStatusLine {
  tone: WsTone;
  /** »Povezava s strežnikom: v živo« (settings sheet, also its screen-reader label). */
  text: string;
}

export function wsTone(status: WebSocketStatus): WsTone {
  switch (status) {
    case 'connected':
      return 'live';
    case 'connecting':
      return 'connecting';
    default:
      return 'polling';
  }
}

/** The settings sheet's connection line. */
export function wsStatusLine(status: WebSocketStatus): WsStatusLine {
  const tone = wsTone(status);
  return { tone, text: `${WS_BADGE_STRINGS.label}: ${WS_BADGE_STRINGS[tone]}` };
}
