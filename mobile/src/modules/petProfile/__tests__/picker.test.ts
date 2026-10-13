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
 * (M5-R10-13, S160 / S162), senior 90 (siberianHuskyProfile); Rottweiler 120 min
 * (M5-R10-19, S168 / S169), senior 90 (rottweilerProfile); Bulldog 60 min
 * (M5-R10-20, S177 / S180), senior 45 (bulldogProfile); German Shorthaired Pointer 120 min
 * (M5-R10-21, S187 / S188), senior 90 (germanShorthairedPointerProfile); Yorkshire Terrier 30 min
 * (M5-R10-22, S192 / S194), senior 23 (yorkshireTerrierProfile); Cane Corso 120 min
 * (M5-R10-23, S199), senior 90 (caneCorsoProfile); Pembroke Welsh Corgi 60 min
 * (M5-R10-24, S204 / S206), senior 45 (pembrokeWelshCorgiProfile); Dobermann 120 min
 * (M5-R10-25, S209 / S211), senior 90 (dobermannProfile); Boxer 120 min
 * (M5-R10-26, S215 / S219), senior 90 (boxerProfile); Miniature Schnauzer 60 min
 * (M5-R10-27, S223 / S226), senior 45 (miniatureSchnauzerProfile); Shih Tzu 60 min
 * (M5-R10-28, S231 / S233), senior 45 (shihTzuProfile);
 * Great Dane 120 min (M5-R10-29, S241 / S243), senior 90 (greatDaneProfile);
 * Pomeranian 30 min (M5-R10-30, S247 / S250), senior 23 (pomeranianProfile);
 * Boston Terrier 60 min (M5-R10-31, S255 / S259), senior 45 (bostonTerrierProfile);
 * arrival puppy 2, young 9 months.
 */
