import type { ChildPetState } from '@/api/client';
import {
  applyBroadcast,
  lockFromFlags,
  lockStateFromView,
  mergePolledState,
  BOUNDARY_RETRY_MS,
  nextRefreshDelay,
  normalizeChildState,
  optimisticView,
  revertOptimistic,
} from '@/modules/childPet/childPetView';
import { makeBroadcast, makeChildState, makeLiveChildState, makeMedia } from '@/test-utils/fixtures';

describe('normalizeChildState', () => {
  it('reads the backend shape (booleans, numbers) into proper types', () => {
    const view = normalizeChildState(makeLiveChildState());
    expect(view.feeding.can_feed).toBe(false);
    expect(view.water.can_water).toBe(true);
    expect(view.water.remaining_today).toBe(2);
    expect(view.feeding.windows).toEqual([
      { start: '06:00', end: '10:00' },
      { start: '17:00', end: '21:00' },
    ]);
    expect(view.feeding.next_feed_window?.start).toBe('2026-10-04T17:00:00+02:00');
    expect(view.water.next_allowed_at).toBeNull();
    expect(view.snapshotAtMs).toBe(Date.parse('2026-10-04T10:00:00Z'));
    expect(view.lastEmittedMs).toBe(0);
  });

  it('copes with the loose generated types (strings, empty instants, unknown values)', () => {
    const raw = makeChildState({ breed_type: 'poodle', pet_state: 'dancing' });
    const view = normalizeChildState({
      ...raw,
      lock: { is_locked: true, reason: 'nonsense', until: null },
      water: { ...raw.water, remaining_today: '2', can_water: 'true', next_allowed_at: '' },
    } as ChildPetState);
    expect(view.pet.breed_type).toBe('mutt');
    expect(view.pet.pet_state).toBe('idle');
    expect(view.lock.reason).toBeNull();
    expect(view.water.remaining_today).toBe(2);
    expect(view.water.can_water).toBe(true);
    expect(view.water.next_allowed_at).toBeNull();
    expect(view.feeding.last_fed_at).toBeNull();
  });
});

describe('applyBroadcast (out-of-order debt)', () => {
  const base = normalizeChildState(makeLiveChildState()); // snapshot 10:00:00Z

  it('applies a newer snapshot and remembers its emitted_at', () => {
    const result = applyBroadcast(base, makeBroadcast({ hunger_level: 41 }));
    expect(result?.view.pet.hunger_level).toBe(41);
    expect(result?.view.lastEmittedMs).toBe(Date.parse('2026-10-04T10:00:05.250Z'));
    expect(result?.refetch).toBe(false);
  });

  it('drops an event older than the last applied one', () => {
    const newer = applyBroadcast(base, makeBroadcast({ hunger_level: 41, emitted_at: '2026-10-04T10:01:00.000+00:00' }));
    expect(newer).not.toBeNull();
    const older = applyBroadcast(newer!.view, makeBroadcast({ hunger_level: 90, emitted_at: '2026-10-04T10:00:30.000+00:00' }));
    expect(older).toBeNull();
  });

  it('drops an event older than the HTTP snapshot, and events of another pet', () => {
    expect(applyBroadcast(base, makeBroadcast({ emitted_at: '2026-10-04T09:59:59.900+00:00' }))).toBeNull();
    expect(applyBroadcast(base, makeBroadcast({ pet_id: 8 }))).toBeNull();
    expect(applyBroadcast(base, makeBroadcast({ emitted_at: 'garbage' }))).toBeNull();
  });

  it('hard stop locks the view, disables actions and asks for a refetch', () => {
    const result = applyBroadcast(base, makeBroadcast({ is_hard_stopped: true, event_type: 'hard_stop_activated' }));
    expect(result?.view.lock).toEqual({ is_locked: true, reason: 'hard_stopped', until: null });
    expect(result?.view.water.can_water).toBe(false);
    expect(result?.refetch).toBe(true);
    expect(lockStateFromView(result!.view)).toBe('hard_stop');
  });

  it('a pet-level awaiting_contract false never unlocks a child who still has to sign', () => {
    const joined = normalizeChildState(
      makeLiveChildState({ pet: { awaiting_contract: true }, lock: { is_locked: true, reason: 'contract_required' } }),
    );
    const result = applyBroadcast(joined, makeBroadcast({ awaiting_contract: false }));
    expect(result?.view.pet.awaiting_contract).toBe(true);
    expect(result?.view.lock.reason).toBe('contract_required');
  });

  it('a mess (hygiene 0) blocks feed and water and triggers a refetch', () => {
    const fed = normalizeChildState(makeLiveChildState({ feeding: { can_feed: true } }));
    const result = applyBroadcast(fed, makeBroadcast({ hygiene_level: 0 }));
    expect(result?.view.pet.needs_cleaning).toBe(true);
    expect(result?.view.feeding.can_feed).toBe(false);
    expect(result?.refetch).toBe(true);
  });

  it('a tick that lowers energy (family midnight reset) refetches the full state', () => {
    const tick = applyBroadcast(base, makeBroadcast({ energy_level: 0, event_type: 'metric_changed' }));
    expect(tick?.refetch).toBe(true);
    const same = applyBroadcast(base, makeBroadcast({ energy_level: 30, event_type: 'metric_changed' }));
    expect(same?.refetch).toBe(false);
  });

  it('m3: a running illness keeps the server lock.until (family offset) over the UTC broadcast value', () => {
    const ill = normalizeChildState(
      makeLiveChildState({
        pet: { is_ill: true, illness_until: '2026-10-04T18:30:00+02:00' },
        lock: { is_locked: true, reason: 'ill', until: '2026-10-04T18:30:00+02:00' },
      }),
    );
    const result = applyBroadcast(ill, makeBroadcast({ is_ill: true, illness_until: '2026-10-04T16:30:00+00:00' }));
    expect(result?.view.lock.until).toBe('2026-10-04T18:30:00+02:00');
  });

  it('illness carries the vet end time', () => {
    const result = applyBroadcast(
      base,
      makeBroadcast({ is_ill: true, illness_until: '2026-10-04T16:30:00+00:00', event_type: 'illness_triggered' }),
    );
    expect(result?.view.lock).toEqual({ is_locked: true, reason: 'ill', until: '2026-10-04T16:30:00+00:00' });
    expect(lockStateFromView(result!.view)).toBe('illness');
  });
});

