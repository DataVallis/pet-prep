/**
 * M5-R10-42: the Maltese — the breed's own expectations (runbook "Own files only",
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

describe('M5-R10-42 Maltese', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('maltese'))).toBe('dog');
    expect(isDefaultFreeBreed('maltese')).toBe(false);
    expect(hasGroomingRoutine('maltese')).toBe(true);
    expect(breedName('maltese')).toBe('Maltežan');
    await inEnglish(() => {
      expect(breedName('maltese')).toBe('Maltese');
    });
  });

  it('quotes 2.000 → 3.000 (at 3 months), young / adult 3.000, senior 2.300 and brushing every day — SL and EN', async () => {
    const M = PICKER_STRINGS.ageHints.maltese;
    expect(M.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 3.000 pri 3 mesecih.');
    expect(M.young).toBe('2 obroka na dan; sprehod 3.000 korakov na dan (kot odrasel pes).');
    expect(M.adult).toBe('2 obroka na dan; sprehod 3.000 korakov na dan.');
    expect(M.senior).toBe('2 obroka na dan; krajši sprehod — 2.300 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.maltese).toBe('Del 12-tedenskega izziva · česanje vsak dan');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.maltese;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 3,000 at 3 months.');
      expect(E.young).toBe('2 meals a day; walks of 3,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 3,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 2,300 steps a day.');
      expect(PICKER_STRINGS.breedHints.maltese).toBe('Part of the 12-week challenge · brushing every day');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue (no new tag)', () => {
    const mal = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'maltese');
    expect(mal).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 380 }));
    expect(mal?.search_keywords).toEqual(['maltese', 'maltežan', 'maltezan', 'malteški bišon', 'malteski bison', 'malteški psiček', 'malteski psicek', 'bichon maltais', 'maltese terrier']);
    expect(mal?.suitability).toEqual({
      suits: ['apartment', 'low_shedding'],
      consider: ['frequent_grooming', 'needs_company'],
    });
  });

  it('is found by its EN / SL names, with and without the carons — "malinois" stays the Belgian Malinois', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Maltese')).toEqual(['maltese']);
    expect(find('Maltežan')).toEqual(['maltese']);
    expect(find('maltezan')).toEqual(['maltese']);
    expect(find('malteški bišon')).toEqual(['maltese']);
    expect(find('bichon maltais')).toEqual(['maltese']);
    expect(find('malinois')).toEqual(['belgian_malinois']);
  });

  it('shows its chips in the picker, quotes 3.000 / 2.300 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const mal = screen.getByTestId('breed-option-maltese');
    expect(mal).toHaveTextContent(/Maltežan/);
    expect(mal).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(mal).toHaveTextContent(/česanje vsak dan/);
    const suits = screen.getByTestId('breed-suitability-maltese-suits');
    expect(suits).toHaveTextContent(/življenje v stanovanju/);
    expect(suits).toHaveTextContent(/manj dlak/);
    expect(suits).not.toHaveTextContent(/družino z otroki/);
    const consider = screen.getByTestId('breed-suitability-maltese-consider');
    expect(consider).toHaveTextContent(/česanje večkrat na teden/);
    expect(consider).toHaveTextContent(/rad ima družbo/);
    expect(consider).not.toHaveTextContent(/izpada mu dlaka/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'maltežan');
    expect(screen.getByTestId('breed-option-maltese')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-maltese'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 3\.000 pri 3 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/3\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/2\.300 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'maltese', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'maltese' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-maltese-suits');
      const consider = screen.getByTestId('breed-suitability-maltese-consider');
      expect(suits).toHaveTextContent(/apartment living/);
      expect(suits).toHaveTextContent(/homes that prefer less shedding/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).toHaveTextContent(/needs brushing several times a week/);
      expect(consider).toHaveTextContent(/loves company/);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-maltese')).toHaveTextContent(/Maltese/);
    });
  });
});
