/**
 * M3-12 (David 2026-10-07, rule A): emergency meal at ≤ 20 % displayed hunger after a
 * missed meal window — how the app reads it, derives it between server snapshots, labels it and
 * words the result.
 */
import { i18n } from '@/i18n';
import {
  applyBroadcast,
  deriveFeeding,
  normalizeChildState,
  optimisticView,
  type ChildPetView,
} from '@/modules/childPet/childPetView';
import { feedHint, feedLabel, feedSuccessMessage } from '@/modules/childPet/actionMessages';
import { makeBroadcast, makeLiveChildState, type LiveStateOverrides } from '@/test-utils/fixtures';

function view(o: LiveStateOverrides = {}): ChildPetView {
  return normalizeChildState(makeLiveChildState(o), 0, Date.parse('2026-10-04T10:00:00Z'));
}

afterEach(async () => {
  await i18n.changeLanguage('sl');
});

describe('normalizeChildState', () => {
  it('reads feed_mode and emergency_threshold', () => {
    const v = view({ pet: { hunger_level: 10 }, feeding: { can_feed: true, feed_mode: 'emergency', emergency_threshold: 20 } });
    expect(v.feeding).toMatchObject({ can_feed: true, mode: 'emergency', emergency_threshold: 20 });
  });

  it('an older server without the keys: an allowed feed is a window meal, no emergency rule', () => {
    const raw = makeLiveChildState({ feeding: { can_feed: true } });
    const feeding = { ...raw.feeding } as Record<string, unknown>;
    delete feeding.feed_mode;
    delete feeding.emergency_threshold;
    const v = normalizeChildState({ ...raw, feeding } as typeof raw);
    expect(v.feeding).toMatchObject({ can_feed: true, mode: 'window', emergency_threshold: null });
    // …and a low hunger tick does not invent an emergency meal.
    expect(deriveFeeding({ ...v.feeding, can_feed: false, mode: null }, 0, false).can_feed).toBe(false);
  });

  it('mode is null whenever feeding is not possible', () => {
    expect(view({ feeding: { can_feed: false, feed_mode: 'emergency' } }).feeding.mode).toBeNull();
  });
});

describe('deriveFeeding (uses only what the server sent)', () => {
  const closed = view({ feeding: { can_feed: false, feed_mode: null } }).feeding;

  it('no missed meal (server threshold null) → no emergency meal however low the hunger', () => {
    const notMissed = view({ feeding: { can_feed: false, feed_mode: null, emergency_threshold: null } }).feeding;
    expect(deriveFeeding(notMissed, 0, false)).toMatchObject({ can_feed: false, mode: null });
  });

  const open = view({ feeding: { can_feed: true, feed_mode: 'window' } }).feeding;

  it.each([
    [21, false, null],
    [20, true, 'emergency'],
    [0, true, 'emergency'],
  ])('outside a window at %i %% → can_feed %s (%s)', (hunger, can, mode) => {
    expect(deriveFeeding(closed, hunger, false)).toMatchObject({ can_feed: can, mode });
  });

  it('an open window stays a window meal (on time) even at low hunger', () => {
    expect(deriveFeeding(open, 5, false)).toMatchObject({ can_feed: true, mode: 'window' });
  });

  it('never while locked or dirty (hygiene 0 % → clean first)', () => {
    expect(deriveFeeding(closed, 0, true)).toMatchObject({ can_feed: false, mode: null });
    expect(deriveFeeding(open, 0, true)).toMatchObject({ can_feed: false, mode: null });
  });
});

