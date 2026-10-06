import { PICKER_AGES, PICKER_BREEDS, PICKER_STRINGS } from '@/modules/petProfile/picker';
import type { LifeStage, PetBreed } from '@/api/client';

/**
 * The age hints quote numbers to the parent, so they are recomputed here from the rules
 * David confirmed on 2026-10-05 (PRODUCT_SPEC §4 / §5, PR #38):
 * steps = minutes × 100; puppy / young 10 min × age in months up to the adult goal;
 * adult mutt 60 min, Border Collie 120 min; senior 75 % of adult; arrival puppy 2, young 9 months.
 */
const ADULT_MINUTES: Record<PetBreed, number> = { mutt: 60, border_collie: 120 };
const ARRIVAL_MONTHS: Partial<Record<LifeStage, number>> = { puppy: 2, young: 9 };

const fmt = (n: number) => n.toLocaleString('de-DE'); // 6000 → "6.000" (Slovenian thousands separator)

function expectedSteps(breed: PetBreed, stage: LifeStage): { start: number; adult: number } {
  const adult = ADULT_MINUTES[breed] * 100;
  if (stage === 'adult') return { start: adult, adult };
  if (stage === 'senior') return { start: adult * 0.75, adult };
  const months = ARRIVAL_MONTHS[stage] ?? 0;
  return { start: Math.min(10 * months * 100, adult), adult };
}

describe('PICKER_STRINGS.ageHints', () => {
  it.each(PICKER_BREEDS.flatMap((breed) => PICKER_AGES.map((age) => [breed, age] as const)))(
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
    for (const breed of PICKER_BREEDS) {
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
});
