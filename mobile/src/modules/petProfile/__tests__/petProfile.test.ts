/**
 * M5-R04: reading the pet `profile` payload safely and the "Izberi kužka" choice.
 */
import {
  formatDogAge,
  formatProfileDate,
  mealsLine,
  nextStageLine,
  originLine,
  readPetProfile,
  stageLine,
} from '@/modules/petProfile/petProfile';
import {
  breedLockReason,
  choiceWithPlan,
  completeChoice,
  INITIAL_PICKER_CHOICE,
  isBreedLocked,
  lockedBreedsFor,
} from '@/modules/petProfile/picker';
import { i18n, t } from '@/i18n';
import { makeLegacyPetProfile, makePetProfile } from '@/test-utils/fixtures';

describe('readPetProfile', () => {
  it('reads stage, age, origin, next stage and sourced meals', () => {
    const info = readPetProfile(makePetProfile({ age_months: 3, data_verified: false, unverified: ['feed_windows'] }));
    expect(info).toEqual({
      ageMonths: 3,
      stage: 'puppy',
      origin: 'bought',
      nextStage: { stage: 'young', fromDate: '2026-11-24' },
      meals: { perDay: 4, byParent: 1 },
    });
    expect(stageLine(info!)).toBe('Mladiček · 3 mesece');
    expect(originLine(info!)).toBe('Kupljen pri vzreditelju');
    expect(nextStageLine(info!)).toBe('Od 24. 11. 2026 mlad pes');
    expect(nextStageLine(info!, 'child')).toBe('24. 11. 2026 postane mlad pes');
    expect(mealsLine(info!)).toBe('Danes 4 obroki — 1 v tihih urah nahrani starš');
  });

  it.each([
    ['legacy pet', makeLegacyPetProfile()],
    ['legacy flag with stray values', makePetProfile({ legacy: true })],
    ['null age (unborn / malformed)', makePetProfile({ age_months: null })],
    ['missing profile (older server)', undefined],
    ['null', null],
    ['a string', 'legacy'],
  ])('%s → null (nothing new is shown)', (_label, raw) => {
    expect(readPetProfile(raw)).toBeNull();
  });

  it('tolerates partial data: no stage, unknown origin, bad next stage, unsourced meals', () => {
    const info = readPetProfile({
      ...makePetProfile(),
      life_stage: null,
      origin: 'stolen',
      next_stage: { life_stage: 'young', from_date: 'soon' },
      unverified: ['meals_per_day'],
    });
    expect(info).not.toBeNull();
    expect(stageLine(info!)).toBe('2 meseca');
    expect(originLine(info!)).toBeNull();
    expect(nextStageLine(info!)).toBeNull();
    expect(mealsLine(info!)).toBeNull();
  });

  it('two meals with none by the parent', () => {
    const info = readPetProfile(
      makePetProfile({
        life_stage: 'senior',
        age_months: 108,
        origin: 'adopted',
        next_stage: null,
        today: { ...makePetProfile().today, meals_per_day: 2, meals_by_child: 2, meals_by_parent: 0 },
      }),
    );
    expect(stageLine(info!)).toBe('Starejši · 9 let');
    expect(originLine(info!)).toBe('Posvojen iz zavetišča');
    expect(nextStageLine(info!)).toBeNull();
    expect(mealsLine(info!)).toBe('Danes 2 obroka');
  });
});

describe('formatDogAge / formatProfileDate', () => {
  it('Slovenian forms (months below 2 years, then years)', () => {
    expect(formatDogAge(1)).toBe('1 mesec');
    expect(formatDogAge(2)).toBe('2 meseca');
    expect(formatDogAge(4)).toBe('4 mesece');
    expect(formatDogAge(9)).toBe('9 mesecev');
    expect(formatDogAge(23)).toBe('23 mesecev');
    expect(formatDogAge(24)).toBe('2 leti');
    expect(formatDogAge(26)).toBe('2 leti in 2 meseca');
    expect(formatDogAge(36)).toBe('3 leta');
    expect(formatDogAge(61)).toBe('5 let in 1 mesec');
    expect(formatDogAge(-3)).toBe('0 mesecev');
  });

  it('formats a family-local date without time-zone shifts', () => {
    expect(formatProfileDate('2026-01-05')).toBe('5. 1. 2026');
  });
});