describe('applyBroadcast', () => {
  const base = view({ pet: { hunger_level: 25 }, feeding: { can_feed: false, feed_mode: null } });
  const at = (s: number) => `2026-10-04T10:00:${String(s).padStart(2, '0')}.000+00:00`;

  it('a tick to 20 % enables the emergency meal without waiting for a refetch', () => {
    const next = applyBroadcast(base, makeBroadcast({ hunger_level: 20, emitted_at: at(10), event_type: 'metric_changed' }));
    expect(next?.view.feeding).toMatchObject({ can_feed: true, mode: 'emergency' });
  });

  it('an on-time child (server threshold null) stays closed at 20 %', () => {
    const fedOnTime = view({ pet: { hunger_level: 25 }, feeding: { can_feed: false, feed_mode: null, emergency_threshold: null } });
    const next = applyBroadcast(fedOnTime, makeBroadcast({ hunger_level: 20, emitted_at: at(10), event_type: 'metric_changed' }));
    expect(next?.view.feeding).toMatchObject({ can_feed: false, mode: null });
  });

  it('21 % keeps it closed', () => {
    const next = applyBroadcast(base, makeBroadcast({ hunger_level: 21, emitted_at: at(10), event_type: 'metric_changed' }));
    expect(next?.view.feeding).toMatchObject({ can_feed: false, mode: null });
  });

  it('a mess (hygiene 0 %) or a hard stop blocks it', () => {
    const dirty = applyBroadcast(base, makeBroadcast({ hunger_level: 0, hygiene_level: 0, emitted_at: at(10) }));
    expect(dirty?.view.feeding.can_feed).toBe(false);
    const stopped = applyBroadcast(base, makeBroadcast({ hunger_level: 0, is_hard_stopped: true, emitted_at: at(10) }));
    expect(stopped?.view.feeding.can_feed).toBe(false);
  });
});

describe('optimistic emergency feed', () => {
  it('disables the button and leaves the window state alone (an emergency meal is outside every window)', () => {
    const v = view({ pet: { hunger_level: 5 }, feeding: { can_feed: true, feed_mode: 'emergency', fed_in_current_window: false } });
    const next = optimisticView(v, 'feed');
    expect(next.feeding).toMatchObject({ can_feed: false, mode: null, fed_in_current_window: false, emergency_threshold: null });
    expect(next.pet.hunger_level).toBe(100);
  });
});

describe('label, hint and toast', () => {
  it('"Nujni obrok" only while an emergency meal is possible; no contradicting hint', () => {
    const emergency = view({ pet: { hunger_level: 0 }, feeding: { can_feed: true, feed_mode: 'emergency' } });
    expect(feedLabel(emergency)).toBe('Nujni obrok');
    expect(feedHint(emergency)).toBeNull();

    const window = view({ feeding: { can_feed: true, feed_mode: 'window' } });
    expect(feedLabel(window)).toBe('Hrani');

    const closed = view({ pet: { hunger_level: 30 }, feeding: { can_feed: false } });
    expect(feedLabel(closed)).toBe('Hrani');
    expect(feedHint(closed)).toBe('ob 17:00');

    const dirty = view({ pet: { hunger_level: 0, hygiene_level: 0, needs_cleaning: true }, feeding: { can_feed: false } });
    expect(feedLabel(dirty)).toBe('Hrani');
    expect(feedHint(dirty)).toBe('Najprej pospravi');
  });

  it('the toast after an emergency meal is honest about the score and names the next meal', () => {
    const after = view({ pet: { hunger_level: 100 } });
    expect(feedSuccessMessage('accepted', 'emergency', after)).toBe(
      'Njam! Kuža je sit. Obrok je bil zamujen, zato ne šteje kot pravočasen — naslednji obrok je ob 17:00.',
    );
    expect(feedSuccessMessage('accepted', 'emergency', view({ feeding: { next_feed_window: null } }))).toBe(
      'Njam! Kuža je sit. Obrok je bil zamujen, zato ne šteje kot pravočasen.',
    );
    expect(feedSuccessMessage('accepted', 'window', after)).toBe('Njam! Kuža je sit.');
    expect(feedSuccessMessage('accepted', undefined, after)).toBe('Njam! Kuža je sit.');
  });

  it('English', async () => {
    await i18n.changeLanguage('en');
    const emergency = view({ pet: { hunger_level: 0 }, feeding: { can_feed: true, feed_mode: 'emergency' } });
    expect(feedLabel(emergency)).toBe('Emergency meal');
    expect(feedSuccessMessage('accepted', 'emergency', view())).toBe(
      "Yum! Your pup is full. The meal was missed, so it doesn't count as on time — the next meal is at 17:00.",
    );
  });
});
