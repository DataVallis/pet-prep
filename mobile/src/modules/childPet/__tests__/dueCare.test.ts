/**
 * M5-T20 soft order (David 2026-11-24): only the next care step glows — take out > scrub >
 * litter > food > water (W1: water when it can be refilled and the bowl isn't full).
 */
import { dueCare } from '@/modules/childPet/dueCare';
import { normalizeChildState, optimisticView, type ChildPetView } from '@/modules/childPet/childPetView';
import { makeBehaviourEvent, makeLitterState, makeLiveChildState, makeScratchingState, makeTakeOut, makeWandState } from '@/test-utils/fixtures';

type Overrides = Parameters<typeof makeLiveChildState>[0];

const view = (o: Overrides = {}): ChildPetView => normalizeChildState(makeLiveChildState(o));
// 13:05 Ljubljana — the default take-out clock (due 13:00) is due.
const LATE = Date.parse('2026-10-04T13:05:00+02:00');
const EARLY = Date.parse('2026-10-04T12:00:00+02:00');

/** A morning puppy: must go out, breakfast window open, bowl half full. */
function morningPuppy(o: Overrides = {}): ChildPetView {
  return view({
    behaviour: { take_out: makeTakeOut(), can_take_out: true },
    feeding: { can_feed: true },
    ...o,
  });
}

function catView(o: Overrides = {}): ChildPetView {
  return normalizeChildState(
    makeLiveChildState({
      wand: makeWandState(),
      litter: makeLitterState(),
      grooming: null,
      scratching: makeScratchingState({ active: null, blocked_reason: 'scratching_not_needed', can_start: false }),
      ...o,
      pet: { breed_type: 'domestic_cat', species: 'cat', ...o.pet },
    }),
  );
}

it('morning puppy: only take out glows while feed is enabled', () => {
  expect(dueCare(morningPuppy(), { nowMs: LATE })).toBe('take_out');
});

it('after the take out (server state) → feed; after the feed → water', () => {
  // David 2026-11-27: the optimistic take-out no longer guesses the next deadline — the glow moves
  // once the server's clock arrives (here: restarted at 13:05, due 15:05).
  expect(dueCare(optimisticView(morningPuppy(), 'take_out', LATE), { nowMs: LATE })).toBe('take_out');
  const out = morningPuppy({
    behaviour: {
      take_out: makeTakeOut({ clock_started_at: '2026-10-04T13:05:00+02:00', next_due_at: '2026-10-04T15:05:00+02:00', last_taken_out_at: '2026-10-04T13:05:00+02:00' }),
      can_take_out: true,
    },
  });
  expect(dueCare(out, { nowMs: LATE })).toBe('feed');
  const fed = optimisticView(out, 'feed', LATE);
  expect(dueCare(fed, { nowMs: LATE })).toBe('water');
  const watered = optimisticView(fed, 'water', LATE);
  expect(dueCare(watered, { nowMs: LATE })).toBeNull();
});

it('take out not yet due → feed', () => {
  expect(dueCare(morningPuppy(), { nowMs: EARLY })).toBe('feed');
});

it('a mess to scrub beats food', () => {
  expect(dueCare(view({ feeding: { can_feed: true }, pet: { needs_cleaning: true }, behaviour: { active_events: [makeBehaviourEvent('poop')] } }), { nowMs: EARLY })).toBe('clean');
});

it('a chewed slipper alone is not scrubbed → food glows', () => {
  expect(dueCare(view({ feeding: { can_feed: true }, pet: { needs_cleaning: true }, behaviour: { active_events: [makeBehaviourEvent('chewing')] } }), { nowMs: EARLY })).toBe('feed');
});

it('W1: water only when it can be refilled AND the bowl is not full', () => {
  expect(dueCare(view({ pet: { thirst_level: 50 } }), { nowMs: EARLY })).toBe('water');
  expect(dueCare(view({ pet: { thirst_level: 100 } }), { nowMs: EARLY })).toBeNull();
  expect(dueCare(view({ pet: { thirst_level: 50 }, water: { can_water: false } }), { nowMs: EARLY })).toBeNull();
});

it('a lock → nothing glows', () => {
  expect(dueCare(morningPuppy({ lock: { is_locked: true, reason: 'hard_stopped' } }), { nowMs: LATE })).toBeNull();
});

it('cat: the litter beats food, a scrub beats the litter (chain unchanged)', () => {
  expect(dueCare(catView({ feeding: { can_feed: true } }), { nowMs: EARLY })).toBe('scoop');
  expect(dueCare(catView({ feeding: { can_feed: true }, litter: makeLitterState({ open_uses: [], next_due_at: null, can_scoop: false }) }), { nowMs: EARLY })).toBe('feed');
  expect(
    dueCare(catView({ pet: { needs_cleaning: true }, behaviour: { active_events: [makeBehaviourEvent('litter_accident')] } }), { nowMs: EARLY }),
  ).toBe('clean');
});