describe('mergePolledState', () => {
  it('a poll from an earlier second than the newest broadcast is ignored', () => {
    const shown = applyBroadcast(
      normalizeChildState(makeLiveChildState()),
      makeBroadcast({ hunger_level: 41, emitted_at: '2026-10-04T10:00:07.400+00:00' }),
    )!.view;
    const stalePoll = normalizeChildState(makeLiveChildState({ server_time: '2026-10-04T12:00:06+02:00' }));
    expect(mergePolledState(shown, stalePoll)).toBe(shown);
  });

  it('a poll from the same or a later second wins and keeps the broadcast clock', () => {
    const shown = applyBroadcast(
      normalizeChildState(makeLiveChildState()),
      makeBroadcast({ emitted_at: '2026-10-04T10:00:07.400+00:00' }),
    )!.view;
    const poll = normalizeChildState(makeLiveChildState({ server_time: '2026-10-04T12:00:07+02:00', pet: { hunger_level: 39 } }));
    const merged = mergePolledState(shown, poll);
    expect(merged.pet.hunger_level).toBe(39);
    expect(merged.lastEmittedMs).toBe(Date.parse('2026-10-04T10:00:07.400Z'));
  });

  it('m1: an HTTP snapshot older than the one shown is dropped (slow poll overtaken)', () => {
    const shown = normalizeChildState(makeLiveChildState({ server_time: '2026-10-04T12:00:10+02:00', pet: { hunger_level: 100 } }));
    const slow = normalizeChildState(makeLiveChildState({ server_time: '2026-10-04T12:00:04+02:00', pet: { hunger_level: 60 } }));
    expect(shown.lastEmittedMs).toBe(0);
    expect(slow.lastEmittedMs).toBe(0);
    expect(mergePolledState(shown, slow)).toBe(shown);
  });

  it('writes that carry the clock (optimistic, broadcast, action response) always pass', () => {
    const shown = normalizeChildState(makeLiveChildState());
    const optimistic = optimisticView(shown, 'feed');
    expect(mergePolledState(shown, optimistic)).toBe(optimistic);
    expect(mergePolledState(undefined, shown)).toBe(shown);
  });
});

describe('optimisticView', () => {
  const view = normalizeChildState(makeLiveChildState({ pet: { hygiene_level: 0, needs_cleaning: true } }));

  it('feed / water / clean set their metric to 100 %', () => {
    expect(optimisticView(view, 'feed').pet.hunger_level).toBe(100);
    expect(optimisticView(view, 'feed').feeding.can_feed).toBe(false);
    expect(optimisticView(view, 'water').pet.thirst_level).toBe(100);
    const cleaned = optimisticView(view, 'clean');
    expect(cleaned.pet.hygiene_level).toBe(100);
    expect(cleaned.pet.needs_cleaning).toBe(false);
  });
});

