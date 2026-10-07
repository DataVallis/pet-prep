/**
 * Realtime status badge in the child HUD header — pure, tested in `__tests__/wsBadge.test.ts`.
 *
 * A red "BREZ POVEZAVE" scared kids although nothing was wrong for them: without the
 * websocket `useChildPet` polls every 10 s and the pet stays up to date. So:
 * live → green dot "V ŽIVO", connecting → amber dot "POVEZUJEM", anything else →
 * a small grey "auto refresh" icon without text. A real outage (API unreachable) is
 * still shown by the amber "Ni povezave — prikazujem zadnje stanje." banner.
 */

import type { WebSocketStatus } from '@/store/appStore';
import { strings } from '@/i18n/strings';

/** User-visible strings (`child:ws`, M1-18); `polling` is screen-reader only (the badge shows just an icon). */
export const WS_BADGE_STRINGS = strings('child', 'ws');

export type WsBadge =
  | { tone: 'live'; label: string; accessibilityLabel: string }
  | { tone: 'connecting'; label: string; accessibilityLabel: string }
  | { tone: 'polling'; label: null; accessibilityLabel: string };

export function wsBadge(status: WebSocketStatus): WsBadge {
  switch (status) {
    case 'connected':
      return { tone: 'live', label: WS_BADGE_STRINGS.live, accessibilityLabel: WS_BADGE_STRINGS.live };
    case 'connecting':
      return { tone: 'connecting', label: WS_BADGE_STRINGS.connecting, accessibilityLabel: WS_BADGE_STRINGS.connecting };
    default:
      return { tone: 'polling', label: null, accessibilityLabel: WS_BADGE_STRINGS.polling };
  }
}
