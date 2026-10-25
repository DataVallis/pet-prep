/**
 * M5-R10-49: the Portuguese Water Dog — the breed's own expectations (runbook "Own files only",
 * David 2026-10-15): picker hints EN + SL, fallback catalogue entry, search, species, chips, confirm.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import PetPickerStep from '@/components/parent/PetPickerStep';
import { i18n } from '@/i18n';
import {
  FALLBACK_CATALOGUE,
  INITIAL_PICKER_CHOICE,
  PICKER_STRINGS,
  searchBreeds,
  type BreedCatalogue,
} from '@/modules/petProfile/picker';
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

describe('M5-R10-49 Portuguese Water Dog', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('portuguese_water_dog'))).toBe('dog');
    expect(isDefaultFreeBreed('portuguese_water_dog')).toBe(false);
    expect(hasGroomingRoutine('portuguese_water_dog')).toBe(true);
    expect(breedName('portuguese_water_dog')).toBe('Portugalski vodni pes');
    await inEnglish(() => {
      expect(breedName('portuguese_water_dog')).toBe('Portuguese Water Dog');
    });
  });

  it('quotes 2.000 → 6.000 (at 6 months), young / adult 6.000, senior 4.500 and brushing every day — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.portuguese_water_dog;
    expect(P.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 6.000 pri 6 mesecih.');
    expect(P.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(P.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.portuguese_water_dog).toBe('Del 12-tedenskega izziva · česanje vsak dan');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.portuguese_water_dog;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 6,000 at 6 months.');
      expect(E.young).toBe('2 meals a day; walks of 6,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 6,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 4,500 steps a day.');
      expect(PICKER_STRINGS.breedHints.portuguese_water_dog).toBe('Part of the 12-week challenge · brushing every day');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue (no new tag)', () => {
    const pwd = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'portuguese_water_dog');
    expect(pwd).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 450 }));
    expect(pwd?.search_keywords).toEqual(['portuguese water dog', 'portie', 'pwd', 'cao de agua', 'cão de água português', 'portugalski vodni pes', 'vodni pes', 'portugalski']);
    expect(pwd?.suitability).toEqual({
      suits: ['family_pet', 'children', 'large_home', 'other_pets', 'low_shedding'],
      consider: ['frequent_grooming'],
    });
  });

  it('is found by its EN / SL names, the Portuguese name and the nickname', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Portuguese Water Dog')).toEqual(['portuguese_water_dog']);
    expect(find('Portugalski vodni pes')).toEqual(['portuguese_water_dog']);
    expect(find('vodni pes')).toEqual(['portuguese_water_dog']);
    expect(find('Cão de Água')).toEqual(['portuguese_water_dog']);
    expect(find('portie')).toEqual(['portuguese_water_dog']);
  });

  it('shows its chips in the picker, quotes 6.000 / 4.500 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const pwd = screen.getByTestId('breed-option-portuguese_water_dog');
    expect(pwd).toHaveTextContent(/Portugalski vodni pes/);
    expect(pwd).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(pwd).toHaveTextContent(/česanje vsak dan/);
    const suits = screen.getByTestId('breed-suitability-portuguese_water_dog-suits');
    expect(suits).toHaveTextContent(/družinsko življenje/);
    expect(suits).toHaveTextContent(/družino z otroki/);
    expect(suits).toHaveTextContent(/veliko hišo z vrtom/);
    expect(suits).toHaveTextContent(/dom z drugimi ljubljenčki/);
    expect(suits).toHaveTextContent(/dom, kjer želite manj dlak/);
    const consider = screen.getByTestId('breed-suitability-portuguese_water_dog-consider');
    expect(consider).toHaveTextContent(/česanje večkrat na teden/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'vodni pes');
    expect(screen.getByTestId('breed-option-portuguese_water_dog')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-portuguese_water_dog'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 6\.000 pri 6 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/6\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/4\.500 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'portuguese_water_dog', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'portuguese_water_dog' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-portuguese_water_dog-suits');
      const consider = screen.getByTestId('breed-suitability-portuguese_water_dog-consider');
      expect(suits).toHaveTextContent(/family life/);
      expect(suits).toHaveTextContent(/families with children/);
      expect(suits).toHaveTextContent(/a large house and garden/);
      expect(suits).toHaveTextContent(/homes with other pets/);
      expect(suits).toHaveTextContent(/homes that prefer less shedding/);
      expect(suits).not.toHaveTextContent(/hypoallergenic|allerg/i);
      expect(consider).toHaveTextContent(/needs brushing several times a week/);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-portuguese_water_dog')).toHaveTextContent(/Portuguese Water Dog/);
    });
  });
});
