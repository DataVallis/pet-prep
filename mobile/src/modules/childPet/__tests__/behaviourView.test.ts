/**
 * M5-R02 in the child view model: `behaviour` normalised from the state, kept / replaced
 * by `PetUpdated`, optimistic take-out / clean / resolve-chewing and their rollback, the
 * accident boundary refresh, messages and hints.
 */
import { ApiError } from '@/api/client';
import {
  applyBroadcast,
  broadcastBehaviour,
  nextRefreshDelay,
  normalizeChildState,
  optimisticView,
  revertOptimistic,
  type ChildPetView,
} from '@/modules/childPet/childPetView';
import {
  CHILD_ACTION_STRINGS,
  classifyActionError,
  cleanHint,
  failureMessage,
  successMessage,
} from '@/modules/childPet/actionMessages';
import { EMPTY_BEHAVIOUR } from '@/modules/behaviour/behaviour';
import { makeBehaviourEvent, makeBroadcast, makeLiveChildState, makeTakeOut } from '@/test-utils/fixtures';

const NOW = Date.parse('2026-10-04T12:00:00+02:00');

function puppyView(overrides: Parameters<typeof makeLiveChildState>[0] = {}): ChildPetView {
  return normalizeChildState(
    makeLiveChildState({ behaviour: { take_out: makeTakeOut(), can_take_out: true }, ...overrides }),
    0,
    NOW,
  );
}

function dirtyView(kinds: Array<'poop' | 'accident' | 'chewing'>): ChildPetView {
  const events = kinds.map((k, i) => makeBehaviourEvent(k, { id: i + 1 }));
  const scene = [...kinds].reverse().find((k) => k !== 'poop') ?? null;
  return normalizeChildState(
    makeLiveChildState({
      pet: { hygiene_level: 0, needs_cleaning: true, pet_state: 'sick' },
      behaviour: { active_events: events, scene, can_resolve_chewing: kinds.includes('chewing') },
    }),
    0,
    NOW,
  );
}

describe('normalizeChildState — behaviour', () => {
  it('reads the bladder clock and flags', () => {
    const v = puppyView();
    expect(v.behaviour.take_out?.hold_hours).toBe(2);
    expect(v.behaviour.can_take_out).toBe(true);
  });

  it('an older server without `behaviour` → nothing new, no crash', () => {
    const v = normalizeChildState(makeLiveChildState({ behaviour: null }), 0, NOW);
    expect(v.behaviour).toEqual(EMPTY_BEHAVIOUR);
  });
});

describe('applyBroadcast — behaviour (realtime)', () => {
  const emitted = '2026-10-04T10:00:05.250Z';

  it('takes the broadcast behaviour and derives the child flags', () => {
    const v = puppyView();
    const result = applyBroadcast(
      v,
      makeBroadcast({
        emitted_at: emitted,
        hygiene_level: 0,
        behaviour: {
          take_out: makeTakeOut({ next_due_at: '2026-10-04T15:00:00+02:00' }),
          active_events: [makeBehaviourEvent('chewing')],
          scene: 'chewing',
        },
      }),
    );
    expect(result?.view.behaviour).toMatchObject({
      scene: 'chewing',
      can_take_out: true,
      can_resolve_chewing: true,
    });
    expect(result?.view.behaviour.take_out?.next_due_at).toBe('2026-10-04T15:00:00+02:00');
    // hygiene went to 0 → the full state is fetched (feed / water windows).
    expect(result?.refetch).toBe(true);
  });

  it('a locked pet may not act', () => {
    const result = applyBroadcast(
      puppyView(),
      makeBroadcast({
        emitted_at: emitted,
        is_hard_stopped: true,
        behaviour: { take_out: makeTakeOut(), active_events: [makeBehaviourEvent('chewing')], scene: 'chewing' },
      }),
    );
    expect(result?.view.behaviour.can_take_out).toBe(false);
    expect(result?.view.behaviour.can_resolve_chewing).toBe(false);
  });

  it('a broadcast without behaviour (older server) keeps the view', () => {
    const v = puppyView();
    const result = applyBroadcast(v, makeBroadcast({ emitted_at: emitted }));
    expect(result?.view.behaviour).toEqual(v.behaviour);
  });

  it('broadcastBehaviour without behaviour while locked switches the flags off', () => {
    const b = broadcastBehaviour({ ...EMPTY_BEHAVIOUR, can_take_out: true, can_resolve_chewing: true }, undefined, true);
    expect(b.can_take_out).toBe(false);
    expect(b.can_resolve_chewing).toBe(false);
  });

  it('a puppy that grew up (take_out null) loses the button', () => {
    const result = applyBroadcast(
      puppyView(),
      makeBroadcast({ emitted_at: emitted, behaviour: { take_out: null, active_events: [], scene: null } }),
    );
    expect(result?.view.behaviour.can_take_out).toBe(false);
    expect(result?.view.behaviour.take_out).toBeNull();
  });
});

