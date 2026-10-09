import { PICKER_AGES, PICKER_STRINGS } from '@/modules/petProfile/picker';
import type { LifeStage, PetBreed } from '@/api/client';
import { i18n } from '@/i18n';

/**
 * The age hints quote numbers to the parent, so they are recomputed here from the rules
 * David confirmed on 2026-10-05 (PRODUCT_SPEC §4 / §5, PR #38):
 * steps = minutes × 100; puppy / young 10 min × age in months up to the adult goal;
 * adult mutt 60 min, Border Collie 120 min, Labrador 90 min (M5-R10, S59); senior 75 % of
 * adult in whole minutes (Labrador 67.5 → 68, BreedStageParamsSeeder::labradorProfile);
 * arrival puppy 2, young 9 months.
 */
type DogBreed = Extract<PetBreed, 'mutt' | 'border_collie' | 'labrador_retriever'>;
const DOG_BREEDS: readonly DogBreed[] = ['mutt', 'border_collie', 'labrador_retriever'];
const ADULT_MINUTES: Record<DogBreed, number> = { mutt: 60, border_collie: 120, labrador_retriever: 90 };
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

  it('no picker note names one paid breed as the only one (two paid dogs since M5-R10)', () => {
    for (const text of [PICKER_STRINGS.breedFreeNote, PICKER_STRINGS.breedChallengeNote, PICKER_STRINGS.searchPlaceholder, PICKER_STRINGS.plans.challenge.hint]) {
      expect(text).not.toMatch(/collie|koli|labrador/i);
    }
  });
});
