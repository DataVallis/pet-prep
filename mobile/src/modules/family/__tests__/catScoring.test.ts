/**
 * M5-R06-08b — the parent side for a cat: routine labels, report rows per species, a day's
 * plays instead of steps, missed cat routines, timeline labels for the R06-04 / 05 activity
 * types; and the behaviour readers / cleaning choice for the cat's messes.
 */
import {
  ROUTINE_LABELS,
  activityText,
  missedLabel,
  missedWhenText,
  readChildReport,
  readDayRows,
  readTimeline,
  reportRoutineTypes,
  type RoutineType,
  type TypeTotals,
} from '@/modules/family/scoring';
import {
  afterClean,
  cleaningMess,
  needsScrubbing,
  onlyScratchingEventsOpen,
  parentBehaviourLines,
  readChildBehaviour,
  EMPTY_BEHAVIOUR,
} from '@/modules/behaviour/behaviour';
import { dayPlayText } from '@/screens/parent/ChildDetailScreen';
import { makeBehaviourEvent } from '@/test-utils/fixtures';

const ZERO: TypeTotals = { expected: 0, done: 0, done_by_child: 0, missed: 0, pending: 0 };
const byType = (counted: Partial<Record<RoutineType, Partial<TypeTotals>>> = {}): Record<RoutineType, TypeTotals> => {
  const all = ['feed', 'water', 'clean', 'walk', 'training', 'play', 'litter_scoop', 'litter_change', 'grooming'] as const;
  return Object.fromEntries(all.map((k) => [k, { ...ZERO, ...counted[k] }])) as Record<RoutineType, TypeTotals>;
};

describe('routine labels and report rows per species', () => {
  it('labels for the cat routines (SL)', () => {
    expect([ROUTINE_LABELS.play, ROUTINE_LABELS.litter_scoop, ROUTINE_LABELS.litter_change, ROUTINE_LABELS.grooming]).toEqual([
      'Igra',
      'Pesek',
      'Menjava peska',
      'Česanje',
    ]);
  });

  it('a dog keeps its rows (training only with training) — no cat rows', () => {
    expect(reportRoutineTypes('dog', 'mutt', byType(), false)).toEqual(['feed', 'water', 'clean', 'walk']);
    expect(reportRoutineTypes('dog', 'border_collie', byType(), true)).toEqual(['feed', 'water', 'clean', 'walk', 'training']);
    expect(reportRoutineTypes(null, null, byType(), false)).toEqual(['feed', 'water', 'clean', 'walk']);
  });

  it('a cat: litter, play, weekly change; brushing only for a Maine Coon; no walk / training', () => {
    expect(reportRoutineTypes('cat', 'domestic_cat', byType(), false)).toEqual(['feed', 'water', 'clean', 'play', 'litter_scoop', 'litter_change']);
    expect(reportRoutineTypes('cat', 'maine_coon', byType(), true)).toEqual(['feed', 'water', 'clean', 'play', 'litter_scoop', 'litter_change', 'grooming']);
  });

  it('any type the server counted is shown', () => {
    expect(reportRoutineTypes('cat', 'domestic_cat', byType({ walk: { missed: 1 } }), false)).toContain('walk');
  });

  it('the report reads every type (older server → zeros)', () => {
    const report = readChildReport({ child: { id: 1, name: 'Ana' }, by_type: { play: { expected: 7, done: 5, done_by_child: 4, missed: 2 } } });
    expect(report?.by_type.play).toEqual({ expected: 7, done: 5, done_by_child: 4, missed: 2, pending: 0 });
    expect(report?.by_type.grooming).toEqual(ZERO);
  });
});

describe('a cat\'s day: plays instead of steps', () => {
  it('reads play_sessions / play_goal / play_done (null for a dog / older server)', () => {
    const [cat, dog] = readDayRows([
      { date: '2026-10-21', play_sessions: 1, play_goal: 2, play_done: false },
      { date: '2026-10-21', walk_steps: 4000 },
    ]);
    expect(cat).toMatchObject({ play_sessions: 1, play_goal: 2, play_done: false });
    expect(dog).toMatchObject({ play_sessions: null, play_goal: null, play_done: null, walk_steps: 4000 });
  });

  it('"Igra 1/2"; nothing without a play routine', () => {
    expect(dayPlayText({ play_sessions: 1, play_goal: 2 })).toBe('Igra 1/2');
    expect(dayPlayText({ play_sessions: null, play_goal: null })).toBeNull();
  });
});