describe('optimistic behaviour actions', () => {
  it('take-out restarts the clock in server time', () => {
    const v = puppyView();
    const next = optimisticView(v, 'take_out', Date.parse('2026-10-04T12:00:00+02:00'));
    expect(next.behaviour.take_out?.next_due_at).toBe('2026-10-04T12:00:00.000Z');
    expect(next.pet).toBe(v.pet);
  });

  it('clean with a chewed slipper open: accident gone, still dirty', () => {
    const next = optimisticView(dirtyView(['accident', 'chewing']), 'clean');
    expect(next.pet.hygiene_level).toBe(0);
    expect(next.pet.needs_cleaning).toBe(true);
    expect(next.behaviour.active_events.map((e) => e.kind)).toEqual(['chewing']);
  });

  it('clean of the last accident: clean again', () => {
    const next = optimisticView(dirtyView(['accident']), 'clean');
    expect(next.pet.hygiene_level).toBe(100);
    expect(next.pet.needs_cleaning).toBe(false);
    expect(next.behaviour.scene).toBeNull();
  });

  it('resolve-chewing: clean only when nothing else is open', () => {
    const only = optimisticView(dirtyView(['chewing']), 'resolve_chewing');
    expect(only.pet.needs_cleaning).toBe(false);
    expect(only.behaviour.can_resolve_chewing).toBe(false);
    const withPoop = optimisticView(dirtyView(['poop', 'chewing']), 'resolve_chewing');
    expect(withPoop.pet.needs_cleaning).toBe(true);
    expect(withPoop.behaviour.active_events.map((e) => e.kind)).toEqual(['poop']);
  });

  it('rollback without a server answer restores behaviour (unless a broadcast came)', () => {
    const before = dirtyView(['chewing']);
    const optimistic = optimisticView(before, 'resolve_chewing');
    const reverted = revertOptimistic(optimistic, before, 'resolve_chewing');
    expect(reverted.behaviour).toEqual(before.behaviour);
    expect(reverted.pet.needs_cleaning).toBe(true);

    const p = puppyView();
    const moved = optimisticView(p, 'take_out', NOW);
    expect(revertOptimistic(moved, p, 'take_out').behaviour).toEqual(p.behaviour);
    const withBroadcast = { ...moved, lastEmittedMs: p.lastEmittedMs + 1 };
    expect(revertOptimistic(withBroadcast, p, 'take_out').behaviour).toEqual(moved.behaviour);
  });
});

describe('nextRefreshDelay — accident boundary', () => {
  it('refreshes when the accident is due', () => {
    const v = puppyView({
      feeding: { next_feed_window: null },
      water: { next_allowed_at: null },
      behaviour: { take_out: makeTakeOut({ next_due_at: '2026-10-04T12:20:00+02:00' }), can_take_out: true },
    });
    expect(nextRefreshDelay(v, NOW - v.clockSkewMs)).toBe(20 * 60_000);
  });

  it('retries every 30 s while the due time is past', () => {
    const v = puppyView({
      feeding: { next_feed_window: null },
      water: { next_allowed_at: null },
      behaviour: { take_out: makeTakeOut({ next_due_at: '2026-10-04T11:59:00+02:00' }), can_take_out: true },
    });
    expect(nextRefreshDelay(v, NOW - v.clockSkewMs)).toBe(30_000);
  });

  it('a locked pet has a frozen clock', () => {
    const v = puppyView({
      lock: { is_locked: true, reason: 'hard_stopped' },
      feeding: { next_feed_window: null },
      water: { next_allowed_at: null },
      behaviour: { take_out: makeTakeOut({ next_due_at: '2026-10-04T11:59:00+02:00' }) },
    });
    expect(nextRefreshDelay(v, NOW - v.clockSkewMs)).not.toBe(30_000);
  });
});

describe('messages and hints', () => {
  it('toasts for the new actions', () => {
    expect(successMessage('take_out', 'accepted')).toBe(CHILD_ACTION_STRINGS.success.take_out);
    expect(successMessage('take_out', 'unchanged')).toBe('Kuža je bil pravkar zunaj.');
    expect(successMessage('resolve_chewing', 'accepted')).toMatch(/Copat je pospravljen/);
    expect(successMessage('resolve_chewing', 'unchanged')).toBe('Ni več kaj pospraviti.');
  });

  it('422 take_out_not_needed is worded kindly', () => {
    const failure = classifyActionError(new ApiError('x', 422, { reason: 'take_out_not_needed', state: null }));
    expect(failureMessage(failure, null)).toBe('Kuža zdaj ne rabi ven.');
  });

  it('"Očisti" hint: Čisto / Pospravi copat / nothing', () => {
    expect(cleanHint(normalizeChildState(makeLiveChildState({ pet: { hygiene_level: 100 } }), 0, NOW))).toBe('Čisto');
    expect(cleanHint(dirtyView(['chewing']))).toBe('Pospravi copat');
    expect(cleanHint(dirtyView(['accident']))).toBeNull();
  });
});
