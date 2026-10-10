import { PICKER_AGES, PICKER_STRINGS } from '@/modules/petProfile/picker';
import type { LifeStage, PetBreed } from '@/api/client';
import { i18n } from '@/i18n';

/**
 * The age hints quote numbers to the parent, so they are recomputed here from the rules
 * David confirmed on 2026-10-05 (PRODUCT_SPEC §4 / §5, PR #38):
 * steps = minutes × 100; puppy / young 10 min × age in months up to the adult goal;
 * adult mutt 60 min, Border Collie 120 min, Labrador 90 min (M5-R10, S59), Golden Retriever
 * 120 min (M5-R10-02, S65 / S68); senior 75 % of adult in whole minutes (Labrador 67.5 → 68,
 * BreedStageParamsSeeder::labradorProfile; Golden 90, goldenProfile); French Bulldog 60 min
 * (M5-R10-03, S78 / S81), senior 45 (frenchBulldogProfile); German Shepherd 120 min (M5-R10-04,
 * S97 / S100), senior 90 (germanShepherdProfile); Cavalier King Charles Spaniel 60 min
 * (M5-R10-05, S105 / S108), senior 45 (cavalierProfile); Beagle 60 min (M5-R10-06, S113),
 * senior 45 (beagleProfile); Standard Poodle 60 min (M5-R10-07, S120), senior 45 (standardPoodleProfile);
 * Dachshund 60 min (M5-R10-08, S125), senior 45 (dachshundProfile); Australian Shepherd 120 min
 * (M5-R10-09, S133 / S134), senior 90 (australianShepherdProfile); Havanese 30 min (M5-R10-10,
 * S138 / S140), senior 23 (havaneseProfile); West Highland White Terrier
 * 60 min (M5-R10-11, S144 / S147), senior 45 (westHighlandWhiteTerrierProfile); Bernese Mountain Dog
 * 60 min (M5-R10-12, S152 / S154), senior 45 (berneseMountainDogProfile); Siberian Husky 120 min
 * (M5-R10-13, S160 / S162), senior 90 (siberianHuskyProfile);
 * arrival puppy 2, young 9 months.
 */
type DogBreed = Extract<PetBreed, 'mutt' | 'border_collie' | 'labrador_retriever' | 'golden_retriever' | 'french_bulldog' | 'german_shepherd' | 'cavalier_king_charles_spaniel' | 'beagle' | 'standard_poodle' | 'dachshund' | 'australian_shepherd' | 'havanese' | 'west_highland_white_terrier' | 'bernese_mountain_dog' | 'siberian_husky'>;
const DOG_BREEDS: readonly DogBreed[] = ['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky'];
const ADULT_MINUTES: Record<DogBreed, number> = {
  mutt: 60,
  border_collie: 120,
  labrador_retriever: 90,
  golden_retriever: 120,
  french_bulldog: 60,
  german_shepherd: 120,
  cavalier_king_charles_spaniel: 60,
  beagle: 60,
  standard_poodle: 60,
  dachshund: 60,
  australian_shepherd: 120,
  havanese: 30,
  west_highland_white_terrier: 60,
  bernese_mountain_dog: 60,
  siberian_husky: 120,
};
const ARRIVAL_MONTHS: Partial<Record<LifeStage, number>> = { puppy: 2, young: 9 };

const fmt = (n: number) => n.toLocaleString('de-DE'); // 6000 → "6.000" (Slovenian thousands separator)

function expectedSteps(breed: DogBreed, stage: LifeStage): { start: number; adult: number } {
  const adult = ADULT_MINUTES[breed] * 100;
  if (stage === 'adult') return { start: adult, adult };
  if (stage === 'senior') return { start: Math.round(ADULT_MINUTES[breed] * 0.75) * 100, adult };
  const months = ARRIVAL_MONTHS[stage] ?? 0;
  return { start: Math.min(10 * months * 100, adult), adult };
}

