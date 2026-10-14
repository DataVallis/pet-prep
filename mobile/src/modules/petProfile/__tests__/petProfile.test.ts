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
  FALLBACK_CATALOGUE,
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
  /** Today's dogs (the catalogue's dog breeds) — passed explicitly, the helpers have no default list. */
  const D = FALLBACK_CATALOGUE.breeds;

  it('is complete only with species, plan, origin and age (M3-09: no default plan)', () => {
    expect(completeChoice(INITIAL_PICKER_CHOICE, [], D)).toBeNull();
    expect(completeChoice({ ...dog, plan: null, breed: 'mutt', origin: 'adopted', age_stage: 'senior' }, [], D)).toBeNull();
    expect(completeChoice({ ...dog, plan: 'challenge', breed: 'border_collie', origin: 'bought', age_stage: null }, [], D)).toBeNull();
    expect(completeChoice({ ...dog, plan: 'challenge', breed: 'border_collie', origin: null, age_stage: 'puppy' }, [], D)).toBeNull();
    // M5-R06-02: no species yet → never complete.
    expect(completeChoice({ species: null, plan: 'free', breed: 'mutt', origin: 'adopted', age_stage: 'senior' }, [], D)).toBeNull();
    expect(completeChoice({ ...dog, plan: 'free', breed: 'mutt', origin: 'adopted', age_stage: 'senior' }, [], D)).toEqual({
      species: 'dog',
      breed: 'mutt',
      origin: 'adopted',
      age_stage: 'senior',
      plan: 'free',
    });
    // An empty breed list (nothing to choose) is never complete.
    expect(completeChoice({ ...dog, plan: 'free', breed: 'mutt', origin: 'adopted', age_stage: 'senior' }, [], [])).toBeNull();
  });

  it('M5-F03: the challenge needs a paid breed — the mutt is locked while it is chosen', () => {
    expect(lockedBreedsFor('challenge', [], D)).toEqual(['mutt']);
    expect(isBreedLocked('mutt', lockedBreedsFor('challenge', [], D))).toBe(true);
    // Challenge + mutt is never a complete choice (the server would answer 422).
    expect(completeChoice({ ...dog, plan: 'challenge', breed: 'mutt', origin: 'adopted', age_stage: 'senior' }, [], D)).toBeNull();
    // Why a breed is locked (note + a11y label).
    expect(breedLockReason('mutt', 'challenge', [], D)).toBe('free_only');
    expect(breedLockReason('border_collie', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('border_collie', null, [], D)).toBe('challenge_only');
    expect(breedLockReason('border_collie', 'challenge', ['border_collie'], D)).toBe('server');
    expect(breedLockReason('mutt', 'free', [], D)).toBeNull();
    expect(breedLockReason('border_collie', 'challenge', [], D)).toBeNull();
  });

  it('M5-F03: switching plans never leaves an invalid breed selected', () => {
    const muttFree = { ...dog, plan: 'free' as const, breed: 'mutt' as const, origin: 'bought' as const, age_stage: 'puppy' as const };
    // free (mutt) → challenge: moves to the first paid breed.
    const challenge = choiceWithPlan(muttFree, 'challenge', [], D);
    expect(challenge).toEqual({ ...muttFree, plan: 'challenge', breed: 'border_collie' });
    expect(completeChoice(challenge, [], D)).toEqual({ species: 'dog', breed: 'border_collie', origin: 'bought', age_stage: 'puppy', plan: 'challenge' });
    // challenge → free: always the species' free breed (the mutt).
    expect(choiceWithPlan(challenge, 'free', [], D)).toEqual(muttFree);
    // No plan yet (initial choice) → challenge: the collie.
    expect(choiceWithPlan(INITIAL_PICKER_CHOICE, 'challenge', [], D).breed).toBe('border_collie');
    // A pickable breed is kept.
    expect(choiceWithPlan(challenge, 'challenge', [], D)).toEqual(challenge);
    // M5-R10: the server refused the collie → the next paid breed (the Labrador).
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie'], D).breed).toBe('labrador_retriever');
    // M5-R10-02: collie and Labrador refused → the Golden Retriever.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever'], D).breed).toBe('golden_retriever');
    // M5-R10-03: collie, Labrador and Golden refused → the French Bulldog.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever'], D).breed).toBe('french_bulldog');
    // M5-R10-04: … and the French Bulldog refused → the German Shepherd.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog'], D).breed).toBe('german_shepherd');
    // M5-R10-05: … and the German Shepherd refused → the Cavalier.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd'], D).breed).toBe('cavalier_king_charles_spaniel');
    // M5-R10-06: … and the Cavalier refused → the Beagle.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel'], D).breed).toBe('beagle');
    // M5-R10-07: … and the Beagle refused → the Standard Poodle.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle'], D).breed).toBe('standard_poodle');
    // M5-R10-08: … and the Standard Poodle refused → the Dachshund.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle'], D).breed).toBe('dachshund');
    // M5-R10-09: … and the Dachshund refused → the Australian Shepherd.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund'], D).breed).toBe('australian_shepherd');
    // Every paid breed refused by the server: nothing pickable → the choice stays incomplete.
    // M5-R10-10: … and the Australian Shepherd refused → the Havanese.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd'], D).breed).toBe('havanese');
    // M5-R10-11: … and the Havanese refused → the West Highland White Terrier.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese'], D).breed).toBe('west_highland_white_terrier');
    // M5-R10-12: … and the West Highland White Terrier refused → the Bernese Mountain Dog.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier'], D).breed).toBe('bernese_mountain_dog');
    // M5-R10-13: … and the Bernese Mountain Dog refused → the Siberian Husky.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog'], D).breed).toBe('siberian_husky');
    // M5-R10-19: … and the Siberian Husky refused → the Rottweiler.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky'], D).breed).toBe('rottweiler');
    // M5-R10-20: … and the Rottweiler refused → the Bulldog.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler'], D).breed).toBe('bulldog');
    // M5-R10-21: … and the Bulldog refused → the German Shorthaired Pointer.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog'], D).breed).toBe('german_shorthaired_pointer');
    // M5-R10-22: … and the German Shorthaired Pointer refused → the Yorkshire Terrier.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer'], D).breed).toBe('yorkshire_terrier');
    // M5-R10-23: … and the Yorkshire Terrier refused → the Cane Corso.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier'], D).breed).toBe('cane_corso');
    // M5-R10-24: … and the Cane Corso refused → the Pembroke Welsh Corgi.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso'], D).breed).toBe('pembroke_welsh_corgi');
    // M5-R10-25: … and the Pembroke Welsh Corgi refused → the Dobermann.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi'], D).breed).toBe('dobermann');
    // M5-R10-26: … and the Dobermann refused → the Boxer.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann'], D).breed).toBe('boxer');
    // M5-R10-27: … and the Boxer refused → the Miniature Schnauzer.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer'], D).breed).toBe('miniature_schnauzer');
    // M5-R10-28: … and the Miniature Schnauzer refused → the Shih Tzu.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer'], D).breed).toBe('shih_tzu');
    // M5-R10-29: … and the Shih Tzu refused → the Great Dane.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu'], D).breed).toBe('great_dane');
    // M5-R10-30: … and the Great Dane refused → the Pomeranian.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane'], D).breed).toBe('pomeranian');
    // M5-R10-31: … and the Pomeranian refused → the Boston Terrier.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian'], D).breed).toBe('boston_terrier');
    // M5-R10-32: … and the Boston Terrier refused → the Miniature American Shepherd.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier'], D).breed).toBe('miniature_american_shepherd');
    // M5-R10-33: … and the Miniature American Shepherd refused → the Chihuahua.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd'], D).breed).toBe('chihuahua');
    // M5-R10-34: … and the Chihuahua refused → the English Springer Spaniel.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua'], D).breed).toBe('english_springer_spaniel');
    // M5-R10-35: … and the English Springer Spaniel refused → the Shetland Sheepdog.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel'], D).breed).toBe('shetland_sheepdog');
    // M5-R10-36: … and the Shetland Sheepdog refused → the Brittany.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog'], D).breed).toBe('brittany');
    // M5-R10-37: … and the Brittany refused → the American Cocker Spaniel.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany'], D).breed).toBe('american_cocker_spaniel');
    // M5-R10-38: … and the American Cocker Spaniel refused → the Belgian Malinois.
    expect(choiceWithPlan(muttFree, 'challenge', ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel'], D).breed).toBe('belgian_malinois');
    const refused = ['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel', 'belgian_malinois'] as const;
    const stuck = choiceWithPlan(muttFree, 'challenge', refused, D);
    expect(stuck.plan).toBe('challenge');
    expect(completeChoice(stuck, refused, D)).toBeNull();
  });

  it('premium breeds need the challenge plan; the free plan is always the free breed', () => {
    expect(lockedBreedsFor('free', [], D)).toEqual(['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel', 'belgian_malinois']);
    expect(lockedBreedsFor(null, [], D)).toEqual(['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel', 'belgian_malinois']);
    expect(lockedBreedsFor('challenge', ['border_collie'], D)).toEqual(['mutt', 'border_collie']);
    expect(breedLockReason('labrador_retriever', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('labrador_retriever', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('golden_retriever', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('golden_retriever', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('french_bulldog', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('french_bulldog', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('german_shepherd', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('german_shepherd', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('cavalier_king_charles_spaniel', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('cavalier_king_charles_spaniel', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('beagle', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('beagle', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('standard_poodle', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('standard_poodle', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('dachshund', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('dachshund', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('australian_shepherd', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('australian_shepherd', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('havanese', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('havanese', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('west_highland_white_terrier', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('west_highland_white_terrier', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('bernese_mountain_dog', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('bernese_mountain_dog', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('siberian_husky', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('siberian_husky', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('rottweiler', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('rottweiler', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('bulldog', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('bulldog', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('german_shorthaired_pointer', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('german_shorthaired_pointer', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('yorkshire_terrier', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('yorkshire_terrier', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('cane_corso', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('cane_corso', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('pembroke_welsh_corgi', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('pembroke_welsh_corgi', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('dobermann', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('dobermann', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('boxer', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('boxer', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('miniature_schnauzer', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('miniature_schnauzer', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('shih_tzu', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('shih_tzu', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('great_dane', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('great_dane', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('pomeranian', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('pomeranian', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('boston_terrier', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('boston_terrier', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('miniature_american_shepherd', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('miniature_american_shepherd', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('chihuahua', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('chihuahua', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('english_springer_spaniel', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('english_springer_spaniel', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('shetland_sheepdog', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('shetland_sheepdog', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('brittany', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('brittany', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('american_cocker_spaniel', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('american_cocker_spaniel', 'challenge', [], D)).toBeNull();
    expect(breedLockReason('belgian_malinois', 'free', [], D)).toBe('challenge_only');
    expect(breedLockReason('belgian_malinois', 'challenge', [], D)).toBeNull();
    expect(isBreedLocked('mutt', lockedBreedsFor(null, [], D))).toBe(false);
    expect(isBreedLocked(null, [])).toBe(true);
    expect(completeChoice({ ...dog, plan: 'challenge', breed: 'border_collie', origin: 'bought', age_stage: 'young' }, [], D)).toEqual({
      species: 'dog',
      breed: 'border_collie',
      origin: 'bought',
      age_stage: 'young',
      plan: 'challenge',
    });
    // A stale breed from a previous challenge choice is never sent with the free plan.
    // (The mutt is adoption only — David 2026-10-12.)
    expect(completeChoice({ ...dog, plan: 'free', breed: 'border_collie', origin: 'adopted', age_stage: 'young' }, [], D)).toEqual({
      species: 'dog',
      breed: 'mutt',
      origin: 'adopted',
      age_stage: 'young',
      plan: 'free',
    });
    // The server refused the collie for this choice → not complete.
    expect(
      completeChoice({ ...dog, plan: 'challenge', breed: 'border_collie', origin: 'bought', age_stage: 'young' }, ['border_collie'], D),
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