describe('lockFromFlags priority (game over › inactive › hard stop › contract › ill)', () => {
  const pet = normalizeChildState(makeLiveChildState()).pet;

  it.each([
    [{ is_game_over: true, is_hard_stopped: true, is_ill: true }, 'game_over'],
    [{ is_active: false, is_hard_stopped: true }, 'inactive'],
    [{ is_hard_stopped: true, awaiting_contract: true, is_ill: true }, 'hard_stopped'],
    [{ awaiting_contract: true, is_ill: true }, 'contract_required'],
    [{ is_ill: true }, 'ill'],
    [{}, null],
  ] as const)('%o → %s', (flags, reason) => {
    expect(lockFromFlags({ ...pet, ...flags }).reason).toBe(reason);
  });

  it('contract_required is not an overlay (ContractScreen handles it)', () => {
    const view = normalizeChildState(makeLiveChildState({ lock: { is_locked: true, reason: 'contract_required' } }));
    expect(lockStateFromView(view)).toBe('none');
    const inactive = normalizeChildState(makeLiveChildState({ lock: { is_locked: true, reason: 'inactive' } }));
    expect(lockStateFromView(inactive)).toBe('inactive');
  });
});

describe('nextRefreshDelay (M3 time-based staleness, N2 clock skew)', () => {
  const MIN = 60_000;
  /** State received when the device clock showed `deviceMs` (default: same as the server). */
  const viewAt = (raw: ChildPetState, deviceMs = Date.parse(raw.server_time)) => normalizeChildState(raw, 0, deviceMs);
  const now = Date.parse('2026-10-04T10:00:00Z'); // 12:00 Ljubljana

  it('earliest of next feed window, water gap end, current window end, family midnight', () => {
    const view = viewAt(makeLiveChildState({ water: { can_water: false, next_allowed_at: '2026-10-04T15:30:00+02:00' } }));
    expect(view.clockSkewMs).toBe(0);
    expect(nextRefreshDelay(view, now)).toBe(210 * MIN); // water 15:30 local

    const open = viewAt(
      makeLiveChildState({
        server_time: '2026-10-04T09:00:00+02:00',
        feeding: {
          can_feed: true,
          current_window: { start: '2026-10-04T06:00:00+02:00', end: '2026-10-04T10:00:00+02:00' },
          next_feed_window: { start: '2026-10-04T17:00:00+02:00', end: '2026-10-04T21:00:00+02:00' },
        },
      }),
    );
    expect(nextRefreshDelay(open, Date.parse('2026-10-04T07:00:00Z'))).toBe(60 * MIN); // window end 10:00
  });

  it('hotfix 2026-10-06: the end of a vet visit is a boundary; past it while still "ill" → the 30 s retry', () => {
    const ill = viewAt(
      makeLiveChildState({
        pet: { is_ill: true, illness_until: '2026-10-04T12:05:00+02:00' },
        lock: { is_locked: true, reason: 'ill', until: '2026-10-04T12:05:00+02:00' },
      }),
    );
    expect(nextRefreshDelay(ill, now)).toBe(5 * MIN);
    expect(nextRefreshDelay(ill, now + 6 * MIN)).toBe(BOUNDARY_RETRY_MS);
    // A hard stop has no end time → no illness boundary.
    const stopped = viewAt(makeLiveChildState({ lock: { is_locked: true, reason: 'hard_stopped', until: null } }));
    expect(nextRefreshDelay(stopped, now)).toBeGreaterThan(5 * MIN);
  });

  it('falls back to the next FAMILY midnight (device runs on UTC)', () => {
    const view = viewAt(makeLiveChildState({ server_time: '2026-10-04T21:30:00+02:00', feeding: { next_feed_window: null } }));
    expect(nextRefreshDelay(view, Date.parse('2026-10-04T19:30:00Z'))).toBe(150 * MIN); // 21:30 → 00:00
    const ny = viewAt(
      makeLiveChildState({
        timezone: 'America/New_York',
        server_time: '2026-10-04T15:30:00-04:00',
        feeding: { next_feed_window: null },
      }),
    );
    expect(nextRefreshDelay(ny, Date.parse('2026-10-04T19:30:00Z'))).toBe(510 * MIN); // to 04:00Z
  });

  it('N2: a device clock 2 min fast still refreshes at the server’s boundary', () => {
    const deviceAhead = now + 2 * MIN;
    const view = viewAt(
      makeLiveChildState({ water: { can_water: false, next_allowed_at: '2026-10-04T12:30:00+02:00' } }),
      deviceAhead,
    );
    expect(view.clockSkewMs).toBe(-2 * MIN);
    // 30 min of server time remain, although the device clock already shows 12:32 − 2 = 28 min to go.
    expect(nextRefreshDelay(view, deviceAhead)).toBe(30 * MIN);
    // Half an hour of device time later the boundary is reached in server time too.
    expect(nextRefreshDelay(view, deviceAhead + 30 * MIN)).toBe(BOUNDARY_RETRY_MS);
  });

  it('N2: retries every 30 s while a passed boundary still blocks the action', () => {
    const stuck = viewAt(
      makeLiveChildState({ water: { can_water: false, next_allowed_at: '2026-10-04T11:59:50+02:00' } }),
    );
    expect(nextRefreshDelay(stuck, now)).toBe(BOUNDARY_RETRY_MS);
    const feedStuck = viewAt(
      makeLiveChildState({ feeding: { can_feed: false, next_feed_window: { start: '2026-10-04T11:59:00+02:00', end: '2026-10-04T16:00:00+02:00' } } }),
    );
    expect(nextRefreshDelay(feedStuck, now)).toBe(BOUNDARY_RETRY_MS);
  });

  it('no retry when the action is allowed or blocked for another reason (lock / mess)', () => {
    const allowed = viewAt(makeLiveChildState({ water: { can_water: true, next_allowed_at: '2026-10-04T11:00:00+02:00' } }));
    expect(nextRefreshDelay(allowed, now)).toBe(5 * 60 * MIN); // 17:00 window
    const messy = viewAt(
      makeLiveChildState({ pet: { needs_cleaning: true, hygiene_level: 0 }, water: { can_water: false, next_allowed_at: '2026-10-04T11:00:00+02:00' } }),
    );
    expect(nextRefreshDelay(messy, now)).toBe(5 * 60 * MIN);
  });
});

