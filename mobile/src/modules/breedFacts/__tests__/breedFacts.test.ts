/**
 * M5-R09c — pure rules of »Ali si vedel?« (DID_YOU_KNOW_SPEC §3): which pets, the calm moment
 * (each blocker), chip vs sheet, care actions that count, read marks, search normalisation.
 */
import {
  APP_OPEN_QUIET_MS,
  childFactsOnly,
  isChildFact,
  isTimedReason,
  CARE_SETTLE_MS,
  CARE_WINDOW_MS,
  breedFactsBreed,
  calmMomentBlockers,
  calmStateOf,
  careKindOfMutation,
  familyDay,
  filterFacts,
  hasUnreadFacts,
  isCalmMoment,
  markReadInList,
  nextCalmCheckAt,
  presentCategories,
  readOnDay,
  showFactChip,
  stepGoalReached,
  type CalmMomentInput,
} from '@/modules/breedFacts/breedFacts';
import { normalizeChildState } from '@/modules/childPet/childPetView';
import { makeBehaviourEvent, makeBreedFact as makeFact, makeLiveChildState } from '@/test-utils/fixtures';


const NOW = 1_000_000_000;

function calm(overrides: Partial<CalmMomentInput> = {}): CalmMomentInput {
  return {
    fact: { fact: makeFact(), may_auto_show: true },
    careDoneAtMs: NOW - 5_000,
    appOpenedAtMs: NOW - 60_000,
    nowMs: NOW,
    metrics: [80, 70, 60, 90],
    openMess: false,
    locked: false,
    overlayOpen: false,
    quietHours: false,
    handledToday: false,
    careInFlight: false,
    ...overrides,
  };
}

describe('breedFactsBreed — only pedigree pets', () => {
  it('border collie and maine coon get facts', () => {
    expect(breedFactsBreed('border_collie')).toBe('border_collie');
    expect(breedFactsBreed('maine_coon')).toBe('maine_coon');
  });
  it('mutt, domestic cat, unknown / missing breed → none', () => {
    expect(breedFactsBreed('mutt')).toBeNull();
    expect(breedFactsBreed('domestic_cat')).toBeNull();
    expect(breedFactsBreed('unknown')).toBeNull();
    expect(breedFactsBreed('dragon')).toBeNull();
    expect(breedFactsBreed(null)).toBeNull();
    expect(breedFactsBreed(undefined)).toBeNull();
  });
});

