/**
 * Server "now" for the play UI (M5-R05): re-renders exactly when the happy scene ends or
 * the shown invitation expires (`nextPlayChangeMs`, one timer), and when the app comes
 * back from the background. No polling — the server's broadcasts bring everything else.
 */

import { useEffect, useState } from 'react';
import { AppState } from 'react-native';

import type { ChildPetView } from '@/modules/childPet/childPetView';
import { nextPlayChangeMs } from '@/modules/play/play';

/** A boundary is re-checked this long after it (clock jitter between device and server). */
const BOUNDARY_SLACK_MS = 250;

export function usePlayClock(view: ChildPetView | undefined): number {
  const [deviceNow, setDeviceNow] = useState(() => Date.now());
  const skew = view?.clockSkewMs ?? 0;
  const serverNow = deviceNow + skew;
  const delay = view ? nextPlayChangeMs(view, serverNow) : null;

  useEffect(() => {
    if (delay === null) return;
    const timer = setTimeout(() => setDeviceNow(Date.now()), delay + BOUNDARY_SLACK_MS);
    return () => clearTimeout(timer);
  }, [delay]);

  // A new snapshot (e.g. an optimistic happy state) is judged against the clock now.
  useEffect(() => {
    setDeviceNow(Date.now());
  }, [view]);

  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active') setDeviceNow(Date.now());
    });
    return () => sub.remove();
  }, []);

  return serverNow;
}
