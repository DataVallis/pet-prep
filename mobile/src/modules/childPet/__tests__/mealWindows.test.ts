/**
 * "Obroki danes" (M5-R04): today's windows from `pet.profile.today.feed_windows`, done /
 * now / past / "nahrani starš" in the family zone.
 */
import { normalizeChildState, readTodayFeedWindows } from '@/modules/childPet/childPetView';
import { buildMealWindows, mealWindowsA11y, shortClock } from '@/modules/childPet/mealWindows';
import { makeLiveChildState, makePetProfile } from '@/test-utils/fixtures';

type Overrides = Parameters<typeof makeLiveChildState>[0];
const view = (o: Overrides = {}) => normalizeChildState(makeLiveChildState(o));

/** The fixture profile's windows with some marked fed. */
function profileFed(fed: readonly string[]) {
  const base = makePetProfile();
  return makePetProfile({
    today: { ...base.today, feed_windows: base.today.feed_windows.map((w) => ({ ...w, fed: fed.includes(w.start) })) },
  });
}

describe('readTodayFeedWindows', () => {
  it('reads profile.today.feed_windows (legacy too) and drops malformed entries', () => {
    expect(readTodayFeedWindows(profileFed(['07:00'])).map((w) => `${w.start}-${w.end}:${w.parent_covered}:${w.fed}`)).toEqual([
      '07:00-09:00:false:true',
      '11:00-13:00:true:false',
      '15:00-17:00:false:false',
      '19:00-21:00:false:false',
    ]);
    expect(
      readTodayFeedWindows({
        today: { feed_windows: [null, { start: '7', end: '09:00' }, { start: '06:00', end: '10:00', parent_covered: 'yes', fed: 'true' }] },
      }),
    ).toEqual([{ start: '06:00', end: '10:00', parent_covered: false, fed: false }]);
    expect(readTodayFeedWindows(undefined)).toEqual([]);
    expect(readTodayFeedWindows({ today: null })).toEqual([]);
    expect(readTodayFeedWindows({ today: { feed_windows: 'x' } })).toEqual([]);
  });

  it('lands in the view as feeding.today', () => {
    expect(view().feeding.today).toHaveLength(4);
  });
});

describe('buildMealWindows', () => {
  it('12:00, no window open: ✓ from the server per window, parent label, later ones upcoming', () => {
    const items = buildMealWindows(view({ pet: { profile: profileFed(['07:00']) } }));
    expect(items.map((i) => i.label)).toEqual(['7–9', '11–13', '15–17', '19–21']);
    expect(items[0]).toMatchObject({ done: true, current: false, past: false, byParent: false });
    expect(items[1]).toMatchObject({ done: false, current: false, past: false, byParent: true });
    expect(items[2]).toMatchObject({ done: false, current: false, past: false });
    expect(items[3]).toMatchObject({ done: false, current: false, past: false });
  });

  const open = {
    server_time: '2026-10-04T15:30:00+02:00',
    feeding: {
      current_window: { start: '2026-10-04T15:00:00+02:00', end: '2026-10-04T17:00:00+02:00' },
      fed_in_current_window: false,
      last_fed_at: '2026-10-04T15:05:00+02:00',
    },
  };

  it('every fed window keeps its ✓ (not only the last meal); the parent meal ticks too', () => {
    const items = buildMealWindows(view({ ...open, pet: { profile: profileFed(['07:00', '11:00', '15:00']) } }));
    expect(items[0]).toMatchObject({ done: true, past: false });
    expect(items[1]).toMatchObject({ done: true, past: false, byParent: true });
    expect(items[2]).toMatchObject({ done: true, current: true });
  });

  it('ignores last_fed_at: an ended window with fed=false is only "past" (dimmed, never missed)', () => {
    const items = buildMealWindows(view({ ...open, feeding: { ...open.feeding, last_fed_at: '2026-10-04T07:10:00+02:00' } }));
    expect(items[0]).toMatchObject({ done: false, past: true });
    expect(items[1]).toMatchObject({ done: false, past: true, byParent: true });
    expect(items[2]).toMatchObject({ current: true, done: false, past: false });
  });

  it('optimistic feed: the current window ticks from fed_in_current_window before the state refreshes', () => {
    const items = buildMealWindows(view({ ...open, feeding: { ...open.feeding, fed_in_current_window: true } }));
    expect(items[2]).toMatchObject({ current: true, done: true });
    // fed_in_current_window never ticks another window.
    expect(items[0].done).toBe(false);
  });

  it('works in the family zone, not UTC', () => {
    const items = buildMealWindows(
      view({
        server_time: '2026-10-04T13:30:00Z', // 15:30 in Ljubljana
        feeding: { current_window: { start: '2026-10-04T13:00:00Z', end: '2026-10-04T15:00:00Z' } },
      }),
    );
    expect(items[2].current).toBe(true);
    expect(items[0].past).toBe(true);
    expect(items[3].past).toBe(false);
  });

  it('a midnight-crossing window that opened yesterday does not light tonight\'s chip', () => {
    const late = makePetProfile({
      today: {
        ...makePetProfile().today,
        date: '2026-10-05',
        feed_windows: [
          { start: '06:00', end: '10:00', parent_covered: false, fed: false },
          { start: '22:00', end: '02:00', parent_covered: false, fed: false },
        ],
      },
    });
    const base = {
      pet: { profile: late },
      feeding: { fed_in_current_window: true },
    };
    // 01:00 on 5 Oct: the open window is the 22–02 that started on 4 Oct.
    const night = buildMealWindows(
      view({
        ...base,
        server_time: '2026-10-05T01:00:00+02:00',
        feeding: { ...base.feeding, current_window: { start: '2026-10-04T22:00:00+02:00', end: '2026-10-05T02:00:00+02:00' } },
      }),
    );
    expect(night[1]).toMatchObject({ current: false, done: false, past: false });
    // 23:00 on 5 Oct: tonight's window is the current one.
    const evening = buildMealWindows(
      view({
        ...base,
        server_time: '2026-10-05T23:00:00+02:00',
        feeding: { ...base.feeding, current_window: { start: '2026-10-05T22:00:00+02:00', end: '2026-10-06T02:00:00+02:00' } },
      }),
    );
    expect(evening[1]).toMatchObject({ current: true, done: true });
  });

  it('no windows → nothing', () => {
    expect(buildMealWindows(view({ pet: { profile: makePetProfile({ today: { ...makePetProfile().today, feed_windows: [] } }) } }))).toEqual([]);
  });

  it('short clock labels', () => {
    expect(shortClock('07:00')).toBe('7');
    expect(shortClock('07:30')).toBe('7:30');
    expect(shortClock('19:00')).toBe('19');
  });

  it('one sentence for the screen reader', () => {
    expect(mealWindowsA11y(buildMealWindows(view({ pet: { profile: profileFed(['07:00']) } })))).toBe(
      'Obroki danes: 7–9 nahranjen, 11–13 nahrani starš, 15–17, 19–21',
    );
  });
});
