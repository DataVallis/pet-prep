/**
 * M5-R10-91: the Pekingese (FCI Group 9 Section 8) — the breed's own expectations (runbook "Own files only", David
 * 2026-10-15): picker hints EN + SL, fallback catalogue entry, search, species, chips, confirm.
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

const KEY = 'pekingese';

describe('M5-R10-91 Pekingese', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed(KEY))).toBe('dog');
    expect(isDefaultFreeBreed(KEY)).toBe(false);
    expect(hasGroomingRoutine(KEY)).toBe(true);
    expect(breedName(KEY)).toBe('Pekinezer');
    await inEnglish(() => {
      expect(breedName(KEY)).toBe('Pekingese');
    });
  });

  it('quotes 2.000 → 3.000 (at 3 months), adult 3.000, senior 2.300 and brushing every day — SL and EN', async () => {
    const D = PICKER_STRINGS.ageHints[KEY];
    expect(D.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 3.000 pri 3 mesecih.');
    expect(D.young).toBe('2 obroka na dan; sprehod 3.000 korakov na dan (kot odrasel pes).');
    expect(D.adult).toBe('2 obroka na dan; sprehod 3.000 korakov na dan.');
    expect(D.senior).toBe('2 obroka na dan; krajši sprehod — 2.300 korakov na dan.');
    expect(PICKER_STRINGS.breedHints[KEY]).toBe('Del 12-tedenskega izziva · česanje vsak dan');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints[KEY];
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 3,000 at 3 months.');
      expect(E.young).toBe('2 meals a day; walks of 3,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 3,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 2,300 steps a day.');
      expect(PICKER_STRINGS.breedHints[KEY]).toBe('Part of the 12-week challenge · brushing every day');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue', () => {
    const dog = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === KEY);
    expect(dog).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 850 }));
    expect(dog?.search_keywords).toEqual(['pekingese', 'pekinese', 'peke', 'lion dog', 'pekinezer', 'pekinez', 'pekinški pes', 'pekinski pes']);
    expect(dog?.suitability).toEqual({
      suits: [],
      consider: ['brachycephalic_breathing', 'older_children', 'needs_company', 'eyelid_problems', 'sensitive_skin', 'sheds', 'frequent_grooming'],
    });
  });

  it('is found by its EN / SL names', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('pekingese')).toEqual([KEY]);
    expect(find('pekinezer')).toEqual([KEY]);
    expect(find('peke')).toContain(KEY);
    expect(find('pekinski pes')).toEqual([KEY]);
  });

  it('shows its chips in the picker, quotes 3.000 / 2.300 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const option = screen.getByTestId(`breed-option-${KEY}`);
    expect(option).toHaveTextContent(/Pekinezer/);
    expect(option).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(option).toHaveTextContent(/česanje vsak dan/);
    expect(screen.queryByTestId(`breed-suitability-${KEY}-suits`)).toBeNull();
    const consider = screen.getByTestId(`breed-suitability-${KEY}-consider`);
    expect(consider).toHaveTextContent(/kratek gobček — težave z dihanjem in vročino/);
    expect(consider).toHaveTextContent(/bolje za starejše otroke/);
    expect(consider).toHaveTextContent(/rad ima družbo/);
    expect(consider).toHaveTextContent(/veke in oči/);
    expect(consider).toHaveTextContent(/občutljiva koža/);
    expect(consider).toHaveTextContent(/izpada mu dlaka/);
    expect(consider).toHaveTextContent(/potrebuje česanje večkrat na teden/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'pekinezer');
    expect(screen.getByTestId(`breed-option-${KEY}`)).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId(`breed-option-${KEY}`));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 3\.000 pri 3 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/3\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/2\.300 korakov/);
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
      expect(consider).toHaveTextContent(/flat face — breathing and heat problems/);
      expect(consider).toHaveTextContent(/better with older children/);
      expect(consider).toHaveTextContent(/loves company/);
      expect(consider).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId(`breed-option-${KEY}`)).toHaveTextContent(/Pekingese/);
    });
  });
});
