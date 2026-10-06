/**
 * Server "now" in ms (device clock + `skewMs`), re-rendering every `intervalMs` while
 * `enabled`. For calm countdowns ("Kuža bo moral ven čez ~1 h 20 min", M5-R02) that only
 * need minute precision — computed from the wall clock on every tick, so a backgrounded
 * app is right again as soon as it ticks.
 */

import { useEffect, useState } from 'react';
import { AppState } from 'react-native';

export const SERVER_NOW_TICK_MS = 15_000;

export function useServerNow(skewMs: number, enabled: boolean, intervalMs: number = SERVER_NOW_TICK_MS): number {
  const [deviceNow, setDeviceNow] = useState(() => Date.now());

  useEffect(() => {
    if (!enabled) return;
    setDeviceNow(Date.now());
    const id = setInterval(() => setDeviceNow(Date.now()), intervalMs);
    // Back from the background: re-read the clock at once (timers don't run there).
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active') setDeviceNow(Date.now());
    });
    return () => {
      clearInterval(id);
      sub.remove();
    };
  }, [enabled, intervalMs]);

  return deviceNow + skewMs;
}