type DogBreed = Extract<PetBreed, 'mutt' | 'border_collie' | 'labrador_retriever' | 'golden_retriever' | 'french_bulldog' | 'german_shepherd' | 'cavalier_king_charles_spaniel' | 'beagle' | 'standard_poodle' | 'dachshund' | 'australian_shepherd' | 'havanese' | 'west_highland_white_terrier' | 'bernese_mountain_dog' | 'siberian_husky' | 'rottweiler' | 'bulldog' | 'german_shorthaired_pointer' | 'yorkshire_terrier' | 'cane_corso' | 'pembroke_welsh_corgi' | 'dobermann' | 'boxer' | 'miniature_schnauzer' | 'shih_tzu' | 'great_dane' | 'pomeranian' | 'boston_terrier'>;
const DOG_BREEDS: readonly DogBreed[] = ['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier'];
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
  rottweiler: 120,
  bulldog: 60,
  german_shorthaired_pointer: 120,
  yorkshire_terrier: 30,
  cane_corso: 120,
  pembroke_welsh_corgi: 60,
  dobermann: 120,
  boxer: 120,
  miniature_schnauzer: 60,
  shih_tzu: 60,
  great_dane: 120,
  pomeranian: 30,
  boston_terrier: 60,
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
      expect(PICKER_STRINGS.breedHints.labrador_retriever).toBe('Part of the 12-week challenge · brushing 1× a week');
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
    expect(PICKER_STRINGS.breedHints.golden_retriever).toBe('Del 12-tedenskega izziva · česanje 3× na teden');
  });

  it('M5-R10-02 Golden Retriever hints in English', async () => {
    await i18n.changeLanguage('en');
    try {
      const G = PICKER_STRINGS.ageHints.golden_retriever;
      expect(G.puppy).toContain('2,000 steps a day, more each week up to 12,000 at 12 months');
      expect(G.young).toContain('9,000 steps a day, more each week up to 12,000 at 12 months');
      expect(G.adult).toContain('12,000 steps');
      expect(G.senior).toContain('9,000 steps');
      expect(PICKER_STRINGS.breedHints.golden_retriever).toBe('Part of the 12-week challenge · brushing 3× a week');
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
    expect(PICKER_STRINGS.breedHints.french_bulldog).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
  });

  it('M5-R10-03 French Bulldog hints in English', async () => {
    await i18n.changeLanguage('en');
    try {
      const F = PICKER_STRINGS.ageHints.french_bulldog;
      expect(F.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(F.young).toContain('6,000 steps');
      expect(F.adult).toContain('6,000 steps');
      expect(F.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.french_bulldog).toBe('Part of the 12-week challenge · brushing 1× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-05 Cavalier: 2.000 → 6.000 (reached at 6 months), adult 6.000, senior 4.500 — SL and EN', async () => {
    const C = PICKER_STRINGS.ageHints.cavalier_king_charles_spaniel;
    expect(C.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 6\.000 pri 6 mesecih/);
    expect(C.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(C.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.cavalier_king_charles_spaniel).toBe('Del 12-tedenskega izziva · česanje vsak dan');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.cavalier_king_charles_spaniel;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.adult).toContain('6,000 steps');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.cavalier_king_charles_spaniel).toBe('Part of the 12-week challenge · brushing every day');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-07 Standard Poodle: 2.000 → 6.000 (reached at 6 months), adult 6.000, senior 4.500 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.standard_poodle;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 6\.000 pri 6 mesecih/);
    expect(P.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.standard_poodle).toBe('Del 12-tedenskega izziva · česanje vsak dan');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.standard_poodle;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.adult).toContain('6,000 steps');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.standard_poodle).toBe('Part of the 12-week challenge · brushing every day');
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
    expect(PICKER_STRINGS.breedHints.siberian_husky).toBe('Del 12-tedenskega izziva · česanje 2× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.siberian_husky;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 12,000 at 12 months');
      expect(E.adult).toContain('12,000 steps');
      expect(E.senior).toContain('9,000 steps');
      expect(PICKER_STRINGS.breedHints.siberian_husky).toBe('Part of the 12-week challenge · brushing 2× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-25 Dobermann: 2.000 → 12.000 (reached at 12 months), young 9.000 → 12.000, adult 12.000, senior 9.000 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.dobermann;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 12\.000 pri 12 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(P.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.dobermann).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.dobermann;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 12,000 at 12 months');
      expect(E.adult).toContain('12,000 steps');
      expect(E.senior).toContain('9,000 steps');
      expect(PICKER_STRINGS.breedHints.dobermann).toBe('Part of the 12-week challenge · brushing 1× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-26 Boxer: 2.000 → 12.000 (reached at 12 months), young 9.000 → 12.000, adult 12.000, senior 9.000 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.boxer;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 12\.000 pri 12 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(P.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.boxer).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.boxer;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 12,000 at 12 months');
      expect(E.adult).toContain('12,000 steps');
      expect(E.senior).toContain('9,000 steps');
      expect(PICKER_STRINGS.breedHints.boxer).toBe('Part of the 12-week challenge · brushing 1× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-27 Miniature Schnauzer: 2.000 → 6.000 (reached at 6 months), young / adult 6.000, senior 4.500, brushing 3× a week — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.miniature_schnauzer;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 6\.000 pri 6 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(P.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.miniature_schnauzer).toBe('Del 12-tedenskega izziva · česanje 3× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.miniature_schnauzer;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.adult).toContain('6,000 steps');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.miniature_schnauzer).toBe('Part of the 12-week challenge · brushing 3× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-28 Shih Tzu: 2.000 → 6.000 (reached at 6 months), young / adult 6.000, senior 4.500, brushing every day — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.shih_tzu;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 6\.000 pri 6 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(P.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.shih_tzu).toBe('Del 12-tedenskega izziva · česanje vsak dan');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.shih_tzu;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.adult).toContain('6,000 steps');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.shih_tzu).toBe('Part of the 12-week challenge · brushing every day');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-31 Boston Terrier: 2.000 → 6.000 (reached at 6 months), young 6.000, adult 6.000, senior 4.500, brushing 1× a week — SL and EN', async () => {
    const B = PICKER_STRINGS.ageHints.boston_terrier;
    expect(B.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 6.000 pri 6 mesecih.');
    expect(B.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(B.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(B.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.boston_terrier).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.boston_terrier;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 6,000 at 6 months.');
      expect(E.young).toBe('2 meals a day; walks of 6,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 6,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 4,500 steps a day.');
      expect(PICKER_STRINGS.breedHints.boston_terrier).toBe('Part of the 12-week challenge · brushing 1× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-30 Pomeranian: 2.000 → 3.000 (reached at 3 months), young 3.000, adult 3.000, senior 2.300, brushing 3× a week — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.pomeranian;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 3\.000 pri 3 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 3.000 korakov na dan (kot odrasel pes).');
    expect(P.adult).toBe('2 obroka na dan; sprehod 3.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 2.300 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.pomeranian).toBe('Del 12-tedenskega izziva · česanje 3× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.pomeranian;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 3,000 at 3 months');
      expect(E.adult).toContain('3,000 steps');
      expect(E.senior).toContain('2,300 steps');
      expect(PICKER_STRINGS.breedHints.pomeranian).toBe('Part of the 12-week challenge · brushing 3× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-29 Great Dane: 2.000 → 12.000 (reached at 12 months), young 9.000, adult 12.000, senior 9.000, brushing 1× a week — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.great_dane;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 12\.000 pri 12 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(P.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.great_dane).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.great_dane;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 12,000 at 12 months');
      expect(E.adult).toContain('12,000 steps');
      expect(E.senior).toContain('9,000 steps');
      expect(PICKER_STRINGS.breedHints.great_dane).toBe('Part of the 12-week challenge · brushing 1× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-24 Pembroke Welsh Corgi: 2.000 → 6.000 (reached at 6 months), young 6.000, adult 6.000, senior 4.500 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.pembroke_welsh_corgi;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 6\.000 pri 6 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(P.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.pembroke_welsh_corgi).toBe('Del 12-tedenskega izziva · česanje 3× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.pembroke_welsh_corgi;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.adult).toContain('6,000 steps');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.pembroke_welsh_corgi).toBe('Part of the 12-week challenge · brushing 3× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-23 Cane Corso: 2.000 → 12.000 (reached at 12 months), young 9.000 → 12.000, adult 12.000, senior 9.000 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.cane_corso;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 12\.000 pri 12 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(P.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.cane_corso).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.cane_corso;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 12,000 at 12 months');
      expect(E.adult).toContain('12,000 steps');
      expect(E.senior).toContain('9,000 steps');
      expect(PICKER_STRINGS.breedHints.cane_corso).toBe('Part of the 12-week challenge · brushing 1× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-19 Rottweiler: 2.000 → 12.000 (reached at 12 months), young 9.000 → 12.000, adult 12.000, senior 9.000 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.rottweiler;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 12\.000 pri 12 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(P.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.rottweiler).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.rottweiler;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 12,000 at 12 months');
      expect(E.adult).toContain('12,000 steps');
      expect(E.senior).toContain('9,000 steps');
      expect(PICKER_STRINGS.breedHints.rottweiler).toBe('Part of the 12-week challenge · brushing 1× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-21 German Shorthaired Pointer: 2.000 → 12.000 (reached at 12 months), young 9.000 → 12.000, adult 12.000, senior 9.000 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.german_shorthaired_pointer;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 12\.000 pri 12 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(P.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.german_shorthaired_pointer).toBe('Del 12-tedenskega izziva · česanje 2× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.german_shorthaired_pointer;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 12,000 at 12 months');
      expect(E.young).toBe('2 meals a day; walks of 9,000 steps a day, more each week up to 12,000 at 12 months.');
      expect(E.senior).toContain('9,000 steps');
      expect(PICKER_STRINGS.breedHints.german_shorthaired_pointer).toBe('Part of the 12-week challenge · brushing 2× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-20 Bulldog: 2.000 → 6.000 (reached at 6 months), young 6.000, adult 6.000, senior 4.500 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.bulldog;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 6\.000 pri 6 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(P.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.bulldog).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.bulldog;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.young).toBe('2 meals a day; walks of 6,000 steps a day (like an adult dog).');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.bulldog).toBe('Part of the 12-week challenge · brushing 1× a week');
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
    expect(PICKER_STRINGS.breedHints.bernese_mountain_dog).toBe('Del 12-tedenskega izziva · česanje 2× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.bernese_mountain_dog;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.adult).toContain('6,000 steps');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.bernese_mountain_dog).toBe('Part of the 12-week challenge · brushing 2× a week');
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
    expect(PICKER_STRINGS.breedHints.west_highland_white_terrier).toBe('Del 12-tedenskega izziva · česanje 2× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.west_highland_white_terrier;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.adult).toContain('6,000 steps');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.west_highland_white_terrier).toBe('Part of the 12-week challenge · brushing 2× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-22 Yorkshire Terrier: 2.000 → 3.000 (reached at 3 months), young 3.000, adult 3.000, senior 2.300 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.yorkshire_terrier;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 3\.000 pri 3 mesecih/);
    expect(P.young).toBe('2 obroka na dan; sprehod 3.000 korakov na dan (kot odrasel pes).');
    expect(P.adult).toBe('2 obroka na dan; sprehod 3.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 2.300 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.yorkshire_terrier).toBe('Del 12-tedenskega izziva · česanje vsak dan');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.yorkshire_terrier;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 3,000 at 3 months');
      expect(E.young).toBe('2 meals a day; walks of 3,000 steps a day (like an adult dog).');
      expect(E.senior).toContain('2,300 steps');
      expect(PICKER_STRINGS.breedHints.yorkshire_terrier).toBe('Part of the 12-week challenge · brushing every day');
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
    expect(PICKER_STRINGS.breedHints.havanese).toBe('Del 12-tedenskega izziva · česanje vsak dan');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.havanese;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 3,000 at 3 months');
      expect(E.adult).toContain('3,000 steps');
      expect(E.senior).toContain('2,300 steps');
      expect(PICKER_STRINGS.breedHints.havanese).toBe('Part of the 12-week challenge · brushing every day');
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
    expect(PICKER_STRINGS.breedHints.australian_shepherd).toBe('Del 12-tedenskega izziva · česanje 2× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.australian_shepherd;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 12,000 at 12 months');
      expect(E.adult).toContain('12,000 steps');
      expect(E.senior).toContain('9,000 steps');
      expect(PICKER_STRINGS.breedHints.australian_shepherd).toBe('Part of the 12-week challenge · brushing 2× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-08 Dachshund: 2.000 → 6.000 (reached at 6 months), adult 6.000, senior 4.500 — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.dachshund;
    expect(P.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 6\.000 pri 6 mesecih/);
    expect(P.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.dachshund).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.dachshund;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.adult).toContain('6,000 steps');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.dachshund).toBe('Part of the 12-week challenge · brushing 1× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-06 Beagle: 2.000 → 6.000 (reached at 6 months), adult 6.000, senior 4.500 — SL and EN', async () => {
    const B = PICKER_STRINGS.ageHints.beagle;
    expect(B.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 6\.000 pri 6 mesecih/);
    expect(B.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(B.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.beagle).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.beagle;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 6,000 at 6 months');
      expect(E.adult).toContain('6,000 steps');
      expect(E.senior).toContain('4,500 steps');
      expect(PICKER_STRINGS.breedHints.beagle).toBe('Part of the 12-week challenge · brushing 1× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-04 German Shepherd: 2.000 → 12.000 (reached at 12 months), adult 12.000, senior 9.000 — SL and EN', async () => {
    const G = PICKER_STRINGS.ageHints.german_shepherd;
    expect(G.puppy).toMatch(/^Pride star 2 meseca\..*2\.000 korakov.*do 12\.000 pri 12 mesecih/);
    expect(G.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(G.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.german_shepherd).toBe('Del 12-tedenskega izziva · česanje 2× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.german_shepherd;
      expect(E.puppy).toContain('2,000 steps a day, more each week up to 12,000 at 12 months');
      expect(E.adult).toContain('12,000 steps');
      expect(E.senior).toContain('9,000 steps');
      expect(PICKER_STRINGS.breedHints.german_shepherd).toBe('Part of the 12-week challenge · brushing 2× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-14 British Shorthair (cat): kitten at 3 months, young cat still growing until 3–5 years — SL and EN', async () => {
    const B = PICKER_STRINGS.ageHints.british_shorthair;
    expect(B.puppy).toBe('Pride kot mucek, star 3 mesece (rodovniški mucki gredo od doma pozneje).');
    expect(B.young).toBe('Pride kot mlada mačka, stara 1 leto — britanska kratkodlaka mačka raste do 3.–5. leta.');
    expect(B.adult).toBe('Pride kot zrela mačka, stara 7 let.');
    expect(B.senior).toBe('Pride kot starejša mačka, stara 10 let.');
    expect(PICKER_STRINGS.breedHints.british_shorthair).toBe('Kratka, gosta dlaka · česanje 1× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.british_shorthair;
      expect(E.puppy).toBe('Arrives as a 3-month-old kitten (pedigree kittens leave home later).');
      expect(E.young).toBe('Arrives as a young cat, 1 year old — a British Shorthair keeps growing until 3–5 years.');
      expect(E.adult).toBe('Arrives as a mature cat, 7 years old.');
      expect(E.senior).toBe('Arrives as a senior cat, 10 years old.');
      expect(PICKER_STRINGS.breedHints.british_shorthair).toBe('Short, dense coat · brushing 1× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('no cat picker note names one paid cat as the only one (two paid cats since M5-R10-14) — SL and EN', async () => {
    const notes = () => [i18n.t('pet:picker.cat.breedFreeNote'), i18n.t('pet:picker.cat.breedChallengeNote'), i18n.t('pet:picker.cat.plans.challenge.hint')];
    for (const text of notes()) expect(text).not.toMatch(/maine|coon|british|britansk|ragdoll|siberian|sibirsk|norwegian|norvešk|bengal/i);
    await i18n.changeLanguage('en');
    try {
      for (const text of notes()) expect(text).not.toMatch(/maine|coon|british|britansk|ragdoll|siberian|sibirsk|norwegian|norvešk|bengal/i);
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-15 Ragdoll (cat): kitten at 3 months, young cat still growing until about 4 years — SL and EN', async () => {
    const R = PICKER_STRINGS.ageHints.ragdoll;
    expect(R.puppy).toBe('Pride kot mucek, star 3 mesece (rodovniški mucki gredo od doma pozneje).');
    expect(R.young).toBe('Pride kot mlada mačka, stara 1 leto — ragdoll raste do približno 4. leta.');
    expect(R.adult).toBe('Pride kot zrela mačka, stara 7 let.');
    expect(R.senior).toBe('Pride kot starejša mačka, stara 10 let.');
    expect(PICKER_STRINGS.breedHints.ragdoll).toBe('Poldolga, svilnata dlaka · česanje 2× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.ragdoll;
      expect(E.puppy).toBe('Arrives as a 3-month-old kitten (pedigree kittens leave home later).');
      expect(E.young).toBe('Arrives as a young cat, 1 year old — a Ragdoll keeps growing until about 4 years.');
      expect(E.adult).toBe('Arrives as a mature cat, 7 years old.');
      expect(E.senior).toBe('Arrives as a senior cat, 10 years old.');
      expect(PICKER_STRINGS.breedHints.ragdoll).toBe('Semi-long, silky coat · brushing 2× a week');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-16 Siberian (cat): kitten at 3 months, young cat still growing until 4–5 years, brushing 1× a week — SL and EN', async () => {
    const S = PICKER_STRINGS.ageHints.siberian_cat;
    expect(S.puppy).toBe('Pride kot mucek, star 3 mesece (rodovniški mucki gredo od doma pozneje).');
    expect(S.young).toBe('Pride kot mlada mačka, stara 1 leto — sibirska mačka raste do 4.–5. leta.');
    expect(S.adult).toBe('Pride kot zrela mačka, stara 7 let.');
    expect(S.senior).toBe('Pride kot starejša mačka, stara 10 let.');
    expect(PICKER_STRINGS.breedHints.siberian_cat).toBe('Poldolga, gosta dlaka · česanje 1× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.siberian_cat;
      expect(E.puppy).toBe('Arrives as a 3-month-old kitten (pedigree kittens leave home later).');
      expect(E.young).toBe('Arrives as a young cat, 1 year old — a Siberian keeps growing until 4–5 years.');
      expect(E.adult).toBe('Arrives as a mature cat, 7 years old.');
      expect(E.senior).toBe('Arrives as a senior cat, 10 years old.');
      expect(PICKER_STRINGS.breedHints.siberian_cat).toBe('Semi-long, dense coat · brushing 1× a week');
      // Never sold as an allergy-friendly cat (PetMD C35).
      expect(JSON.stringify(E) + PICKER_STRINGS.breedHints.siberian_cat).not.toMatch(/allerg/i);
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-17 Norwegian Forest Cat (cat): kitten at 3 months, young cat still growing until 4–5 years, brushing 1× a week — SL and EN', async () => {
    const S = PICKER_STRINGS.ageHints.norwegian_forest_cat;
    expect(S.puppy).toBe('Pride kot mucek, star 3 mesece (rodovniški mucki gredo od doma pozneje).');
    expect(S.young).toBe('Pride kot mlada mačka, stara 1 leto — norveška gozdna mačka raste do 4.–5. leta.');
    expect(S.adult).toBe('Pride kot zrela mačka, stara 7 let.');
    expect(S.senior).toBe('Pride kot starejša mačka, stara 10 let.');
    expect(PICKER_STRINGS.breedHints.norwegian_forest_cat).toBe('Poldolga, vodoodbojna dlaka · česanje 1× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.norwegian_forest_cat;
      expect(E.puppy).toBe('Arrives as a 3-month-old kitten (pedigree kittens leave home later).');
      expect(E.young).toBe('Arrives as a young cat, 1 year old — a Norwegian Forest Cat keeps growing until 4–5 years.');
      expect(E.adult).toBe('Arrives as a mature cat, 7 years old.');
      expect(E.senior).toBe('Arrives as a senior cat, 10 years old.');
      expect(PICKER_STRINGS.breedHints.norwegian_forest_cat).toBe('Semi-long, water-repellent coat · brushing 1× a week');
      expect(JSON.stringify(E) + PICKER_STRINGS.breedHints.norwegian_forest_cat).not.toMatch(/allerg/i);
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-18 Bengal (cat): kitten at 3 months, young cat can grow until 2 years, brushing 1× a week — SL and EN', async () => {
    const S = PICKER_STRINGS.ageHints.bengal;
    expect(S.puppy).toBe('Pride kot mucek, star 3 mesece (rodovniški mucki gredo od doma pozneje).');
    expect(S.young).toBe('Pride kot mlada mačka, stara 1 leto — bengalska mačka lahko raste do 2. leta.');
    expect(S.adult).toBe('Pride kot zrela mačka, stara 7 let.');
    expect(S.senior).toBe('Pride kot starejša mačka, stara 10 let.');
    expect(PICKER_STRINGS.breedHints.bengal).toBe('Kratka, mehka, pegasta ali marmorirana dlaka · česanje 1× na teden');
    await i18n.changeLanguage('en');
    try {
      const E = PICKER_STRINGS.ageHints.bengal;
      expect(E.puppy).toBe('Arrives as a 3-month-old kitten (pedigree kittens leave home later).');
      expect(E.young).toBe('Arrives as a young cat, 1 year old — a Bengal can keep growing until 2 years.');
      expect(E.adult).toBe('Arrives as a mature cat, 7 years old.');
      expect(E.senior).toBe('Arrives as a senior cat, 10 years old.');
      expect(PICKER_STRINGS.breedHints.bengal).toBe('Short, soft, spotted or marbled coat · brushing 1× a week');
      // Never an allergy promise, never a wild cat.
      expect(JSON.stringify(E) + PICKER_STRINGS.breedHints.bengal).not.toMatch(/allerg|wild|leopard/i);
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