describe('calm moment (§3.2)', () => {
  it('all conditions met → calm', () => {
    expect(calmMomentBlockers(calm())).toEqual([]);
    expect(isCalmMoment(calm())).toBe(true);
  });

  it.each<[string, Partial<CalmMomentInput>, string]>([
    ['the server says no (may_auto_show false)', { fact: { fact: makeFact(), may_auto_show: false } }, 'server'],
    ['the fact of the day has not loaded', { fact: null }, 'server'],
    ['no unread fact', { fact: { fact: null, may_auto_show: true } }, 'no_fact'],
    ['no care since the app opened', { careDoneAtMs: null }, 'no_care'],
    ['the care success is too old', { careDoneAtMs: NOW - CARE_WINDOW_MS - 1 }, 'no_care'],
    ['the care success is still settling (toast first)', { careDoneAtMs: NOW - 200 }, 'settling'],
    ['first 10 s after opening the app', { appOpenedAtMs: NOW - APP_OPEN_QUIET_MS + 1 }, 'app_just_opened'],
    ['one metric under 50 %', { metrics: [80, 49, 60, 90] }, 'metric_low'],
    ['a metric is not a number', { metrics: [80, Number.NaN, 60, 90] }, 'metric_low'],
    ['an open mess / behaviour event', { openMess: true }, 'mess'],
    ['a lock (payment, illness, hard stop)', { locked: true }, 'locked'],
    ['a game / layer / sheet is open', { overlayOpen: true }, 'overlay'],
    ['quiet hours', { quietHours: true }, 'quiet_hours'],
    ['already asked / read today', { handledToday: true }, 'handled_today'],
    ['a care request is still running (no card mid-care)', { careInFlight: true }, 'busy'],
    ['the 3 s care toast is still showing', { careDoneAtMs: NOW - 3_000 }, 'settling'],
  ])('%s → blocked', (_name, overrides, blocker) => {
    const input = calm(overrides);
    expect(calmMomentBlockers(input)).toContain(blocker);
    expect(isCalmMoment(input)).toBe(false);
  });

  it('exactly 50 % on every meter is calm (≥ 50 %)', () => {
    expect(isCalmMoment(calm({ metrics: [50, 50, 50, 50] }))).toBe(true);
  });

  it('at exactly 10 s after opening the app it is calm', () => {
    expect(isCalmMoment(calm({ appOpenedAtMs: NOW - APP_OPEN_QUIET_MS }))).toBe(true);
  });

  it('nextCalmCheckAt: only the clock blocks → the instant both waits are over', () => {
    const input = calm({ careDoneAtMs: NOW - 100, appOpenedAtMs: NOW - 2_000 });
    expect(nextCalmCheckAt(input)).toBe(NOW - 2_000 + APP_OPEN_QUIET_MS);
    expect(nextCalmCheckAt(calm({ careDoneAtMs: NOW - 100 }))).toBe(NOW - 100 + CARE_SETTLE_MS);
  });

  it('nextCalmCheckAt: null when calm or when something else blocks', () => {
    expect(nextCalmCheckAt(calm())).toBeNull();
    expect(nextCalmCheckAt(calm({ careDoneAtMs: NOW - 100, locked: true }))).toBeNull();
    expect(nextCalmCheckAt(calm({ careDoneAtMs: null, appOpenedAtMs: NOW }))).toBeNull();
  });
});

describe('calmStateOf — live state of the HUD', () => {
  it('reads the four meters, the lock and quiet hours (sleeping)', () => {
    const view = normalizeChildState(makeLiveChildState({ pet: { hunger_level: 70, thirst_level: 55, energy_level: 65, hygiene_level: 90 } }));
    expect(calmStateOf(view)).toEqual({ metrics: [70, 55, 65, 90], openMess: false, locked: false, quietHours: false });
    const asleep = normalizeChildState(makeLiveChildState({ pet: { pet_state: 'sleeping' } }));
    expect(calmStateOf(asleep).quietHours).toBe(true);
    const locked = normalizeChildState(makeLiveChildState({ lock: { is_locked: true, reason: 'hard_stopped' } }));
    expect(calmStateOf(locked).locked).toBe(true);
  });

  it('a dirty pet or an open behaviour event is a mess', () => {
    expect(calmStateOf(normalizeChildState(makeLiveChildState({ pet: { needs_cleaning: true } }))).openMess).toBe(true);
    const chewing = normalizeChildState(makeLiveChildState({ behaviour: { active_events: [makeBehaviourEvent('chewing')] } }));
    expect(calmStateOf(chewing).openMess).toBe(true);
  });
});

