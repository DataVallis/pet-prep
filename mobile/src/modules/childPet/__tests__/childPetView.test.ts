import type { ChildPetState } from '@/api/client';
import {
  applyBroadcast,
  lockFromFlags,
  lockStateFromView,
  mergePolledState,
  normalizeChildState,
  optimisticView,
} from '@/modules/childPet/childPetView';
import { makeBroadcast, makeChildState, makeLiveChildState } from '@/test-utils/fixtures';

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
    const newer = applyBroadcast(base, makeBroadcast({ hunger_level: 41, emitted_at: '2026-10-04T10:01:00.000Z' }));
    expect(newer).not.toBeNull();
    const older = applyBroadcast(newer!.view, makeBroadcast({ hunger_level: 90, emitted_at: '2026-10-04T10:00:30.000Z' }));
    expect(older).toBeNull();
  });

  it('drops an event older than the HTTP snapshot, and events of another pet', () => {
    expect(applyBroadcast(base, makeBroadcast({ emitted_at: '2026-10-04T09:59:59.900Z' }))).toBeNull();
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

  it('illness carries the vet end time', () => {
    const result = applyBroadcast(
      base,
      makeBroadcast({ is_ill: true, illness_until: '2026-10-04T16:30:00Z', event_type: 'illness_triggered' }),
    );
    expect(result?.view.lock).toEqual({ is_locked: true, reason: 'ill', until: '2026-10-04T16:30:00Z' });
    expect(lockStateFromView(result!.view)).toBe('illness');
  });
});

describe('mergePolledState', () => {
  it('a poll from an earlier second than the newest broadcast is ignored', () => {
    const shown = applyBroadcast(
      normalizeChildState(makeLiveChildState()),
      makeBroadcast({ hunger_level: 41, emitted_at: '2026-10-04T10:00:07.400Z' }),
    )!.view;
    const stalePoll = normalizeChildState(makeLiveChildState({ server_time: '2026-10-04T12:00:06+02:00' }));
    expect(mergePolledState(shown, stalePoll)).toBe(shown);
  });

  it('a poll from the same or a later second wins and keeps the broadcast clock', () => {
    const shown = applyBroadcast(
      normalizeChildState(makeLiveChildState()),
      makeBroadcast({ emitted_at: '2026-10-04T10:00:07.400Z' }),
    )!.view;
    const poll = normalizeChildState(makeLiveChildState({ server_time: '2026-10-04T12:00:07+02:00', pet: { hunger_level: 39 } }));
    const merged = mergePolledState(shown, poll);
    expect(merged.pet.hunger_level).toBe(39);
    expect(merged.lastEmittedMs).toBe(Date.parse('2026-10-04T10:00:07.400Z'));
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
