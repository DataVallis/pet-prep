/**
 * Dock hints (device feedback 2026-10-07: "tomorrow at 0…" was ellipsised at 375 pt).
 * The time is always its own line; "tomorrow" / "jutri" goes on a small line above it.
 * Toasts and refusals keep the full sentence. Both languages.
 */
import { i18n } from '@/i18n';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { feedDockHint, feedHint, feedLabel, refusalMessage, waterDockHint, waterHint } from '@/modules/childPet/actionMessages';
import { dockText, dockWhen, toDockHint } from '@/modules/childPet/dockHint';
import { takeOutCountdown } from '@/modules/behaviour/behaviour';
import { makeLiveChildState, makeTakeOut, type LiveStateOverrides } from '@/test-utils/fixtures';

const TZ = 'Europe/Ljubljana';
const NOW = '2026-10-04T21:30:00+02:00';

function view(o: LiveStateOverrides = {}): ChildPetView {
  return normalizeChildState(makeLiveChildState({ server_time: NOW, ...o }), 0, Date.parse(NOW));
}

const tomorrowFeed = (o: LiveStateOverrides = {}) =>
  view({ feeding: { can_feed: false, next_feed_window: { start: '2026-10-05T06:00:00+02:00', end: '2026-10-05T09:00:00+02:00' } }, ...o });
const todayFeed = () =>
  view({ server_time: '2026-10-04T12:00:00+02:00', feeding: { can_feed: false, next_feed_window: { start: '2026-10-04T17:00:00+02:00', end: '2026-10-04T21:00:00+02:00' } } });

afterEach(async () => {
  await i18n.changeLanguage('sl');
});

describe('dockWhen', () => {
  it('today: one line "ob 17:00" (sl) / "at 17:00" (en)', async () => {
    expect(dockWhen('2026-10-04T17:00:00+02:00', '2026-10-04T12:00:00+02:00', TZ)).toEqual({ day: null, text: 'ob 17:00', a11y: 'ob 17:00' });
    await i18n.changeLanguage('en');
    expect(dockWhen('2026-10-04T17:00:00+02:00', '2026-10-04T12:00:00+02:00', TZ)).toEqual({ day: null, text: 'at 17:00', a11y: 'at 17:00' });
  });

  it('tomorrow: a day line above the bare time; the screen reader gets the full phrase', async () => {
    expect(dockWhen('2026-10-05T06:00:00+02:00', NOW, TZ)).toEqual({ day: 'jutri', text: '06:00', a11y: 'jutri ob 06:00' });
    await i18n.changeLanguage('en');
    expect(dockWhen('2026-10-05T06:00:00+02:00', NOW, TZ)).toEqual({ day: 'tomorrow', text: '06:00', a11y: 'tomorrow at 06:00' });
  });

  it('uses the family zone, not the device zone (New York family, UTC instant)', () => {
    expect(dockWhen('2026-10-05T10:00:00Z', '2026-10-04T21:30:00-04:00', 'America/New_York')).toMatchObject({ day: 'jutri', text: '06:00' });
  });

  it('the time line is short in every language (never needs an ellipsis)', async () => {
    for (const lng of ['sl', 'en']) {
      await i18n.changeLanguage(lng);
      const hint = dockWhen('2026-10-05T06:00:00+02:00', NOW, TZ);
      expect(hint?.text).toBe('06:00');
      expect((hint?.day ?? '').length).toBeLessThanOrEqual(8);
    }
  });

  it('missing or broken instants → null', () => {
    expect(dockWhen(null, NOW, TZ)).toBeNull();
    expect(dockWhen('not a date', NOW, TZ)).toBeNull();
  });

  it('dockText / toDockHint', () => {
    expect(dockText('Čisto')).toEqual({ day: null, text: 'Čisto', a11y: 'Čisto' });
    expect(toDockHint('4.857/4.000')).toEqual(dockText('4.857/4.000'));
    expect(toDockHint('')).toBeNull();
    expect(toDockHint(null)).toBeNull();
    expect(toDockHint(undefined)).toBeNull();
  });
});

