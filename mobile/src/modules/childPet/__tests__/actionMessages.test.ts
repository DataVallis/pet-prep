import { ApiError } from '@/api/client';
import {
  CHILD_ACTION_STRINGS,
  classifyActionError,
  failureMessage,
  feedHint,
  HUD_HINTS,
  lockMessage,
  refusalMessage,
  successMessage,
  waterHint,
} from '@/modules/childPet/actionMessages';
import { normalizeChildState } from '@/modules/childPet/childPetView';
import { makeLiveChildState } from '@/test-utils/fixtures';

const lj = normalizeChildState(makeLiveChildState());
const ny = normalizeChildState(
  makeLiveChildState({
    timezone: 'America/New_York',
    server_time: '2026-10-04T12:00:00-04:00',
    feeding: { next_feed_window: { start: '2026-10-04T17:00:00-04:00', end: '2026-10-04T21:00:00-04:00' } },
  }),
);

describe('classifyActionError', () => {
  it('422 → refused with reason, next time and state', () => {
    const state = makeLiveChildState();
    const failure = classifyActionError(
      new ApiError('x', 422, { status: 'refused', reason: 'water_too_soon', next_allowed_at: '2026-10-04T15:30:00+02:00', state }),
    );
    expect(failure).toEqual({ kind: 'refused', reason: 'water_too_soon', nextAllowedAt: '2026-10-04T15:30:00+02:00', state });
  });

  it('423 → locked with a known reason only', () => {
    expect(classifyActionError(new ApiError('x', 423, { reason: 'ill', locked_until: '2026-10-04T18:30:00+02:00' }))).toEqual({
      kind: 'locked',
      reason: 'ill',
      lockedUntil: '2026-10-04T18:30:00+02:00',
      state: null,
    });
    expect(classifyActionError(new ApiError('x', 423, { reason: 'weird' }))).toMatchObject({ kind: 'locked', reason: null });
  });

  it('network error → offline; 429 → throttled; 500 → failed', () => {
    expect(classifyActionError(new TypeError('Network request failed'))).toEqual({ kind: 'offline' });
    expect(classifyActionError(new ApiError('x', 429))).toEqual({ kind: 'throttled' });
    expect(classifyActionError(new ApiError('x', 500))).toEqual({ kind: 'failed', status: 500 });
  });
});

describe('refusalMessage (family timezone)', () => {
  it('outside_feed_window → "Naslednji obrok je ob 17:00"', () => {
    expect(refusalMessage('outside_feed_window', '2026-10-04T17:00:00+02:00', lj)).toBe('Naslednji obrok je ob 17:00. Če bo kuža zelo lačen, mu lahko daš nujni obrok.');
  });

  it('outside_feed_window without next_allowed_at uses the state window; New York family sees its own clock', () => {
    expect(refusalMessage('outside_feed_window', null, ny)).toBe('Naslednji obrok je ob 17:00. Če bo kuža zelo lačen, mu lahko daš nujni obrok.');
  });

  it('after the evening window → "jutri ob 06:00"', () => {
    const late = normalizeChildState(makeLiveChildState({ server_time: '2026-10-04T21:30:00+02:00' }));
    expect(refusalMessage('already_fed_this_window', '2026-10-05T06:00:00+02:00', late)).toBe(
      'Kuža je že jedel. Spet ga nahraniš jutri ob 06:00.',
    );
  });

  it('water_too_soon names the time; across midnight it reads as tomorrow', () => {
    expect(refusalMessage('water_too_soon', '2026-10-04T15:30:00+02:00', lj)).toBe(
      'Posoda je še polna. Novo vodo lahko daš ob 15:30.',
    );
    expect(refusalMessage('water_too_soon', '2026-10-05T01:00:00+02:00', lj)).toBe(CHILD_ACTION_STRINGS.refused.waterDailyLimit);
  });

  it('water_daily_limit, needs_cleaning, unknown', () => {
    expect(refusalMessage('water_daily_limit', '2026-10-05T00:00:00+02:00', lj)).toBe(
      'Danes je kuža popil dovolj vode. Nova voda jutri.',
    );
    expect(refusalMessage('needs_cleaning', null, lj)).toBe('Najprej pospravi za kužkom!');
    expect(refusalMessage('something_new', null, lj)).toBe(CHILD_ACTION_STRINGS.refused.other);
  });
});

describe('lockMessage', () => {
  it('hard stop, vet with time in family tz, game over, inactive; contract → null', () => {
    expect(lockMessage('hard_stopped', null, 'Europe/Ljubljana')).toBe('Starš je ustavil igro.');
    expect(lockMessage('ill', '2026-10-04T16:30:00Z', 'Europe/Ljubljana')).toBe('Kuža je pri veterinarju do 18:30.');
    expect(lockMessage('ill', null, 'Europe/Ljubljana')).toBe(CHILD_ACTION_STRINGS.locked.illNoTime);
    expect(lockMessage('game_over', null, null)).toBe(CHILD_ACTION_STRINGS.locked.game_over);
    expect(lockMessage('inactive', null, null)).toBe(CHILD_ACTION_STRINGS.locked.inactive);
    expect(lockMessage('contract_required', null, null)).toBeNull();
  });

  it('failureMessage covers every kind', () => {
    expect(failureMessage({ kind: 'offline' }, lj)).toBe(CHILD_ACTION_STRINGS.offline);
    expect(failureMessage({ kind: 'throttled' }, lj)).toBe(CHILD_ACTION_STRINGS.tooFast);
    expect(failureMessage({ kind: 'failed', status: 500 }, null)).toBe(CHILD_ACTION_STRINGS.failed);
    expect(failureMessage({ kind: 'locked', reason: 'contract_required', lockedUntil: null, state: null }, lj)).toBeNull();
  });

  it('successMessage', () => {
    expect(successMessage('feed', 'accepted')).toBe(CHILD_ACTION_STRINGS.success.feed);
    expect(successMessage('clean', 'unchanged')).toBe(CHILD_ACTION_STRINGS.unchanged.clean);
  });
});

describe('HUD hints', () => {
  it('feed: next window in family time; nothing when allowed or locked; mess first', () => {
    expect(feedHint(lj)).toBe('ob 17:00');
    expect(feedHint(ny)).toBe('ob 17:00');
    expect(feedHint(normalizeChildState(makeLiveChildState({ feeding: { can_feed: true } })))).toBeNull();
    expect(feedHint(normalizeChildState(makeLiveChildState({ lock: { is_locked: true, reason: 'hard_stopped' } })))).toBeNull();
    expect(feedHint(normalizeChildState(makeLiveChildState({ pet: { needs_cleaning: true, hygiene_level: 0 } })))).toBe(
      HUD_HINTS.cleanFirst,
    );
  });

  it('water: time of the next refill, "jutri" after the daily limit', () => {
    expect(waterHint(lj)).toBeNull();
    const soon = normalizeChildState(
      makeLiveChildState({ water: { can_water: false, next_allowed_at: '2026-10-04T15:30:00+02:00' } }),
    );
    expect(waterHint(soon)).toBe('ob 15:30');
    const done = normalizeChildState(
      makeLiveChildState({ water: { can_water: false, remaining_today: 0, next_allowed_at: '2026-10-05T00:00:00+02:00' } }),
    );
    expect(waterHint(done)).toBe(HUD_HINTS.tomorrow);
  });
});