describe('missed cat routines', () => {
  const tz = 'Europe/Ljubljana';
  it('litter: its deadline; weekly change / brushing: end of the week; play: end of the day', () => {
    const base = { kind: null, date: '2026-10-21', opens_at: '2026-10-21T09:00:00+02:00', due_at: '2026-10-21T13:00:00+02:00' };
    expect(missedWhenText({ ...base, type: 'litter_scoop' }, tz)).toBe('rok 13:00');
    expect(missedWhenText({ ...base, type: 'litter_change' }, tz)).toBe('do konca tedna');
    expect(missedWhenText({ ...base, type: 'grooming' }, tz)).toBe('do konca tedna');
    expect(missedWhenText({ ...base, type: 'play' }, tz)).toBe('do konca dneva');
  });

  it('a missed clean names the cat\'s mess', () => {
    expect(missedLabel({ type: 'clean', kind: 'litter_accident' })).toBe('Nered zraven peska');
    expect(missedLabel({ type: 'clean', kind: 'scratching' })).toBe('Opraskan kavč');
  });
});

describe('timeline labels for the cat (R06-04 / 05 activity types)', () => {
  it('child actions', () => {
    const row = (activity_type: string) => activityText({ activity_type, actor_nickname: 'Ana' }, 'cat');
    expect(row('played_wand')).toBe('Ana se je igral(a) s palico s peresom');
    expect(row('scooped_litter')).toBe('Ana počistil(a) pesek');
    expect(row('changed_litter')).toBe('Ana zamenjal(a) ves pesek');
    expect(row('groomed_pet')).toBe('Ana počesal(a) muco');
    expect(row('resolved_scratching')).toBe('Ana odnesel(a) muco na praskalnik in jo pohvalil(a)');
    expect(row('fed_pet')).toBe('Ana nahranil(a) muco');
  });

  it('a dog keeps "kužka"', () => {
    expect(activityText({ activity_type: 'fed_pet', actor_nickname: 'Ana' })).toBe('Ana nahranil(a) kužka');
    expect(activityText({ activity_type: 'fed_pet', actor_nickname: 'Ana' }, 'dog')).toBe('Ana nahranil(a) kužka');
  });

  it('system rows are bad news (also without is_positive from the server)', () => {
    expect(activityText({ activity_type: 'pet_scratched', actor_nickname: null }, 'cat')).toBe('Muca je opraskala kavč');
    expect(activityText({ activity_type: 'pet_litter_accident', actor_nickname: null }, 'cat')).toBe('Muca je naredila nered zraven peska');
    expect(activityText({ activity_type: 'pet_coat_matted', actor_nickname: null }, 'cat')).toBe('Muci se je zavozlala dlaka');
    const rows = readTimeline([
      { id: 1, activity_type: 'pet_scratched' },
      { id: 2, activity_type: 'pet_litter_accident' },
      { id: 3, activity_type: 'pet_coat_matted' },
      { id: 4, activity_type: 'scooped_litter' },
    ]);
    expect(rows.map((r) => r.is_positive)).toEqual([false, false, false, true]);
  });
});

describe('behaviour: the cat\'s messes', () => {
  const ev = makeBehaviourEvent;

  it('reads litter_accident / scratching events', () => {
    const b = readChildBehaviour({ active_events: [ev('litter_accident', { id: 1 }), ev('scratching', { id: 2 })], scene: 'scratching' });
    expect(b.active_events.map((e) => e.kind)).toEqual(['litter_accident', 'scratching']);
    expect(b.scene).toBe('scratching');
  });

  it('a scratched sofa is never scrubbed; a mess next to the tray is (litter title)', () => {
    expect(needsScrubbing(true, { active_events: [ev('scratching')] })).toBe(false);
    expect(onlyScratchingEventsOpen({ active_events: [ev('scratching')] })).toBe(true);
    expect(needsScrubbing(true, { active_events: [ev('scratching'), ev('litter_accident', { id: 2 })] })).toBe(true);
    expect(cleaningMess({ active_events: [ev('litter_accident')] })).toBe('litter');
    expect(cleaningMess({ active_events: [ev('litter_accident'), ev('scratching', { id: 2 })] })).toBe('litter');
    // Dogs unchanged.
    expect(cleaningMess({ active_events: [ev('accident')] })).toBe('accident');
    expect(cleaningMess({ active_events: [ev('poop'), ev('accident', { id: 2 })] })).toBe('poop');
  });

  it('cleaning leaves the scratched sofa open', () => {
    const after = afterClean({ ...EMPTY_BEHAVIOUR, active_events: [ev('litter_accident', { id: 1 }), ev('scratching', { id: 2 })], scene: 'scratching' });
    expect(after.active_events.map((e) => e.kind)).toEqual(['scratching']);
    expect(after.scene).toBe('scratching');
  });

  it('the parent sees the cat\'s open messes', () => {
    const lines = parentBehaviourLines({ take_out: null, active_events: [ev('scratching', { due_at: '2026-10-04T13:30:00+02:00' })], scene: 'scratching' }, 'Europe/Ljubljana', '2026-10-04T10:00:00Z');
    expect(lines).toEqual(['Opraskan kavč — na praskalnik do 13:30']);
  });
});
