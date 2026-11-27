/** 2026-11-27 stall watchdog (pure): a loop whose time stands still while it should play is kicked. */
import { IDLE_WATCHDOG, STALL_AFTER_MS, STALL_CHECK_MS, watchdogStep, type WatchdogSample, type WatchdogState } from '@/modules/petMedia/playbackWatchdog';

const playing: WatchdogSample = { wanted: true, status: 'readyToPlay', playing: true, currentTime: 1 };

function run(samples: WatchdogSample[], start: WatchdogState = IDLE_WATCHDOG) {
  let state = start;
  return samples.map((sample, i) => {
    const step = watchdogStep(state, sample, i * STALL_CHECK_MS);
    state = step.state;
    return step.action;
  });
}

describe('watchdogStep', () => {
  it('an advancing loop is never kicked', () => {
    expect(run([0, 1, 2, 3, 4, 5].map((t) => ({ ...playing, currentTime: t * 0.9 })))).toEqual(['none', 'none', 'none', 'none', 'none', 'none']);
  });

  it('a loop seeking back to 0 at its end counts as moving', () => {
    expect(run([2.7, 0.1, 1.1, 2.1].map((t) => ({ ...playing, currentTime: t })))).toEqual(['none', 'none', 'none', 'none']);
  });

  it('paused by the system (playing false, time still) → resume after STALL_AFTER_MS, then every STALL_AFTER_MS', () => {
    const still = { ...playing, playing: false, currentTime: 2.4 };
    const actions = run(Array.from({ length: 8 }, () => still));
    const kicks = actions.map((a, i) => (a === 'none' ? null : i * STALL_CHECK_MS)).filter((v) => v !== null);
    expect(actions.filter((a) => a !== 'none')).toEqual(['resume', 'resume']);
    expect(kicks).toEqual([STALL_AFTER_MS, 2 * STALL_AFTER_MS]);
  });

  it('»playing« but the time stands → restart', () => {
    expect(run(Array.from({ length: 4 }, () => playing))).toEqual(['none', 'none', 'none', 'restart']);
  });

  it('not wanted / not ready / no time → never kicked, and the clock restarts', () => {
    const still = { ...playing, playing: false };
    expect(run([still, still, { ...still, wanted: false }, still, still, still])).toEqual(['none', 'none', 'none', 'none', 'none', 'none']);
    expect(run([{ ...still, status: 'loading' }, { ...still, status: 'loading' }, { ...still, status: 'loading' }, { ...still, status: 'loading' }])).toEqual([
      'none',
      'none',
      'none',
      'none',
    ]);
    expect(watchdogStep(IDLE_WATCHDOG, { ...still, currentTime: Number.NaN }, 0)).toEqual({ state: IDLE_WATCHDOG, action: 'none' });
  });

  it('tiny jitter below the epsilon counts as standing still', () => {
    expect(run([1, 1.001, 1.002, 1.003].map((t) => ({ ...playing, playing: false, currentTime: t })))).toEqual(['none', 'none', 'none', 'resume']);
  });
});