describe('revertOptimistic (M4)', () => {
  const previous = normalizeChildState(makeLiveChildState({ feeding: { can_feed: true } }));

  it('restores only the action’s metric and flags on top of the current view', () => {
    const optimistic = optimisticView(previous, 'feed');
    const current = { ...optimistic, pet: { ...optimistic.pet, thirst_level: 12 } };
    const reverted = revertOptimistic(current, previous, 'feed');
    expect(reverted.pet.hunger_level).toBe(60);
    expect(reverted.pet.thirst_level).toBe(12);
    expect(reverted.feeding.can_feed).toBe(true);
  });

  it('keeps the metric when a newer broadcast already brought the server value; never re-enables a locked action', () => {
    const afterBroadcast = applyBroadcast(
      optimisticView(previous, 'feed'),
      makeBroadcast({ hunger_level: 58, is_hard_stopped: true, emitted_at: '2026-10-04T10:00:30.000+00:00' }),
    )!.view;
    const reverted = revertOptimistic(afterBroadcast, previous, 'feed');
    expect(reverted.pet.hunger_level).toBe(58);
    expect(reverted.feeding.can_feed).toBe(false);
  });

  it('clean restores hygiene and the mess flag', () => {
    const messy = normalizeChildState(makeLiveChildState({ pet: { hygiene_level: 0, needs_cleaning: true } }));
    const reverted = revertOptimistic(optimisticView(messy, 'clean'), messy, 'clean');
    expect(reverted.pet.hygiene_level).toBe(0);
    expect(reverted.pet.needs_cleaning).toBe(true);
  });
});

describe('pet media (M4-03 app side)', () => {
  const IDLE = 'https://api.petprep.si/api/media/2?expires=1&v=i&signature=a';
  const IMG = 'https://api.petprep.si/api/media/1?expires=1&v=img&signature=a';

  it('normalises the media object of the child state', () => {
    const view = normalizeChildState(
      makeLiveChildState({
        pet: { media: makeMedia({ status: 'partial', reference_image_url: IMG, videos: { idle: IDLE }, current_video_url: IDLE }) },
      }),
    );
    expect(view.pet.media).toMatchObject({ status: 'partial', referenceImageUrl: IMG, videos: { idle: IDLE } });
  });

  it('a broadcast with media replaces it; one without keeps the cached media', () => {
    const view = normalizeChildState(makeLiveChildState({ pet: { media: makeMedia({ status: 'pending' }) } }));
    const withMedia = applyBroadcast(
      view,
      makeBroadcast({ emitted_at: '2026-10-04T10:00:05.250Z', event_type: 'video_ready', media: makeMedia({ status: 'partial', reference_image_url: IMG, videos: { idle: IDLE } }) }),
    );
    expect(withMedia?.view.pet.media.videos.idle).toBe(IDLE);
    expect(withMedia?.refetch).toBe(true);
    const without = applyBroadcast(view, makeBroadcast({ emitted_at: '2026-10-04T10:00:05.250Z' }));
    expect(without?.view.pet.media).toBe(view.pet.media);
  });
});
