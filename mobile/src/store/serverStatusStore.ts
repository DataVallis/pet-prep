/**
 * Server reachability (M5-F13) — UI state only: whether the "Updating PetPrep" banner
 * shows. The API client reports every answer (`noteServerAnswered`) and every outage
 * failure (`noteOutageFailure`); screens read `outage`. Kept apart from `appStore` so the
 * API client can import it without pulling in the session store.
 */

import { create } from 'zustand';

import {
  afterOutageFailure,
  afterServerAnswered,
  HEALTHY,
  outageCauseOfError,
  type OutageCause,
  type ServerStatus,
} from '@/modules/connectivity/serverOutage';

interface ServerStatusStore extends ServerStatus {
  noteOutageFailure: (cause: OutageCause, retryAfterMs: number | null, nowMs?: number) => void;
  noteServerAnswered: () => void;
  reset: () => void;
}

function statusOf(state: ServerStatusStore): ServerStatus {
  const { outage, cause, failures, since, retryAfterMs } = state;
  return { outage, cause, failures, since, retryAfterMs };
}

export const useServerStatusStore = create<ServerStatusStore>()((set, get) => ({
  ...HEALTHY,
  noteOutageFailure: (cause, retryAfterMs, nowMs = Date.now()) => {
    set(afterOutageFailure(statusOf(get()), cause, retryAfterMs, nowMs));
  },
  noteServerAnswered: () => {
    const current = statusOf(get());
    const next = afterServerAnswered(current);
    if (next !== current) set(next);
  },
  reset: () => set(HEALTHY),
}));

/** The banner shows right now (non-React callers: action texts, tests). */
export function isServerOutage(): boolean {
  return useServerStatusStore.getState().outage;
}

/** `true` while the server is updating / unreachable (re-renders on change). */
export function useServerOutage(): boolean {
  return useServerStatusStore((s) => s.outage);
}

/**
 * A failure caused by a deploy (M5-F13): a maintenance 503 / gateway 502 / 504 answer, or
 * a network failure while the banner already shows (the deploy's container restart). A
 * network failure on its own may be the phone without internet — that keeps the usual
 * short retry and the offline texts.
 */
export function isDeployError(error: unknown): boolean {
  const cause = outageCauseOfError(error);
  if (cause === 'maintenance' || cause === 'gateway') return true;
  return cause === 'network' && isServerOutage();
}