describe('careKindOfMutation — "care just done"', () => {
  it.each<[readonly string[], string]>([
    [['child', 'pet', 'feed'], 'feed'],
    [['child', 'pet', 'water'], 'water'],
    [['child', 'pet', 'clean'], 'clean'],
    [['child', 'pet', 'take_out'], 'take_out'],
    [['child', 'pet', 'resolve_chewing'], 'resolve_chewing'],
    [['child', 'pet', 'play'], 'play'],
    [['child', 'pet', 'socialisation'], 'socialisation'],
    [['child', 'pet', 'litter', 'scoop'], 'scoop'],
    [['child', 'pet', 'wand', 'finish'], 'wand'],
    [['child', 'pet', 'grooming', 'finish'], 'grooming'],
    [['child', 'pet', 'litter_change', 'finish'], 'litter_change'],
    [['child', 'pet', 'scratching', 'finish'], 'scratching'],
    [['child', 'pet', 'training', 'finish'], 'training'],
  ])('%j accepted → %s', (key, kind) => {
    expect(careKindOfMutation(key, { status: 'accepted' })).toBe(kind);
  });

  it('unchanged / rejected / no status → nothing done', () => {
    expect(careKindOfMutation(['child', 'pet', 'feed'], { status: 'unchanged' })).toBeNull();
    expect(careKindOfMutation(['child', 'pet', 'wand', 'finish'], { status: 'rejected' })).toBeNull();
    expect(careKindOfMutation(['child', 'pet', 'feed'], undefined)).toBeNull();
  });

  it('starts, steps and other keys never count', () => {
    expect(careKindOfMutation(['child', 'pet', 'wand', 'start'], { status: 'accepted' })).toBeNull();
    expect(careKindOfMutation(['child', 'pet', 'steps'], { status: 'accepted' })).toBeNull();
    expect(careKindOfMutation(['child', 'factOfTheDay', 'shown'], { status: 'accepted' })).toBeNull();
    expect(careKindOfMutation(undefined, { status: 'accepted' })).toBeNull();
  });

  it('stepGoalReached: the walk counts once the day goal is reached', () => {
    expect(stepGoalReached(normalizeChildState(makeLiveChildState({ steps: { steps_today: 4000, goal: 4000 } })))).toBe(true);
    expect(stepGoalReached(normalizeChildState(makeLiveChildState({ steps: { steps_today: 3999, goal: 4000 } })))).toBe(false);
    expect(stepGoalReached(normalizeChildState(makeLiveChildState({ steps: { steps_today: 10, goal: 0 } })))).toBe(false);
  });
});

describe('chip vs sheet (§3.1)', () => {
  const fotd = { fact: makeFact(), may_auto_show: true, shown_today: false };
  const base = { fact: fotd, pendingToday: false, readToday: false, locked: false, covered: false };

  it('a day the sheet may open but has not → chip', () => {
    expect(showFactChip(base)).toBe(true);
  });
  it('option A: only on a card day — not after a refusal / on another day', () => {
    expect(showFactChip({ ...base, fact: { ...fotd, may_auto_show: false } })).toBe(false);
  });
  it('a card recorded on this device whose moment passed stays reachable as the chip today', () => {
    expect(showFactChip({ ...base, pendingToday: true, fact: { ...fotd, may_auto_show: false, shown_today: true } })).toBe(true);
    expect(showFactChip({ ...base, pendingToday: true, readToday: true })).toBe(false);
  });
  it('already shown today (another device) / not allowed → no chip', () => {
    expect(showFactChip({ ...base, fact: { ...fotd, shown_today: true } })).toBe(false);
    expect(showFactChip({ ...base, fact: { ...fotd, may_auto_show: false } })).toBe(false);
  });
  it('read today, locked, covered or no fact → no chip', () => {
    expect(showFactChip({ ...base, readToday: true })).toBe(false);
    expect(showFactChip({ ...base, locked: true })).toBe(false);
    expect(showFactChip({ ...base, covered: true })).toBe(false);
    expect(showFactChip({ ...base, fact: { ...fotd, fact: null } })).toBe(false);
    expect(showFactChip({ ...base, fact: null })).toBe(false);
  });
});

