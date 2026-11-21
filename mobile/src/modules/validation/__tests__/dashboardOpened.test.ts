import type { DashboardOpenTrigger } from '@/api/client';

import { DASHBOARD_OPEN_DEBOUNCE_MS, DashboardOpenReporter, DashboardOpenSignal, OPEN_GRACE_MS } from '../dashboardOpened';

function setup(send: (t: DashboardOpenTrigger) => Promise<unknown> = () => Promise.resolve(null)) {
  const sent: DashboardOpenTrigger[] = [];
  const reporter = new DashboardOpenReporter({
    send: (trigger) => {
      sent.push(trigger);
      return send(trigger);
    },
    now: () => Date.now(),
  });
  const timers = { setTimer: (fn: () => void, ms: number) => setTimeout(fn, ms), clearTimer: (h: unknown) => clearTimeout(h as ReturnType<typeof setTimeout>) };
  const signal = (userId = 1) => new DashboardOpenSignal(userId, reporter, timers);
  return { sent, reporter, signal };
}

beforeEach(() => {
  jest.useFakeTimers();
  jest.setSystemTime(new Date('2026-11-21T08:00:00Z'));
});

afterEach(() => {
  jest.useRealTimers();
});

describe('H6 dashboard-opened signal', () => {
  it('a cold launch reports `launch` after the grace period', () => {
    const { sent, signal } = setup();
    signal().mounted(false);
    expect(sent).toEqual([]);
    jest.advanceTimersByTime(OPEN_GRACE_MS);
    expect(sent).toEqual(['launch']);
  });

  it('debounces 30 minutes per parent', () => {
    const { sent, signal } = setup();
    const s = signal();
    s.mounted(false);
    jest.advanceTimersByTime(OPEN_GRACE_MS);
    s.foreground();
    jest.advanceTimersByTime(OPEN_GRACE_MS);
    s.push();
    expect(sent).toEqual(['launch']);

    jest.advanceTimersByTime(DASHBOARD_OPEN_DEBOUNCE_MS);
    s.foreground();
    jest.advanceTimersByTime(OPEN_GRACE_MS);
    expect(sent).toEqual(['launch', 'foreground']);
  });

  it('another parent on the same phone has their own clock', () => {
    const { sent, signal } = setup();
    signal(1).push();
    signal(2).push();
    expect(sent).toEqual(['push', 'push']);
  });

  it('a push tap during the grace period wins the label', () => {
    const { sent, signal } = setup();
    const s = signal();
    s.foreground();
    jest.advanceTimersByTime(OPEN_GRACE_MS / 2);
    s.push();
    jest.advanceTimersByTime(OPEN_GRACE_MS);
    expect(sent).toEqual(['push']);
  });

  it('mounting with a pending push reports `push` at once', () => {
    const { sent, signal } = setup();
    signal().mounted(true);
    expect(sent).toEqual(['push']);
  });

  it('a failure is silent and never retried', async () => {
    const send = jest.fn(() => Promise.reject(new Error('offline')));
    const { sent, signal } = setup(send);
    const s = signal();
    s.push();
    await Promise.resolve();
    s.foreground();
    jest.advanceTimersByTime(OPEN_GRACE_MS * 10);
    expect(sent).toEqual(['push']);
  });

  it('a send that throws synchronously never escapes', () => {
    const reporter = new DashboardOpenReporter({
      send: () => {
        throw new Error('not a function');
      },
      now: () => 0,
    });
    expect(reporter.report(1, 'launch')).toBe(true);
  });

  it('dispose cancels a waiting report', () => {
    const { sent, signal } = setup();
    const s = signal();
    s.mounted(false);
    s.dispose();
    jest.advanceTimersByTime(OPEN_GRACE_MS * 2);
    expect(sent).toEqual([]);
  });
});
