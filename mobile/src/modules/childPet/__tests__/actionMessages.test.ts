import { ApiError } from '@/api/client';
import {
  CHILD_ACTION_STRINGS,
  classifyActionError,
  confirmationNext,
  failureMessage,
  feedHint,
  HUD_HINTS,
  lockMessage,
  refusalMessage,
  successMessage,
  waterDockHint,
  waterHint,
  waterShownAt,
} from '@/modules/childPet/actionMessages';
import { normalizeChildState } from '@/modules/childPet/childPetView';
import { useServerStatusStore } from '@/store/serverStatusStore';
import { makeLiveChildState } from '@/test-utils/fixtures';

const lj = normalizeChildState(makeLiveChildState());
const ny = normalizeChildState(
  makeLiveChildState({
    timezone: 'America/New_York',
    server_time: '2026-10-04T12:00:00-04:00',
    feeding: { next_feed_window: { start: '2026-10-04T17:00:00-04:00', end: '2026-10-04T21:00:00-04:00' } },
  }),
);

describe('confirmationNext (M5-T01 "next time" line, only from the state)', () => {
  it('feed: the next window; water: next_allowed_at today; clean: none', () => {
    expect(confirmationNext('feed', 'accepted', lj)).toBe('Naslednji obrok je ob 17:00.');
    const watered = normalizeChildState(makeLiveChildState({ water: { can_water: false, next_allowed_at: '2026-10-04T15:30:00+02:00' } }));
    expect(confirmationNext('water', 'accepted', watered)).toBe('Novo vodo lahko daš ob 15:30.');
    expect(confirmationNext('water', 'accepted', lj)).toBeNull();
    expect(confirmationNext('clean', 'accepted', lj)).toBeNull();
  });

  it('nothing for unchanged, an emergency meal (its text names the next meal) or a missing state', () => {
    expect(confirmationNext('feed', 'unchanged', lj)).toBeNull();
    expect(confirmationNext('feed', 'accepted', lj, 'emergency')).toBeNull();
    expect(confirmationNext('take_out', 'accepted', null)).toBeNull();
  });
});

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

  it('M5-F13: maintenance 503 / 502 / 504 → updating, with a calm "try again in a moment" text', () => {
    expect(classifyActionError(new ApiError('Service Unavailable', 503, { message: 'x' }, 15))).toEqual({ kind: 'updating' });
    expect(classifyActionError(new ApiError('x', 502))).toEqual({ kind: 'updating' });
    expect(classifyActionError(new ApiError('x', 504))).toEqual({ kind: 'updating' });
    expect(failureMessage({ kind: 'updating' }, null)).toBe('PetPrep se ravno posodablja. Poskusi čez trenutek.');
  });

  it('M5-F13: a network error while the banner shows is the deploy too', () => {
    useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000);
    expect(classifyActionError(new TypeError('Network request failed'))).toEqual({ kind: 'updating' });
  });

  it('M5-F13: 422 / 423 answers are classified exactly as before, also during a deploy', () => {
    useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000);
    expect(classifyActionError(new ApiError('x', 422, { reason: 'water_too_soon' }))).toMatchObject({ kind: 'refused', reason: 'water_too_soon' });
    expect(classifyActionError(new ApiError('x', 423, { reason: 'ill' }))).toMatchObject({ kind: 'locked', reason: 'ill' });
  });
});

