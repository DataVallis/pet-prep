/**
 * M5-08 H6 (VALIDATION_PLAN §4.1, `docs/engineering/VALIDATION_API.md` §2): »did the parent look at the dashboard?« —
 * `POST /api/parent/dashboard-opened {trigger}` when the parent app comes into view after a cold
 * launch (`launch`), a return to the foreground (`foreground`) or a push tap (`push`).
 *
 * Rules (pure, Jest-tested with fake timers):
 *  - at most one call per parent per 30 minutes on this app run (the server keeps ≤ 1 row per
 *    30 min too) — the dashboard's polling / refetches never reach this module;
 *  - `launch` / `foreground` wait `OPEN_GRACE_MS` so a push tap that caused the open wins the label;
 *    `push` is sent at once and cancels a waiting one;
 *  - fire and forget: the debounce clock is set BEFORE sending, a failure is swallowed and never
 *    retried (no loops, no error UI).
 */

import type { DashboardOpenTrigger } from '@/api/client';

export const DASHBOARD_OPEN_DEBOUNCE_MS = 30 * 60 * 1000;
/** A push tap and the AppState change come in either order within a few hundred ms. */
export const OPEN_GRACE_MS = 1500;

export interface OpenSignalDeps {
  send: (trigger: DashboardOpenTrigger) => Promise<unknown>;
  now: () => number;
  setTimer: (fn: () => void, ms: number) => unknown;
  clearTimer: (handle: unknown) => void;
}

/** Last send per parent id on this app run (in memory: a new process may send once more — the server dedupes). */
export class DashboardOpenReporter {
  private readonly lastSentAt = new Map<number, number>();

  constructor(private readonly deps: Pick<OpenSignalDeps, 'send' | 'now'>) {}

  /** True when the request was sent (not debounced). Never throws, never rejects. */
  report(userId: number, trigger: DashboardOpenTrigger): boolean {
    const now = this.deps.now();
    const last = this.lastSentAt.get(userId);
    if (last !== undefined && now - last < DASHBOARD_OPEN_DEBOUNCE_MS) return false;
    this.lastSentAt.set(userId, now);
    try {
      void Promise.resolve(this.deps.send(trigger)).catch(() => undefined);
    } catch {
      // silent by contract
    }
    return true;
  }

  reset(): void {
    this.lastSentAt.clear();
  }
}

/** One mounted parent app: turns mount / foreground / push events into at most one debounced report. */
export class DashboardOpenSignal {
  private pending: unknown = null;
  /** Admin switch »Validation signals« for this family: null = not known yet (dashboard still loading). */
  private enabled: boolean | null = null;
  /** An open that happened while the switch was still unknown — sent only if it resolves to on. */
  private deferred: DashboardOpenTrigger | null = null;

  constructor(
    private readonly userId: number,
    private readonly reporter: DashboardOpenReporter,
    private readonly deps: Pick<OpenSignalDeps, 'setTimer' | 'clearTimer'>,
  ) {}

  /**
   * QA #1500 m4: the switch as the app knows it. Only the FIRST resolution (null → true) may send an open
   * that waited for it (the launch of this mount); a later flip (a refetch turning it on) never sends a
   * `launch` — the next real foreground / push does.
   */
  setEnabled(next: boolean | null): void {
    const before = this.enabled;
    this.enabled = next;
    if (before !== null || next === null) return;
    const waiting = this.deferred;
    this.deferred = null;
    if (next && waiting !== null) this.reporter.report(this.userId, waiting);
  }

  /** The parent app mounted (cold launch, sign-in, back from a child profile). */
  mounted(pushPending: boolean): void {
    if (pushPending) this.push();
    else this.later('launch');
  }

  /** AppState background → (inactive →) active — never inactive → active alone (iOS control centre is no new open). */
  foreground(): void {
    this.later('foreground');
  }

  /** A tapped push routed to the parent dashboard. */
  push(): void {
    this.cancel();
    this.emit('push');
  }

  dispose(): void {
    this.cancel();
  }

  private later(trigger: DashboardOpenTrigger): void {
    if (this.pending !== null) return;
    this.pending = this.deps.setTimer(() => {
      this.pending = null;
      this.emit(trigger);
    }, OPEN_GRACE_MS);
  }

  /** On → report; still unknown → wait for the switch (a push wins over a waiting launch); off → nothing. */
  private emit(trigger: DashboardOpenTrigger): void {
    if (this.enabled === true) {
      this.reporter.report(this.userId, trigger);
    } else if (this.enabled === null) {
      if (this.deferred === null || trigger === 'push') this.deferred = trigger;
    }
  }

  private cancel(): void {
    if (this.pending === null) return;
    this.deps.clearTimer(this.pending);
    this.pending = null;
  }
}