describe('picker choice', () => {
  const dog = { species: 'dog' } as const;

  it('is complete only with species, plan, origin and age (M3-09: no default plan)', () => {
    expect(completeChoice(INITIAL_PICKER_CHOICE)).toBeNull();
    expect(completeChoice({ ...dog, plan: null, breed: 'mutt', origin: 'adopted', age_stage: 'senior' })).toBeNull();
    expect(completeChoice({ ...dog, plan: 'challenge', breed: 'border_collie', origin: 'bought', age_stage: null })).toBeNull();
    expect(completeChoice({ ...dog, plan: 'challenge', breed: 'border_collie', origin: null, age_stage: 'puppy' })).toBeNull();
    // M5-R06-02: no species yet → never complete.
    expect(completeChoice({ species: null, plan: 'free', breed: 'mutt', origin: 'adopted', age_stage: 'senior' })).toBeNull();
    expect(completeChoice({ ...dog, plan: 'free', breed: 'mutt', origin: 'adopted', age_stage: 'senior' })).toEqual({
      species: 'dog',
      breed: 'mutt',
      origin: 'adopted',
      age_stage: 'senior',
      plan: 'free',
    });
  });

  it('M5-F03: the challenge needs a paid breed — the mutt is locked while it is chosen', () => {
    expect(lockedBreedsFor('challenge')).toEqual(['mutt']);
    expect(isBreedLocked('mutt', lockedBreedsFor('challenge'))).toBe(true);
    // Challenge + mutt is never a complete choice (the server would answer 422).
    expect(completeChoice({ ...dog, plan: 'challenge', breed: 'mutt', origin: 'adopted', age_stage: 'senior' })).toBeNull();
    // Why a breed is locked (note + a11y label).
    expect(breedLockReason('mutt', 'challenge')).toBe('free_only');
    expect(breedLockReason('border_collie', 'free')).toBe('challenge_only');
    expect(breedLockReason('border_collie', null)).toBe('challenge_only');
    expect(breedLockReason('border_collie', 'challenge', ['border_collie'])).toBe('server');
    expect(breedLockReason('mutt', 'free')).toBeNull();
    expect(breedLockReason('border_collie', 'challenge')).toBeNull();
  });

  it('M5-F03: switching plans never leaves an invalid breed selected', () => {
    const muttFree = { ...dog, plan: 'free' as const, breed: 'mutt' as const, origin: 'bought' as const, age_stage: 'puppy' as const };
    // free (mutt) → challenge: moves to the first paid breed.
    const challenge = choiceWithPlan(muttFree, 'challenge');
    expect(challenge).toEqual({ ...muttFree, plan: 'challenge', breed: 'border_collie' });
    expect(completeChoice(challenge)).toEqual({ species: 'dog', breed: 'border_collie', origin: 'bought', age_stage: 'puppy', plan: 'challenge' });
    // challenge → free: always the species' free breed (the mutt).
    expect(choiceWithPlan(challenge, 'free')).toEqual(muttFree);
    // No plan yet (initial choice) → challenge: the collie.
    expect(choiceWithPlan(INITIAL_PICKER_CHOICE, 'challenge').breed).toBe('border_collie');
    // A pickable breed is kept.
    expect(choiceWithPlan(challenge, 'challenge')).toEqual(challenge);
    // Every paid breed refused by the server: nothing pickable → the choice stays incomplete.
    const stuck = choiceWithPlan(muttFree, 'challenge', ['border_collie']);
    expect(stuck.plan).toBe('challenge');
    expect(completeChoice(stuck, ['border_collie'])).toBeNull();
  });

  it('premium breeds need the challenge plan; the free plan is always the free breed', () => {
    expect(lockedBreedsFor('free')).toEqual(['border_collie']);
    expect(lockedBreedsFor(null)).toEqual(['border_collie']);
    expect(lockedBreedsFor('challenge', ['border_collie'])).toEqual(['mutt', 'border_collie']);
    expect(isBreedLocked('mutt')).toBe(false);
    expect(completeChoice({ ...dog, plan: 'challenge', breed: 'border_collie', origin: 'bought', age_stage: 'young' })).toEqual({
      species: 'dog',
      breed: 'border_collie',
      origin: 'bought',
      age_stage: 'young',
      plan: 'challenge',
    });
    // A stale breed from a previous challenge choice is never sent with the free plan.
    expect(completeChoice({ ...dog, plan: 'free', breed: 'border_collie', origin: 'bought', age_stage: 'young' })).toEqual({
      species: 'dog',
      breed: 'mutt',
      origin: 'bought',
      age_stage: 'young',
      plan: 'free',
    });
    // The server refused the collie for this choice → not complete.
    expect(
      completeChoice({ ...dog, plan: 'challenge', breed: 'border_collie', origin: 'bought', age_stage: 'young' }, ['border_collie']),
    ).toBeNull();
  });
});

describe('profile texts per language (M1-18)', () => {
  afterEach(async () => {
    await i18n.changeLanguage('sl');
  });

  it('Slovenian plurals follow CLDR (101 one, 102 two, 103 few)', () => {
    expect(t('pet:profile.months', { count: 101 })).toBe('101 mesec');
    expect(t('pet:profile.months', { count: 102 })).toBe('102 meseca');
    expect(t('pet:profile.months', { count: 103 })).toBe('103 mesece');
    expect(t('pet:profile.months', { count: 105 })).toBe('105 mesecev');
  });

  it('reads the profile in English', async () => {
    await i18n.changeLanguage('en');
    const info = readPetProfile(makePetProfile({ age_months: 3 }))!;
    expect(stageLine(info)).toBe('Puppy · 3 months');
    expect(originLine(info)).toBe('Bought from a breeder');
    expect(nextStageLine(info)).toBe('Becomes a young dog on 24 Nov 2026');
    expect(nextStageLine(info, 'child')).toBe('Grows into a young dog on 24 Nov 2026');
    expect(mealsLine(info)).toBe('4 meals today — a parent gives 1 during quiet hours');
    expect(formatDogAge(1)).toBe('1 month');
    expect(formatDogAge(26)).toBe('2 years and 2 months');
    expect(formatDogAge(36)).toBe('3 years');
    expect(formatProfileDate('2026-01-05')).toBe('5 Jan 2026');
  });
});