describe('PICKER_STRINGS.ageHints', () => {
  it.each(DOG_BREEDS.flatMap((breed) => PICKER_AGES.map((age) => [breed, age] as const)))(
    '%s / %s quotes the confirmed step goal',
    (breed, age) => {
      const hint = PICKER_STRINGS.ageHints[breed][age];
      const { start, adult } = expectedSteps(breed, age);
      expect(hint).toContain(`${fmt(start)} korakov`);
      if (start < adult && age !== 'senior') {
        // Rises weekly (1 week = 1 month) up to the adult goal.
        expect(hint).toContain(`do ${fmt(adult)}`);
      }
    },
  );

  it('meals: puppy 4 → 3 → 2, every other stage 2', () => {
    for (const breed of DOG_BREEDS) {
      expect(PICKER_STRINGS.ageHints[breed].puppy).toMatch(/4 obroki na dan \(nato 3, od 6\. meseca 2\)/);
      for (const age of ['young', 'adult', 'senior'] as const) {
        expect(PICKER_STRINGS.ageHints[breed][age]).toMatch(/^2 obroka na dan/);
      }
    }
  });

  it('exact values David confirmed', () => {
    expect(PICKER_STRINGS.ageHints.mutt.young).toContain('6.000');
    expect(PICKER_STRINGS.ageHints.mutt.senior).toContain('4.500');
    expect(PICKER_STRINGS.ageHints.border_collie.young).toMatch(/9\.000.*12\.000 pri 12 mesecih/);
    expect(PICKER_STRINGS.ageHints.border_collie.senior).toContain('9.000');
  });

  it('M5-R10 Labrador: 2.000 → 9.000 (reached at 9 months), young / adult 9.000, senior 6.800', () => {
    const L = PICKER_STRINGS.ageHints.labrador_retriever;
    expect(L.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 9\.000 pri 9 mesecih/);
    expect(L.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan (kot odrasel pes).');
    expect(L.adult).toBe('2 obroka na dan; sprehod 9.000 korakov na dan.');
    expect(L.senior).toBe('2 obroka na dan; krajši sprehod — 6.800 korakov na dan.');
  });

  it('M5-R10 Labrador hints in English', async () => {
    await i18n.changeLanguage('en');
    try {
      const L = PICKER_STRINGS.ageHints.labrador_retriever;
      expect(L.puppy).toContain('2,000 steps a day, more each week up to 9,000 at 9 months');
      expect(L.young).toContain('9,000 steps');
      expect(L.adult).toContain('9,000 steps');
      expect(L.senior).toContain('6,800 steps');
      expect(PICKER_STRINGS.breedHints.labrador_retriever).toBe('Part of the 12-week challenge.');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-02 Golden Retriever: 2.000 → 12.000 (reached at 12 months), young 9.000 → 12.000, adult 12.000, senior 9.000', () => {
    const G = PICKER_STRINGS.ageHints.golden_retriever;
    expect(G.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 12\.000 pri 12 mesecih/);
    expect(G.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(G.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(G.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.golden_retriever).toBe('Del 12-tedenskega izziva.');
  });

  it('M5-R10-02 Golden Retriever hints in English', async () => {
    await i18n.changeLanguage('en');
    try {
      const G = PICKER_STRINGS.ageHints.golden_retriever;
      expect(G.puppy).toContain('2,000 steps a day, more each week up to 12,000 at 12 months');
      expect(G.young).toContain('9,000 steps a day, more each week up to 12,000 at 12 months');
      expect(G.adult).toContain('12,000 steps');
      expect(G.senior).toContain('9,000 steps');
      expect(PICKER_STRINGS.breedHints.golden_retriever).toBe('Part of the 12-week challenge.');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-03 French Bulldog: 2.000 → 6.000 (reached at 6 months), young / adult 6.000, senior 4.500', () => {
    const F = PICKER_STRINGS.ageHints.french_bulldog;
    expect(F.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 6\.000 pri 6 mesecih/);
    expect(F.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(F.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(F.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.french_bulldog).toBe('Del 12-tedenskega izziva.');
  });

  it('M5-R10-03 French Bulldog hints in English', async () => {
    await i18n.changeLanguage('en');
    try {
      const F = PICKER_STRINGS.ageHints.french_bulldog;
      expect(F.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(F.young).toContain('6,000 steps');
      expect(F.adult).toContain('6,000 steps');
      expect(F.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.french_bulldog).toBe('Part of the 12-week challenge.');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-05 Cavalier: 2.000 → 6.000 (reached at 6 months), adult 6.000, senior 4.500 — SL and EN', async () => {
    const C = PICKER_STRINGS.ageHints.cavalier_king_charles_spaniel;
    expect(C.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 6\.000 pri 6 mesecih/);
    expect(C.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(C.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.cavalier_king_charles_spaniel).toBe('Del 12-tedenskega izziva.');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.cavalier_king_charles_spaniel;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.adult).toContain('6,000 steps');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.cavalier_king_charles_spaniel).toBe('Part of the 12-week challenge.');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-07 Standard Poodle: 2.000 → 6.000 (reached at 6 months), adult 6.000, senior 4.500 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.standard_poodle;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 6\.000 pri 6 mesecih/);
    expect(P.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.standard_poodle).toBe('Del 12-tedenskega izziva.');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.standard_poodle;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.adult).toContain('6,000 steps');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.standard_poodle).toBe('Part of the 12-week challenge.');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-13 Siberian Husky: 2.000 → 12.000 (reached at 12 months), young 9.000 → 12.000, adult 12.000, senior 9.000 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.siberian_husky;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 12\.000 pri 12 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(P.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.siberian_husky).toBe('Del 12-tedenskega izziva.');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.siberian_husky;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 12,000 at 12 months');
      expect(E.adult).toContain('12,000 steps');
      expect(E.senior).toContain('9,000 steps');
      expect(PICKER_STRINGS.breedHints.siberian_husky).toBe('Part of the 12-week challenge.');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-12 Bernese Mountain Dog: 2.000 → 6.000 (reached at 6 months), young 6.000, adult 6.000, senior 4.500 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.bernese_mountain_dog;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 6\.000 pri 6 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(P.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.bernese_mountain_dog).toBe('Del 12-tedenskega izziva.');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.bernese_mountain_dog;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.adult).toContain('6,000 steps');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.bernese_mountain_dog).toBe('Part of the 12-week challenge.');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-11 West Highland White Terrier: 2.000 → 6.000 (reached at 6 months), young 6.000, adult 6.000, senior 4.500 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.west_highland_white_terrier;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 6\.000 pri 6 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(P.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.west_highland_white_terrier).toBe('Del 12-tedenskega izziva.');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.west_highland_white_terrier;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.adult).toContain('6,000 steps');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.west_highland_white_terrier).toBe('Part of the 12-week challenge.');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-10 Havanese: 2.000 → 3.000 (reached at 3 months), young 3.000, adult 3.000, senior 2.300 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.havanese;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 3\.000 pri 3 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 3.000 korakov na dan (kot odrasel pes).');
    expect(P.adult).toBe('2 obroka na dan; sprehod 3.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 2.300 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.havanese).toBe('Del 12-tedenskega izziva.');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.havanese;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 3,000 at 3 months');
      expect(E.adult).toContain('3,000 steps');
      expect(E.senior).toContain('2,300 steps');
      expect(PICKER_STRINGS.breedHints.havanese).toBe('Part of the 12-week challenge.');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-09 Australian Shepherd: 2.000 → 12.000 (reached at 12 months), young 9.000, adult 12.000, senior 9.000 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.australian_shepherd;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 12\.000 pri 12 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(P.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.australian_shepherd).toBe('Del 12-tedenskega izziva.');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.australian_shepherd;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 12,000 at 12 months');
      expect(E.adult).toContain('12,000 steps');
      expect(E.senior).toContain('9,000 steps');
      expect(PICKER_STRINGS.breedHints.australian_shepherd).toBe('Part of the 12-week challenge.');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-08 Dachshund: 2.000 → 6.000 (reached at 6 months), adult 6.000, senior 4.500 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.dachshund;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 6\.000 pri 6 mesecih/);
    expect(P.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.dachshund).toBe('Del 12-tedenskega izziva.');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.dachshund;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.adult).toContain('6,000 steps');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.dachshund).toBe('Part of the 12-week challenge.');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-06 Beagle: 2.000 → 6.000 (reached at 6 months), adult 6.000, senior 4.500 — SL and EN', async () => {
    const B = PICKER_STRINGS.ageHints.beagle;
    expect(B.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 6\.000 pri 6 mesecih/);
    expect(B.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(B.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.beagle).toBe('Del 12-tedenskega izziva.');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.beagle;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.adult).toContain('6,000 steps');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.beagle).toBe('Part of the 12-week challenge.');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-04 German Shepherd: 2.000 → 12.000 (reached at 12 months), adult 12.000, senior 9.000 — SL and EN', async () => {
    const G = PICKER_STRINGS.ageHints.german_shepherd;
    expect(G.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 12\.000 pri 12 mesecih/);
    expect(G.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(G.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.german_shepherd).toBe('Del 12-tedenskega izziva.');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.german_shepherd;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 12,000 at 12 months');
      expect(E.adult).toContain('12,000 steps');
      expect(E.senior).toContain('9,000 steps');
      expect(PICKER_STRINGS.breedHints.german_shepherd).toBe('Part of the 12-week challenge.');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('no picker note names one paid breed as the only one (two paid dogs since M5-R10)', () => {
    for (const text of [PICKER_STRINGS.breedFreeNote, PICKER_STRINGS.breedChallengeNote, PICKER_STRINGS.searchPlaceholder, PICKER_STRINGS.plans.challenge.hint]) {
      expect(text).not.toMatch(/collie|koli|labrador|golden|zlati|bulldog|buldog/i);
    }
  });
});