describe('read marks and progress', () => {
  it('markReadInList marks one fact and counts it once', () => {
    const list = { breed: 'border_collie', facts: [makeFact({ id: 1 }), makeFact({ id: 2 })], read_count: 0, total_count: 2 };
    const once = markReadInList(list, 2, '2026-10-13T10:00:00Z');
    expect(once.facts[1]).toMatchObject({ read: true, read_at: '2026-10-13T10:00:00Z' });
    expect(once.read_count).toBe(1);
    expect(markReadInList(once, 2, '2026-10-13T11:00:00Z')).toBe(once);
    expect(markReadInList(list, 99, 'x')).toBe(list);
  });

  it('hasUnreadFacts drives the raspberry dot', () => {
    expect(hasUnreadFacts([makeFact({ read: true }), makeFact({ read: false })])).toBe(true);
    expect(hasUnreadFacts([makeFact({ read: true })])).toBe(false);
    expect(hasUnreadFacts([])).toBe(false);
  });

  it('readOnDay / familyDay use the family calendar (not the device zone)', () => {
    const view = { timezone: 'Europe/Ljubljana', server_time: '2026-10-13T23:30:00+02:00' };
    const now = Date.parse('2026-10-13T23:30:00+02:00');
    expect(familyDay(view, now)).toBe('2026-10-13');
    // 22:10 UTC = 00:10 the next family day.
    expect(readOnDay([makeFact({ read_at: '2026-10-13T22:10:00Z' })], '2026-10-13', view)).toBe(false);
    expect(readOnDay([makeFact({ read_at: '2026-10-13T21:50:00Z' })], '2026-10-13', view)).toBe(true);
    expect(readOnDay([makeFact({ read_at: null })], '2026-10-13', view)).toBe(false);
  });
});

describe('guide search + categories (§3.4)', () => {
  const facts = [
    makeFact({ id: 1, category: 'temperament', title: 'Značaj: zelo pameten in delaven.', why: null }),
    makeFact({ id: 2, category: 'exercise', title: 'Potrebuje veliko gibanja.', why: 'Zato je tvoj cilj več korakov.' }),
    makeFact({ id: 3, category: 'origin', title: 'Izvira z meje med Škotsko in Anglijo.', source_name: 'FCI' }),
  ];

  it('diacritic- and case-insensitive: "znacaj" finds "Značaj", "SKOTSKO" finds "Škotsko"', () => {
    expect(filterFacts(facts, 'znacaj', null).map((f) => f.id)).toEqual([1]);
    expect(filterFacts(facts, 'SKOTSKO', null).map((f) => f.id)).toEqual([3]);
  });
  it('searches the why line and the source too; every word must match', () => {
    expect(filterFacts(facts, 'korakov', null).map((f) => f.id)).toEqual([2]);
    expect(filterFacts(facts, 'fci', null).map((f) => f.id)).toEqual([3]);
    expect(filterFacts(facts, 'veliko pameten', null)).toEqual([]);
  });
  it('empty / blank query → all; category filters', () => {
    expect(filterFacts(facts, '   ', null)).toHaveLength(3);
    expect(filterFacts(facts, '', 'exercise').map((f) => f.id)).toEqual([2]);
    expect(filterFacts(facts, 'izvira', 'exercise')).toEqual([]);
  });
  it('presentCategories in display order, only those present', () => {
    expect(presentCategories(facts)).toEqual(['exercise', 'temperament', 'origin']);
  });
});

describe('server reasons and the child guard', () => {
  it('time-based reasons are re-asked; cap reasons are not', () => {
    expect(isTimedReason('quiet_hours')).toBe(true);
    expect(isTimedReason('locked')).toBe(true);
    expect(isTimedReason('week_cap')).toBe(false);
    expect(isTimedReason('too_soon')).toBe(false);
    expect(isTimedReason(null)).toBe(false);
  });

  it('childFactsOnly keeps audience child outside Družina / Zdravje', () => {
    expect(isChildFact(makeFact())).toBe(true);
    expect(isChildFact(makeFact({ audience: 'parent' }))).toBe(false);
    expect(isChildFact(makeFact({ category: 'health' }))).toBe(false);
    expect(isChildFact(makeFact({ category: 'family' }))).toBe(false);
    const list = { breed: 'border_collie', facts: [makeFact({ id: 1, read: true }), makeFact({ id: 2, audience: 'parent', read: true }), makeFact({ id: 3, category: 'health' })], read_count: 2, total_count: 3 };
    const out = childFactsOnly(list);
    expect(out.facts.map((f) => f.id)).toEqual([1]);
    expect(out).toMatchObject({ read_count: 1, total_count: 1 });
    const clean = { ...list, facts: [makeFact()] };
    expect(childFactsOnly(clean)).toBe(clean);
  });
});
