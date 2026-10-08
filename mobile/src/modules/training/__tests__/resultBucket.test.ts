/**
 * M5-F04 — honest dog-school result title (David 2026-10-08 07:43): only the commands the
 * dog OBEYED count. All of them praised on time and no praise when it didn't obey →
 * "Odlično!"; > ½ → "Dobro!"; ≥ 1 (exactly ½ included) → "Še malo vaje — jutri bo bolje";
 * 0 (or the dog never obeyed) → "Tokrat ni šlo, poskusi jutri".
 */
import { i18n } from '@/i18n';
import { resultTitle } from '@/modules/training/TrainingOverlay';
import {
  readTrainingResult,
  trainingResultBucket,
  trainingResultCounts,
  type TrialOutcome,
  type TrialResult,
} from '@/modules/training/training';
import { makeTrainingResult } from '@/test-utils/fixtures';

const NOT_OBEYED: ReadonlySet<TrialOutcome> = new Set(['waited', 'praised_without_obeying']);

function trials(outcomes: TrialOutcome[]): TrialResult[] {
  return outcomes.map((outcome, index) => ({ index, obeys: !NOT_OBEYED.has(outcome), outcome, tap_ms: null }));
}

const r = (outcomes: TrialOutcome[]) => ({ trials: trials(outcomes) });
const W: TrialOutcome = 'waited';
const P: TrialOutcome = 'praised_without_obeying';
const OK: TrialOutcome = 'in_time';
const LATE: TrialOutcome = 'too_late';
const EARLY: TrialOutcome = 'too_early';

describe('trainingResultBucket (M5-F04, David 2026-10-08)', () => {
  it.each([
    ['4/4 on time with correct waits → excellent', [OK, W, OK, OK, W, OK, W, W], 'excellent'],
    ['1/1 on time → excellent', [OK, W, W], 'excellent'],
    ['4/4 on time + one praise without obeying → good (not excellent)', [OK, P, OK, OK, W, OK], 'good'],
    ['3/5 → good', [OK, W, OK, OK, W, LATE, EARLY, W], 'good'],
    ['2/3 → good', [OK, OK, LATE, W], 'good'],
    ['exactly half 2/4 → practice', [OK, W, OK, LATE, W, 'no_praise'], 'practice'],
    ['exactly half 1/2 → practice', [OK, LATE, W], 'practice'],
    ['one of five → practice', [OK, LATE, EARLY, 'no_praise', LATE, W], 'practice'],
    ['zero on time → none', [LATE, W, EARLY, 'no_praise', W], 'none'],
    ['dog never obeyed (obeyed 0) → none, even when the child waited perfectly', [W, W, W], 'none'],
    ['obeyed 0 with praises → none', [P, W, P], 'none'],
    ['no trials → none', [], 'none'],
  ] as const)('%s', (_label, outcomes, bucket) => {
    expect(trainingResultBucket(r([...outcomes]))).toBe(bucket);
  });

  it("David's device case: 1 on time, 3× praised without obeying, 2× too early → practice (1 of 3 obeyed)", () => {
    const device = r([OK, P, EARLY, P, EARLY, P]);
    expect(trainingResultCounts(device)).toEqual({ onTime: 1, obeyed: 3, praisedWithoutObeying: 3 });
    expect(trainingResultBucket(device)).toBe('practice');
  });

  it('counts come from the parsed trials: inflated server totals and unknown trials are ignored', () => {
    const parsed = readTrainingResult(
      makeTrainingResult({
        successes: 8,
        obeyed: 8,
        trials: [
          { index: 0, obeys: true, outcome: 'in_time', tap_ms: 2_000 },
          { index: 1, obeys: true, outcome: 'too_late', tap_ms: 9_000 },
          { index: 2, obeys: true, outcome: 'bogus', tap_ms: 1 },
          { index: 'x', obeys: true, outcome: 'in_time', tap_ms: 1 },
        ],
      }),
    );
    expect(parsed).not.toBeNull();
    expect(trainingResultCounts(parsed!)).toEqual({ onTime: 1, obeyed: 2, praisedWithoutObeying: 0 });
    expect(trainingResultBucket(parsed!)).toBe('practice');
  });

  it('the default fixture (3 on time of 5 obeyed, 3 waits) → good', () => {
    expect(trainingResultBucket(readTrainingResult(makeTrainingResult())!)).toBe('good');
  });
});

describe('resultTitle (M5-F04)', () => {
  it('Slovenian titles — kind, never false praise', () => {
    expect(resultTitle('excellent')).toBe('Odlično!');
    expect(resultTitle('good')).toBe('Dobro!');
    expect(resultTitle('practice')).toBe('Še malo vaje — jutri bo bolje');
    expect(resultTitle('none')).toBe('Tokrat ni šlo, poskusi jutri');
  });

  it('English titles', async () => {
    await i18n.changeLanguage('en');
    try {
      expect(resultTitle('excellent')).toBe('Excellent!');
      expect(resultTitle('good')).toBe('Good job!');
      expect(resultTitle('practice')).toBe('A bit more practice — tomorrow will be better');
      expect(resultTitle('none')).toBe("It didn't work this time — try again tomorrow");
    } finally {
      await i18n.changeLanguage('sl');
    }
  });
});
