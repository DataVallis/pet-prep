/**
 * Server "now" for the summer heat note (M5-R14): re-renders exactly when the midday window
 * opens or closes (one timer), on a new snapshot and when the app comes back to the
 * foreground. No polling — the window is fixed instants from the server.
 */

import { useEffect, useState } from 'react';
import { AppState } from 'react-native';

import type { ChildPetView } from '@/modules/childPet/childPetView';
import { nextHeatChangeMs } from './careTasks';

const BOUNDARY_SLACK_MS = 250;
const MAX_TIMER_MS = 24 * 3_600_000;

export function useHeatClock(view: ChildPetView | undefined): number {
  const [deviceNow, setDeviceNow] = useState(() => Date.now());
  const skew = view?.clockSkewMs ?? 0;
  const serverNow = deviceNow + skew;
  const next = view ? nextHeatChangeMs(view.walkHeat, serverNow) : null;
  const delay = next !== null ? next - serverNow : null;

  useEffect(() => {
    if (delay === null) return;
    const timer = setTimeout(() => setDeviceNow(Date.now()), Math.min(Math.max(0, delay) + BOUNDARY_SLACK_MS, MAX_TIMER_MS));
    return () => clearTimeout(timer);
  }, [delay]);

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
