/**
 * M5-F04 — honest dog-school result title (David 2026-10-07 21:35): ratio of praises on
 * time to all commands of the session. 1 → "Odlično!"; > ½ → "Dobro!"; > 0 (incl. exactly
 * ½) → "Še malo vaje — jutri bo bolje"; 0 → "Tokrat ni šlo, poskusi jutri".
 */
import { i18n } from '@/i18n';
import { resultTitle } from '@/modules/training/TrainingOverlay';
import { readTrainingResult, trainingResultBucket, type TrialOutcome, type TrialResult } from '@/modules/training/training';
import { makeTrainingResult } from '@/test-utils/fixtures';

function trials(outcomes: TrialOutcome[]): TrialResult[] {
  return outcomes.map((outcome, index) => ({
    index,
    obeys: outcome !== 'waited' && outcome !== 'praised_without_obeying',
    outcome,
    tap_ms: null,
  }));
}

/** A result with `onTime` in-time praises out of `total` commands (the rest too late). */
function result(onTime: number, total: number) {
  const outcomes: TrialOutcome[] = Array.from({ length: total }, (_, i) => (i < onTime ? 'in_time' : 'too_late'));
  return { successes: onTime, trials: trials(outcomes) };
}

describe('trainingResultBucket (M5-F04)', () => {
  it.each([
    ['all on time (8/8)', 8, 8, 'excellent'],
    ['all on time (1/1)', 1, 1, 'excellent'],
    ['more than half (5/8)', 5, 8, 'good'],
    ['more than half (7/8)', 7, 8, 'good'],
    ['more than half, odd total (2/3)', 2, 3, 'good'],
    ['exactly half (4/8) → practice', 4, 8, 'practice'],
    ['exactly half (1/2) → practice', 1, 2, 'practice'],
    ['less than half (3/8)', 3, 8, 'practice'],
    ['one (1/8)', 1, 8, 'practice'],
    ['zero (0/8)', 0, 8, 'none'],
  ] as const)('%s', (_label, onTime, total, bucket) => {
    expect(trainingResultBucket(result(onTime, total))).toBe(bucket);
  });

  it('no commands at all (total 0) → none, even with a stray success count', () => {
    expect(trainingResultBucket({ successes: 0, trials: [] })).toBe('none');
    expect(trainingResultBucket({ successes: 3, trials: [] })).toBe('none');
  });

  it('more successes than commands (malformed) is capped at all → excellent, never above', () => {
    expect(trainingResultBucket({ successes: 9, trials: trials(['in_time', 'in_time']) })).toBe('excellent');
    expect(trainingResultBucket({ successes: -2, trials: trials(['too_early']) })).toBe('none');
  });

  it("David's device case: 1 on time, 3× didn't obey, 2× too early → practice, not \"Great job\"", () => {
    const device = {
      successes: 1,
      trials: trials(['in_time', 'praised_without_obeying', 'too_early', 'praised_without_obeying', 'too_early', 'praised_without_obeying']),
    };
    expect(trainingResultBucket(device)).toBe('practice');
  });

  it('commands the dog did not obey count in the total (waiting is right, but it is not a praise on time)', () => {
    // 5 obeyed and praised on time + 3 correctly waited = 5 / 8 → good, not excellent.
    const r = { successes: 5, trials: trials(['in_time', 'waited', 'in_time', 'in_time', 'waited', 'in_time', 'in_time', 'waited']) };
    expect(trainingResultBucket(r)).toBe('good');
  });

  it('reads the server payload (fixture: 3 on time of 8 commands → practice)', () => {
    const parsed = readTrainingResult(makeTrainingResult());
    expect(parsed).not.toBeNull();
    expect(trainingResultBucket(parsed!)).toBe('practice');
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
