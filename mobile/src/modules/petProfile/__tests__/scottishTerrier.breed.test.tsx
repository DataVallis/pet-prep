/**
 * M5-R10-58: the Scottish Terrier — the breed's own expectations (runbook "Own files only",
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
  render(<PetPickerStep childName="Maja" catalogue={catalogue} initial={INITIAL_PICKER_CHOICE} onConfirm={onConfirm} onBack={jest.fn()} />);
  const toggle = screen.queryByTestId('breed-show-all');
  if (toggle) fireEvent.press(toggle);
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

describe('M5-R10-58 Scottish Terrier', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('scottish_terrier'))).toBe('dog');
    expect(isDefaultFreeBreed('scottish_terrier')).toBe(false);
    expect(hasGroomingRoutine('scottish_terrier')).toBe(true);
    expect(breedName('scottish_terrier')).toBe('Škotski terier');
    await inEnglish(() => {
      expect(breedName('scottish_terrier')).toBe('Scottish Terrier');
    });
  });

  it('quotes 2.000 → 6.000 (at 6 months), adult 6.000, senior 4.500 and brushing every day — SL and EN', async () => {
    const D = PICKER_STRINGS.ageHints.scottish_terrier;
    expect(D.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 6.000 pri 6 mesecih.');
    expect(D.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(D.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(D.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.scottish_terrier).toBe('Del 12-tedenskega izziva · česanje vsak dan');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.scottish_terrier;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 6,000 at 6 months.');
      expect(E.young).toBe('2 meals a day; walks of 6,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 6,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 4,500 steps a day.');
      expect(PICKER_STRINGS.breedHints.scottish_terrier).toBe('Part of the 12-week challenge · brushing every day');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue', () => {
    const scottie = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'scottish_terrier');
    expect(scottie).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 540 }));
    expect(scottie?.search_keywords).toEqual(['scottish terrier', 'skotski terier', 'škotski terier', 'scottie', 'scotty', 'aberdeen terrier', 'scottish']);
    expect(scottie?.suitability).toEqual({
      suits: ['family_pet'],
      consider: ['frequent_grooming', 'needs_company'],
    });
  });

  it('is found by its EN / SL names and the nickname Scottie', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Scottish Terrier')).toEqual(['scottish_terrier']);
    expect(find('škotski terier')).toEqual(['scottish_terrier']);
    // »škotski« alone also finds the other Scottish breeds (Westie, Rough Collie) — the ASCII variant of the SL name still finds it.
    expect(find('skotski terier')).toEqual(['scottish_terrier']);
    expect(find('skotski')).toContain('scottish_terrier');
    expect(find('scottie')).toEqual(['scottish_terrier']);
  });

  it('shows its chips in the picker, quotes 6.000 / 4.500 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const scottie = screen.getByTestId('breed-option-scottish_terrier');
    expect(scottie).toHaveTextContent(/Škotski terier/);
    expect(scottie).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(scottie).toHaveTextContent(/česanje vsak dan/);
    const suits = screen.getByTestId('breed-suitability-scottish_terrier-suits');
    expect(suits).toHaveTextContent(/družinsko življenje/);
    expect(suits).not.toHaveTextContent(/družino z otroki/);
    const consider = screen.getByTestId('breed-suitability-scottish_terrier-consider');
    expect(consider).toHaveTextContent(/česanje večkrat na teden/);
    expect(consider).toHaveTextContent(/rad ima družbo/);
    expect(consider).not.toHaveTextContent(/lovski nagon/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'škotski');
    expect(screen.getByTestId('breed-option-scottish_terrier')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-scottish_terrier'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 6\.000 pri 6 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/6\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/4\.500 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'scottish_terrier', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'scottish_terrier' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-scottish_terrier-suits');
      const consider = screen.getByTestId('breed-suitability-scottish_terrier-consider');
      expect(suits).toHaveTextContent(/family life/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).toHaveTextContent(/needs brushing several times a week/);
      expect(consider).toHaveTextContent(/loves company/);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-scottish_terrier')).toHaveTextContent(/Scottish Terrier/);
    });
  });
});