describe('feed dock hint', () => {
  it('today (sl / en)', async () => {
    expect(feedDockHint(todayFeed())).toEqual({ day: null, text: 'ob 17:00', a11y: 'ob 17:00' });
    await i18n.changeLanguage('en');
    expect(feedDockHint(todayFeed())).toEqual({ day: null, text: 'at 17:00', a11y: 'at 17:00' });
  });

  it('tomorrow (sl / en): "jutri" + "06:00", feedHint keeps the full phrase', async () => {
    expect(feedDockHint(tomorrowFeed())).toEqual({ day: 'jutri', text: '06:00', a11y: 'jutri ob 06:00' });
    expect(feedHint(tomorrowFeed())).toBe('jutri ob 06:00');
    await i18n.changeLanguage('en');
    expect(feedDockHint(tomorrowFeed())).toEqual({ day: 'tomorrow', text: '06:00', a11y: 'tomorrow at 06:00' });
  });

  it('emergency meal possible (M3-12): button enabled, label "Nujni obrok" / "Emergency meal", no hint', async () => {
    const emergency = tomorrowFeed({ pet: { hunger_level: 12 }, feeding: { can_feed: true, feed_mode: 'emergency', emergency_threshold: 20, next_feed_window: { start: '2026-10-05T06:00:00+02:00', end: '2026-10-05T09:00:00+02:00' } } });
    expect(feedDockHint(emergency)).toBeNull();
    expect(feedLabel(emergency)).toBe('Nujni obrok');
    await i18n.changeLanguage('en');
    expect(feedLabel(emergency)).toBe('Emergency meal');
  });

  it('needs cleaning → "Najprej pospravi" / "Clean up first"; locked → nothing', async () => {
    const dirty = tomorrowFeed({ pet: { needs_cleaning: true, hygiene_level: 0 } });
    expect(feedDockHint(dirty)).toEqual(dockText('Najprej pospravi'));
    await i18n.changeLanguage('en');
    expect(feedDockHint(dirty)).toEqual(dockText('Clean up first'));
    expect(feedDockHint(tomorrowFeed({ lock: { is_locked: true, reason: 'hard_stopped' } }))).toBeNull();
  });

  it('the refusal toast keeps the full sentence with "jutri ob 06:00"', () => {
    expect(refusalMessage('outside_feed_window', null, tomorrowFeed())).toContain('jutri ob 06:00');
  });
});

describe('water dock hint', () => {
  it('later today → "ob 23:15"; daily limit → "jutri" / "tomorrow" (one line)', async () => {
    const soon = view({ water: { can_water: false, next_allowed_at: '2026-10-04T23:15:00+02:00' } });
    expect(waterDockHint(soon)).toEqual({ day: null, text: 'ob 23:15', a11y: 'ob 23:15' });
    const done = view({ water: { can_water: false, next_allowed_at: '2026-10-05T06:00:00+02:00' } });
    expect(waterDockHint(done)).toEqual(dockText('jutri'));
    expect(waterHint(done)).toBe('jutri');
    await i18n.changeLanguage('en');
    expect(waterDockHint(done)).toEqual(dockText('tomorrow'));
  });
});

describe('puppy "take out" dock hint', () => {
  it('a later family day is two lines; today stays a plain string', async () => {
    const at = (iso: string) => Date.parse(iso);
    const tomorrow = takeOutCountdown(makeTakeOut({ next_due_at: '2026-10-05T07:10:00+02:00' }), at('2026-10-04T21:00:00+02:00'), TZ);
    expect(tomorrow?.hint).toEqual({ day: 'jutri', text: '07:10', a11y: 'jutri ob 07:10' });
    expect(tomorrow?.line).toBe('Kuža bo moral ven jutri ob 07:10');
    await i18n.changeLanguage('en');
    const en = takeOutCountdown(makeTakeOut({ next_due_at: '2026-10-05T07:10:00+02:00' }), at('2026-10-04T21:00:00+02:00'), TZ);
    expect(en?.hint).toEqual({ day: 'tomorrow', text: '07:10', a11y: 'tomorrow at 07:10' });
  });
});