describe('refusalMessage (family timezone)', () => {
  it('outside_feed_window → "Naslednji obrok je ob 17:00"', () => {
    expect(refusalMessage('outside_feed_window', '2026-10-04T17:00:00+02:00', lj)).toBe('Naslednji obrok je ob 17:00.');
  });

  it('outside_feed_window without next_allowed_at uses the state window; New York family sees its own clock', () => {
    expect(refusalMessage('outside_feed_window', null, ny)).toBe('Naslednji obrok je ob 17:00.');
  });

  it('after the evening window → "jutri ob 6:00"', () => {
    const late = normalizeChildState(makeLiveChildState({ server_time: '2026-10-04T21:30:00+02:00' }));
    expect(refusalMessage('already_fed_this_window', '2026-10-05T06:00:00+02:00', late)).toBe(
      'Kuža je že jedel. Spet ga nahraniš jutri ob 6:00.',
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
      'Posoda je še dovolj polna do jutri. Novo vodo lahko daš jutri.',
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

describe('water time inside the pet\'s sleep shows the wake time (David 2026-10-25)', () => {
  const WAKE = '2026-10-05T07:00:00+02:00';
  const night = (nextAllowedAt: string, opts: { serverTime?: string; until?: string | null; asleep?: boolean } = {}) =>
    normalizeChildState(
      makeLiveChildState({
        server_time: opts.serverTime ?? '2026-10-04T20:52:00+02:00',
        water: { can_water: false, next_allowed_at: nextAllowedAt },
        sleep: { asleep: opts.asleep ?? true, until: opts.until === undefined ? WAKE : opts.until },
      }),
    );

  it('23:58 while the pup sleeps until 7:00 → "jutri" + "7:00" (dock, screen reader, refusal toast)', () => {
    const view = night('2026-10-04T23:58:00+02:00');
    expect(waterShownAt(view.water.next_allowed_at, view)).toBe(WAKE);
    expect(waterDockHint(view)).toEqual({ day: 'jutri', text: '7:00', a11y: 'jutri ob 7:00' });
    expect(waterHint(view)).toBe('jutri ob 7:00');
    expect(refusalMessage('water_too_soon', '2026-10-04T23:58:00+02:00', view)).toBe(
      CHILD_ACTION_STRINGS.refused.waterTooSoon('jutri ob 7:00'),
    );
  });

  it('a threshold after midnight inside the sleep → "jutri 7:00", not a bare "jutri"', () => {
    const view = night('2026-10-05T01:30:00+02:00');
    expect(waterDockHint(view)).toEqual({ day: 'jutri', text: '7:00', a11y: 'jutri ob 7:00' });
    expect(refusalMessage('water_too_soon', null, view)).toBe(CHILD_ACTION_STRINGS.refused.waterTooSoon('jutri ob 7:00'));
  });

  it('after midnight, before the wake-up today → "ob 7:00"', () => {
    const view = night('2026-10-05T04:00:00+02:00', { serverTime: '2026-10-05T02:00:00+02:00' });
    expect(waterDockHint(view)).toEqual({ day: null, text: 'ob 7:00', a11y: 'ob 7:00' });
    expect(refusalMessage('water_too_soon', null, view)).toBe(CHILD_ACTION_STRINGS.refused.waterTooSoon('ob 7:00'));
  });

  it('awake pet / no wake time / a time after the wake-up keep the server time', () => {
    const awake = night('2026-10-04T15:30:00+02:00', { serverTime: '2026-10-04T12:00:00+02:00', asleep: false, until: null });
    expect(waterHint(awake)).toBe('ob 15:30');
    const noUntil = night('2026-10-04T23:58:00+02:00', { until: null });
    expect(waterHint(noUntil)).toBe('ob 23:58');
    expect(refusalMessage('water_too_soon', null, noUntil)).toBe(CHILD_ACTION_STRINGS.refused.waterTooSoon('ob 23:58'));
    const afterWake = night('2026-10-05T08:30:00+02:00');
    expect(waterShownAt(afterWake.water.next_allowed_at, afterWake)).toBe('2026-10-05T08:30:00+02:00');
    expect(waterHint(afterWake)).toBe(HUD_HINTS.tomorrow);
    expect(refusalMessage('water_too_soon', null, afterWake)).toBe(CHILD_ACTION_STRINGS.refused.waterDailyLimit);
  });

  describe('server display time `water.shown_at` (David 2026-10-25)', () => {
    const awakeAt2052 = (shownAt: string | null, nextAllowedAt = '2026-10-04T23:58:00+02:00') =>
      normalizeChildState(
        makeLiveChildState({
          server_time: '2026-10-04T20:52:00+02:00',
          water: { can_water: false, next_allowed_at: nextAllowedAt, shown_at: shownAt },
          sleep: { asleep: false, until: null },
        }),
      );

    it('awake at 20:52, threshold 23:58 inside tonight\'s sleep → the server\'s "jutri 7:00"', () => {
      const view = awakeAt2052(WAKE);
      expect(view.water.shown_at).toBe(WAKE);
      expect(waterShownAt(view.water.next_allowed_at, view)).toBe(WAKE);
      expect(waterDockHint(view)).toEqual({ day: 'jutri', text: '7:00', a11y: 'jutri ob 7:00' });
      expect(refusalMessage('water_too_soon', '2026-10-04T23:58:00+02:00', view)).toBe(
        CHILD_ACTION_STRINGS.refused.waterTooSoon('jutri ob 7:00'),
      );
    });

    it('shown_at equal to next_allowed_at keeps the server time', () => {
      const view = awakeAt2052('2026-10-04T15:30:00+02:00', '2026-10-04T15:30:00+02:00');
      expect(waterHint({ ...view, server_time: '2026-10-04T12:00:00+02:00' })).toBe('ob 15:30');
    });

    it('wins over the app\'s own sleep rule while the pet sleeps', () => {
      const view = normalizeChildState(
        makeLiveChildState({
          server_time: '2026-10-04T22:00:00+02:00',
          water: { can_water: false, next_allowed_at: '2026-10-04T23:58:00+02:00', shown_at: '2026-10-05T06:30:00+02:00' },
          sleep: { asleep: true, until: WAKE },
        }),
      );
      expect(waterShownAt(view.water.next_allowed_at, view)).toBe('2026-10-05T06:30:00+02:00');
    });

    it('an older server (no shown_at) falls back: an awake pet keeps "ob 23:58"', () => {
      const view = awakeAt2052(null);
      expect(view.water.shown_at).toBeNull();
      expect(waterHint(view)).toBe('ob 23:58');
    });

    it('a refusal time other than the view\'s next_allowed_at uses the fallback', () => {
      const view = awakeAt2052(WAKE);
      expect(waterShownAt('2026-10-04T22:10:00+02:00', view)).toBe('2026-10-04T22:10:00+02:00');
    });
  });

  it('the "next time" line after a night refill also names the wake time when it is today', () => {
    const view = night('2026-10-05T04:00:00+02:00', { serverTime: '2026-10-05T02:00:00+02:00' });
    expect(confirmationNext('water', 'accepted', view)).toBe(CHILD_ACTION_STRINGS.next.water('ob 7:00'));
  });
});
