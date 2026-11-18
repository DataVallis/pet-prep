/**
 * M5-R10-123: the Xoloitzcuintli (FCI No. 234, Group 5, Mexico) — the breed's own expectations (runbook
 * "Own files only", David 2026-10-15): picker hints EN + SL, fallback catalogue entry, search, species, chips, confirm.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import PetPickerStep from '@/components/parent/PetPickerStep';
import { i18n } from '@/i18n';
import { FALLBACK_CATALOGUE, INITIAL_PICKER_CHOICE, PICKER_STRINGS, searchBreeds, type BreedCatalogue } from '@/modules/petProfile/picker';
import { breedName, hasGroomingRoutine, isDefaultFreeBreed, readBreed, readSpecies } from '@/modules/species/species';

function renderPicker(catalogue: BreedCatalogue | null, onConfirm = jest.fn()) {
  render(<PetPickerStep childName="Maja" catalogue={catalogue} initial={INITIAL_PICKER_CHOICE} onConfirm={onConfirm} onBack={jest.fn()} previewCount={Number.MAX_SAFE_INTEGER} />);
  return { onConfirm };
}

async function inEnglish(run: () => void | Promise<void>) {
  await i18n.changeLanguage('en');
  try {
    await run();
  } finally {
    await i18n.changeLanguage('sl');
  }
}

const KEY = 'xoloitzcuintli';

describe('M5-R10-123 Xoloitzcuintli', () => {
  it('is a paid dog without a brushing routine (hairless), with its own name', async () => {
    expect(readSpecies(undefined, readBreed(KEY))).toBe('dog');
    expect(isDefaultFreeBreed(KEY)).toBe(false);
    expect(hasGroomingRoutine(KEY)).toBe(false);
    expect(breedName(KEY)).toBe('Mehiški goli pes');
    await inEnglish(() => {
      expect(breedName(KEY)).toBe('Xoloitzcuintli');
    });
  });

  it('quotes 2.000 from the arrival, adult 2.000, senior 1.500 and no brushing — SL and EN', async () => {
    const D = PICKER_STRINGS.ageHints[KEY];
    expect(D.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan (kot odrasel pes).');
    expect(D.young).toBe('2 obroka na dan; sprehod 2.000 korakov na dan (kot odrasel pes).');
    expect(D.adult).toBe('2 obroka na dan; sprehod 2.000 korakov na dan.');
    expect(D.senior).toBe('2 obroka na dan; krajši sprehod — 1.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints[KEY]).toBe('Del 12-tedenskega izziva.');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints[KEY];
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day (like an adult dog).');
      expect(E.young).toBe('2 meals a day; walks of 2,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 2,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 1,500 steps a day.');
      expect(PICKER_STRINGS.breedHints[KEY]).toBe('Part of the 12-week challenge.');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue', () => {
    const dog = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === KEY);
    expect(dog).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 1140 }));
    expect(dog?.search_keywords).toEqual(['xoloitzcuintli', 'xoloitzcuintle', 'xolo', 'mehiški goli pes', 'mehiski goli pes', 'mexican hairless dog', 'mexican hairless', 'sholo', 'goli pes']);
    expect(dog?.suitability).toEqual({
      suits: ['family_pet', 'children'],
      consider: ['sun_protection', 'cold_sensitive', 'chases_small_pets'],
    });
  });

  it('is found by its names', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('xoloitzcuintli')).toEqual([KEY]);
    expect(find('xolo')).toEqual([KEY]);
    expect(find('mehiški goli pes')).toEqual([KEY]);
    expect(find('mehiski goli')).toEqual([KEY]);
    expect(find('mexican hairless')).toEqual([KEY]);
  });

  it('shows its chips in the picker, quotes 2.000 / 1.500 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const option = screen.getByTestId(`breed-option-${KEY}`);
    expect(option).toHaveTextContent(/Mehiški goli pes/);
    expect(option).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(option).not.toHaveTextContent(/česanje/);
    const suits = screen.getByTestId(`breed-suitability-${KEY}-suits`);
    expect(suits).not.toHaveTextContent(/aktivno družino/);
    expect(suits).toHaveTextContent(/družinsko življenje/);
    expect(suits).toHaveTextContent(/družino z otroki/);
    expect(suits).not.toHaveTextContent(/življenje v stanovanju/);
    const consider = screen.getByTestId(`breed-suitability-${KEY}-consider`);
    expect(consider).not.toHaveTextContent(/izpada mu dlaka/);
    expect(consider).toHaveTextContent(/zaščito pred soncem/);
    expect(consider).toHaveTextContent(/občutljiv na mraz/);
    expect(consider).toHaveTextContent(/lovski nagon/);
    expect(consider).not.toHaveTextContent(/česanje večkrat na teden/);
    expect(consider).not.toHaveTextContent(/rad ima družbo/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'mehiški goli pes');
    expect(screen.getByTestId(`breed-option-${KEY}`)).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId(`breed-option-${KEY}`));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/2\.000 korakov/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/2\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/1\.500 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: KEY, origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: KEY }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const consider = screen.getByTestId(`breed-suitability-${KEY}-consider`);
      expect(consider).not.toHaveTextContent(/sheds/);
      expect(screen.getByTestId(`breed-suitability-${KEY}-suits`)).toHaveTextContent(/family life/);
      expect(consider).toHaveTextContent(/strong prey drive/);
      expect(consider).toHaveTextContent(/sun protection/);
      expect(consider).not.toHaveTextContent(/loves company/);
      expect(consider).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId(`breed-option-${KEY}`)).toHaveTextContent(/Xoloitzcuintli/);
    });
  });
});
