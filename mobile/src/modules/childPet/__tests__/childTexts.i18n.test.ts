/**
 * M1-18: child / behaviour / training texts in both languages — time phrases, CLDR
 * plurals (sl: one/two/few/other, incl. 101–104) and a few English lines.
 */
import { i18n } from '@/i18n';
import { whenText } from '@/modules/childPet/familyTime';
import { failureMessage, successMessage, waterHint } from '@/modules/childPet/actionMessages';
import { PARENT_BEHAVIOUR_STRINGS, takeOutCountdown } from '@/modules/behaviour/behaviour';
import { knowsLine, TRAINING_STRINGS, trainingRefusalMessage } from '@/modules/training/training';
import { lockedCopy } from '@/screens/LockedScreen';
import { formatChallengeWeek } from '@/screens/ChildHudScreen';
import { formatSteps } from '@/modules/steps/stepCounter';
import { normalizeChildState } from '@/modules/childPet/childPetView';
import { makeLiveChildState } from '@/test-utils/fixtures';

const TZ = 'Europe/Ljubljana';
const NOW = '2026-10-04T12:00:00+02:00';

afterEach(async () => {
  await i18n.changeLanguage('sl');
});

describe('time phrases', () => {
  it('Slovenian (test device default)', () => {
    expect(whenText('2026-10-04T17:00:00+02:00', NOW, TZ)).toBe('ob 17:00');
    expect(whenText('2026-10-05T06:00:00+02:00', NOW, TZ)).toBe('jutri ob 06:00');
  });

  it('English', async () => {
    await i18n.changeLanguage('en');
    expect(whenText('2026-10-04T17:00:00+02:00', NOW, TZ)).toBe('at 17:00');
    expect(whenText('2026-10-05T06:00:00+02:00', NOW, TZ)).toBe('tomorrow at 06:00');
  });
});

describe('plurals', () => {
  it('training sessions left — Slovenian CLDR categories', () => {
    expect(TRAINING_STRINGS.sessionsLeft(0)).toBe('Danes še 0 vaj.');
    expect(TRAINING_STRINGS.sessionsLeft(1)).toBe('Danes še 1 vaja.');
    expect(TRAINING_STRINGS.sessionsLeft(2)).toBe('Danes še 2 vaji.');
    expect(TRAINING_STRINGS.sessionsLeft(3)).toBe('Danes še 3 vaje.');
    expect(TRAINING_STRINGS.sessionsLeft(4)).toBe('Danes še 4 vaje.');
    expect(TRAINING_STRINGS.sessionsLeft(5)).toBe('Danes še 5 vaj.');
    expect(TRAINING_STRINGS.sessionsLeft(101)).toBe('Danes še 101 vaja.');
    expect(TRAINING_STRINGS.sessionsLeft(102)).toBe('Danes še 102 vaji.');
    expect(TRAINING_STRINGS.sessionsLeft(103)).toBe('Danes še 103 vaje.');
  });

  it('training sessions left — English', async () => {
    await i18n.changeLanguage('en');
    expect(TRAINING_STRINGS.sessionsLeft(1)).toBe('1 more practice today.');
    expect(TRAINING_STRINGS.sessionsLeft(3)).toBe('3 more practices today.');
  });

  it('parent "last N days" in both languages', async () => {
    expect(PARENT_BEHAVIOUR_STRINGS.lastDays(1)).toBe('Zadnji dan');
    expect(PARENT_BEHAVIOUR_STRINGS.lastDays(2)).toBe('Zadnja 2 dneva');
    expect(PARENT_BEHAVIOUR_STRINGS.lastDays(4)).toBe('Zadnji 4 dnevi');
    expect(PARENT_BEHAVIOUR_STRINGS.lastDays(7)).toBe('Zadnjih 7 dni');
    expect(PARENT_BEHAVIOUR_STRINGS.lastDays(101)).toBe('Zadnji 101 dan');
    await i18n.changeLanguage('en');
    expect(PARENT_BEHAVIOUR_STRINGS.lastDays(1)).toBe('Last day');
    expect(PARENT_BEHAVIOUR_STRINGS.lastDays(7)).toBe('Last 7 days');
  });
});

describe('English child copy', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en');
  });

  it('care actions: success, refusals and offline', () => {
    const view = normalizeChildState(makeLiveChildState());
    expect(successMessage('feed', 'accepted')).toBe('Yum! Your pup is full.');
    expect(successMessage('take_out', 'accepted')).toBe('Great job! Your pup went potty outside.');
    expect(failureMessage({ kind: 'offline' }, view)).toBe('No connection. Check the internet and try again.');
    expect(
      failureMessage({ kind: 'refused', reason: 'water_too_soon', nextAllowedAt: '2026-10-04T17:00:00+02:00', state: null }, view),
    ).toBe('The bowl is still full. You can give fresh water at 17:00.');
  });

  it('water hint "tomorrow" when the next water is on a later day', () => {
    const view = normalizeChildState(makeLiveChildState({ water: { can_water: false, next_allowed_at: null } }));
    expect(waterHint(view)).toBe('tomorrow');
  });

  it('puppy countdown', () => {
    const clock = { hold_hours: 2, clock_started_at: NOW, next_due_at: '2026-10-04T13:20:00+02:00', last_taken_out_at: null };
    expect(takeOutCountdown(clock, Date.parse(NOW), TZ)?.line).toBe('Your pup will need to go out in ~1 h 20 min');
    expect(takeOutCountdown(clock, Date.parse('2026-10-04T13:20:00+02:00'), TZ)?.line).toBe('Your pup wants to go outside.');
  });

  it('training: refusals with a time, result lines and the parent summary', () => {
    expect(trainingRefusalMessage('training_session_active', '2026-10-04T12:01:00+02:00', TZ)).toBe(
      'Someone is already practising with your pup. Try again at 12:01.',
    );
    expect(TRAINING_STRINGS.result.gain('Sit', 40, 60)).toBe('Sit: 40% → 60%');
    expect(
      knowsLine([
        { command: 'sit', progress: 100, learned: true, last_practised_at: null },
        { command: 'come', progress: 60, learned: false, last_practised_at: null },
      ]),
    ).toBe('The dog knows: sit ✓, come 60%');
  });

  it('lock screen, challenge week and step grouping', () => {
    expect(lockedCopy('illness', { until: '2026-10-04T18:30:00+02:00', timezone: TZ }, TZ)).toEqual({
      title: 'Your pup is at the vet',
      body: 'Your pup is at the vet until 18:30. It needs some rest.',
    });
    expect(lockedCopy('hard_stop', { until: null, timezone: null }, TZ).title).toBe('A parent paused the game');
    expect(formatChallengeWeek(0)).toBe('WEEK 1 OF 12');
    expect(formatSteps(12500)).toBe('12,500');
  });
});
