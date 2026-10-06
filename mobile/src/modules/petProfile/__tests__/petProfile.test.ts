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
import { completeChoice, INITIAL_PICKER_CHOICE, isBreedLocked } from '@/modules/petProfile/picker';
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
  it('is complete only with origin and age, and never for a locked breed', () => {
    expect(completeChoice(INITIAL_PICKER_CHOICE)).toBeNull();
    expect(completeChoice({ breed: 'mutt', origin: 'bought', age_stage: null })).toBeNull();
    expect(completeChoice({ breed: 'mutt', origin: null, age_stage: 'puppy' })).toBeNull();
    expect(completeChoice({ breed: 'border_collie', origin: 'bought', age_stage: 'puppy' })).toBeNull();
    expect(completeChoice({ breed: 'mutt', origin: 'adopted', age_stage: 'senior' })).toEqual({
      breed: 'mutt',
      origin: 'adopted',
      age_stage: 'senior',
    });
  });

  it('premium breeds are locked by default; an unlocked list lets them through', () => {
    expect(isBreedLocked('border_collie')).toBe(true);
    expect(isBreedLocked('mutt')).toBe(false);
    expect(completeChoice({ breed: 'border_collie', origin: 'bought', age_stage: 'young' }, [])).toEqual({
      breed: 'border_collie',
      origin: 'bought',
      age_stage: 'young',
    });
  });
});
