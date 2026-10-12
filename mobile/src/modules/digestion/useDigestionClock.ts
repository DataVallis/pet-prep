/**
 * Server "now" for the digestion note (M5-R16): re-renders exactly when the warning ends
 * (one timer), when a new snapshot arrives and when the app comes back from the background.
 * No polling and no refetch — the end is a fixed instant from the server.
 */

import { useEffect, useState } from 'react';
import { AppState } from 'react-native';

import type { ChildPetView } from '@/modules/childPet/childPetView';
import { digestionEndsInMs } from './digestion';

/** The end is re-checked this long after it (clock jitter between device and server). */
const BOUNDARY_SLACK_MS = 250;

/** setTimeout can't take more than ~24.8 days; the gap is minutes anyway. */
const MAX_TIMER_MS = 24 * 3_600_000;

export function useDigestionClock(view: ChildPetView | undefined): number {
  const [deviceNow, setDeviceNow] = useState(() => Date.now());
  const serverNow = deviceNow + (view?.clockSkewMs ?? 0);
  const delay = view ? digestionEndsInMs(view, serverNow) : null;

  useEffect(() => {
    if (delay === null) return;
    const timer = setTimeout(() => setDeviceNow(Date.now()), Math.min(delay + BOUNDARY_SLACK_MS, MAX_TIMER_MS));
    return () => clearTimeout(timer);
  }, [delay]);

  // A new snapshot (e.g. the feed response) is judged against the clock now.
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
