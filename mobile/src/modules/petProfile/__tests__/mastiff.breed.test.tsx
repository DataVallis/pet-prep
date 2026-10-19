/**
 * M5-R10-45: the Mastiff — the breed's own expectations (runbook "Own files only",
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

describe('M5-R10-45 Mastiff', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('mastiff'))).toBe('dog');
    expect(isDefaultFreeBreed('mastiff')).toBe(false);
    expect(hasGroomingRoutine('mastiff')).toBe(true);
    expect(breedName('mastiff')).toBe('Angleški mastif');
    await inEnglish(() => {
      expect(breedName('mastiff')).toBe('Mastiff');
    });
  });

  it('quotes 2.000 → 6.000 (at 6 months), young / adult 6.000, senior 4.500 and brushing once a week — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.mastiff;
    expect(P.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 6.000 pri 6 mesecih.');
    expect(P.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(P.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.mastiff).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.mastiff;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 6,000 at 6 months.');
      expect(E.young).toBe('2 meals a day; walks of 6,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 6,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 4,500 steps a day.');
      expect(PICKER_STRINGS.breedHints.mastiff).toBe('Part of the 12-week challenge · brushing 1× a week');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue (no new tag)', () => {
    const mastiff = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'mastiff');
    expect(mastiff).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 410 }));
    expect(mastiff?.search_keywords).toEqual(['mastiff', 'english mastiff', 'old english mastiff', 'angleški mastif', 'angleski mastif', 'mastif']);
    expect(mastiff?.suitability).toEqual({
      suits: ['family_pet', 'children', 'other_pets'],
      consider: ['not_for_small_children', 'shorter_lifespan', 'eyelid_problems', 'sensitive_skin', 'hips_hind_legs', 'sheds', 'drools'],
    });
  });

  it('is found by its EN / SL names (with and without diacritics)', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Mastiff')).toEqual(['mastiff']);
    expect(find('English Mastiff')).toEqual(['mastiff']);
    expect(find('angleški mastif')).toEqual(['mastiff']);
    expect(find('angleski mastif')).toEqual(['mastiff']);
    expect(find('mastif')).toEqual(['mastiff']);
  });

  it('shows its chips in the picker, quotes 6.000 / 4.500 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const mastiff = screen.getByTestId('breed-option-mastiff');
    expect(mastiff).toHaveTextContent(/Angleški mastif/);
    expect(mastiff).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(mastiff).toHaveTextContent(/česanje 1× na teden/);
    const suits = screen.getByTestId('breed-suitability-mastiff-suits');
    expect(suits).toHaveTextContent(/družinsko življenje/);
    expect(suits).toHaveTextContent(/družino z otroki/);
    expect(suits).toHaveTextContent(/dom z drugimi ljubljenčki/);
    const consider = screen.getByTestId('breed-suitability-mastiff-consider');
    expect(consider).toHaveTextContent(/ni za dom z majhnimi otroki/);
    expect(consider).toHaveTextContent(/krajša življenjska doba/);
    expect(consider).toHaveTextContent(/veke in oči/);
    expect(consider).toHaveTextContent(/občutljiva koža/);
    expect(consider).toHaveTextContent(/kolki in zadnje noge/);
    expect(consider).toHaveTextContent(/izpada mu dlaka/);
    expect(consider).toHaveTextContent(/se slini/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'mastif');
    expect(screen.getByTestId('breed-option-mastiff')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-mastiff'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 6\.000 pri 6 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/6\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/4\.500 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'mastiff', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'mastiff' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-mastiff-suits');
      const consider = screen.getByTestId('breed-suitability-mastiff-consider');
      expect(suits).toHaveTextContent(/family life/);
      expect(suits).toHaveTextContent(/families with children/);
      expect(suits).toHaveTextContent(/homes with other pets/);
      expect(consider).toHaveTextContent(/not for homes with small children/);
      expect(consider).toHaveTextContent(/shorter lifespan/);
      expect(consider).toHaveTextContent(/eyelids and eyes/);
      expect(consider).toHaveTextContent(/sensitive skin/);
      expect(consider).toHaveTextContent(/hips and hind legs/);
      expect(consider).toHaveTextContent(/sheds/);
      expect(consider).toHaveTextContent(/drools/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-mastiff')).toHaveTextContent(/Mastiff/);
    });
  });
});
