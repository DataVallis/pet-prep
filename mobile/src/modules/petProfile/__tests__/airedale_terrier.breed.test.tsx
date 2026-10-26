/**
 * M5-R10-68: the Airedale Terrier (FCI 7) — the breed's own expectations (runbook "Own files only",
 * David 2026-10-15): picker hints EN + SL, fallback catalogue entry, search, species, chips, confirm.
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

const KEY = 'airedale_terrier';

describe('M5-R10-68 Airedale Terrier', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed(KEY))).toBe('dog');
    expect(isDefaultFreeBreed(KEY)).toBe(false);
    expect(hasGroomingRoutine(KEY)).toBe(true);
    expect(breedName(KEY)).toBe('Airedalski terier');
    await inEnglish(() => {
      expect(breedName(KEY)).toBe('Airedale Terrier');
    });
  });

  it('quotes 2.000 → 6.000 (at 6 months), adult 6.000, senior 4.500 and brushing every day — SL and EN', async () => {
    const D = PICKER_STRINGS.ageHints[KEY];
    expect(D.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 6.000 pri 6 mesecih.');
    expect(D.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(D.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(D.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints[KEY]).toBe('Del 12-tedenskega izziva · česanje vsak dan');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints[KEY];
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 6,000 at 6 months.');
      expect(E.young).toBe('2 meals a day; walks of 6,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 6,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 4,500 steps a day.');
      expect(PICKER_STRINGS.breedHints[KEY]).toBe('Part of the 12-week challenge · brushing every day');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue', () => {
    const dog = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === KEY);
    expect(dog).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 640 }));
    expect(dog?.search_keywords).toEqual(['airedale terrier', 'airedale', 'king of terriers', 'waterside terrier', 'bingley terrier', 'airedalski terier', 'airedale terier', 'erdelterier']);
    expect(dog?.suitability).toEqual({
      suits: ['family_pet', 'children', 'large_home', 'low_shedding'],
      consider: ['needs_mental_stimulation', 'needs_company', 'frequent_grooming'],
    });
  });

  it('is found by its EN / SL names and nicknames; never another terrier', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Airedale Terrier')).toEqual([KEY]);
    expect(find('airedale')).toEqual([KEY]);
    expect(find('King of Terriers')).toEqual([KEY]);
    expect(find('Airedalski terier')).toEqual([KEY]);
    expect(find('erdelterier')).toEqual([KEY]);
    expect(find('airedale')).not.toContain('staffordshire_bull_terrier');
  });

  it('shows its chips in the picker, quotes 6.000 / 4.500 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const option = screen.getByTestId(`breed-option-${KEY}`);
    expect(option).toHaveTextContent(/Airedalski terier/);
    expect(option).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(option).toHaveTextContent(/česanje vsak dan/);
    const suits = screen.getByTestId(`breed-suitability-${KEY}-suits`);
    const consider = screen.getByTestId(`breed-suitability-${KEY}-consider`);
    expect(suits).toHaveTextContent(/družinsko življenje/);
    expect(suits).toHaveTextContent(/družino z otroki/);
    expect(suits).toHaveTextContent(/veliko hišo z vrtom/);
    expect(suits).toHaveTextContent(/manj dlak/);
    expect(consider).toHaveTextContent(/miselne izzive/);
    expect(consider).toHaveTextContent(/rad ima družbo/);
    expect(consider).toHaveTextContent(/česanje večkrat na teden/);
    expect(consider).not.toHaveTextContent(/lahko lovi manjše živali/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'airedale');
    expect(screen.getByTestId(`breed-option-${KEY}`)).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId(`breed-option-${KEY}`));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 6\.000 pri 6 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/6\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/4\.500 korakov/);
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
      const suits = screen.getByTestId(`breed-suitability-${KEY}-suits`);
      const consider = screen.getByTestId(`breed-suitability-${KEY}-consider`);
      expect(suits).toHaveTextContent(/family life/);
      expect(suits).toHaveTextContent(/families with children/);
      expect(suits).toHaveTextContent(/a large house and garden/);
      expect(suits).toHaveTextContent(/less shedding/);
      expect(consider).toHaveTextContent(/needs mental challenges/);
      expect(consider).toHaveTextContent(/loves company/);
      expect(consider).toHaveTextContent(/needs brushing several times a week/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId(`breed-option-${KEY}`)).toHaveTextContent(/Airedale Terrier/);
    });
  });
});
