/**
 * M5-R10-51: the Newfoundland — the breed's own expectations (runbook "Own files only",
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

describe('M5-R10-51 Newfoundland', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('newfoundland'))).toBe('dog');
    expect(isDefaultFreeBreed('newfoundland')).toBe(false);
    expect(hasGroomingRoutine('newfoundland')).toBe(true);
    expect(breedName('newfoundland')).toBe('Novofundlandec');
    await inEnglish(() => {
      expect(breedName('newfoundland')).toBe('Newfoundland');
    });
  });

  it('quotes 2.000 → 6.000 (at 6 months), young / adult 6.000, senior 4.500 and brushing every day — SL and EN', async () => {
    const N = PICKER_STRINGS.ageHints.newfoundland;
    expect(N.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 6.000 pri 6 mesecih.');
    expect(N.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(N.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(N.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.newfoundland).toBe('Del 12-tedenskega izziva · česanje vsak dan');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.newfoundland;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 6,000 at 6 months.');
      expect(E.young).toBe('2 meals a day; walks of 6,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 6,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 4,500 steps a day.');
      expect(PICKER_STRINGS.breedHints.newfoundland).toBe('Part of the 12-week challenge · brushing every day');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue (no new tag)', () => {
    const newf = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'newfoundland');
    expect(newf).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 470 }));
    expect(newf?.search_keywords).toEqual(['newfoundland', 'newfie', 'newf', 'novofundlandec', 'novofundlandski pes', 'njufi', 'terra nova']);
    expect(newf?.suitability).toEqual({
      suits: ['family_pet', 'children', 'other_pets'],
      consider: ['shorter_lifespan', 'eyelid_problems', 'needs_company', 'sheds', 'frequent_grooming', 'drools'],
    });
  });

  it('is found by its EN / SL names and nicknames', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Newfoundland')).toEqual(['newfoundland']);
    expect(find('newfie')).toEqual(['newfoundland']);
    expect(find('Novofundlandec')).toEqual(['newfoundland']);
    expect(find('novofundlandski pes')).toEqual(['newfoundland']);
    expect(find('njufi')).toEqual(['newfoundland']);
  });

  it('shows its chips in the picker, quotes 6.000 / 4.500 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const newf = screen.getByTestId('breed-option-newfoundland');
    expect(newf).toHaveTextContent(/Novofundlandec/);
    expect(newf).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(newf).toHaveTextContent(/česanje vsak dan/);
    const suits = screen.getByTestId('breed-suitability-newfoundland-suits');
    expect(suits).toHaveTextContent(/družinsko življenje/);
    expect(suits).toHaveTextContent(/družino z otroki/);
    expect(suits).toHaveTextContent(/dom z drugimi ljubljenčki/);
    const consider = screen.getByTestId('breed-suitability-newfoundland-consider');
    expect(consider).toHaveTextContent(/krajša življenjska doba/);
    expect(consider).toHaveTextContent(/veke in oči/);
    expect(consider).toHaveTextContent(/rad ima družbo/);
    expect(consider).toHaveTextContent(/izpada mu dlaka/);
    expect(consider).toHaveTextContent(/česanje večkrat na teden/);
    expect(consider).toHaveTextContent(/se slini/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'novofundland');
    expect(screen.getByTestId('breed-option-newfoundland')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-newfoundland'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 6\.000 pri 6 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/6\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/4\.500 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'newfoundland', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'newfoundland' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-newfoundland-suits');
      const consider = screen.getByTestId('breed-suitability-newfoundland-consider');
      expect(suits).toHaveTextContent(/family life/);
      expect(suits).toHaveTextContent(/families with children/);
      expect(suits).toHaveTextContent(/homes with other pets/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).toHaveTextContent(/shorter lifespan/);
      expect(consider).toHaveTextContent(/eyelids and eyes/);
      expect(consider).toHaveTextContent(/loves company/);
      expect(consider).toHaveTextContent(/needs brushing several times a week/);
      expect(consider).toHaveTextContent(/drools/);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-newfoundland')).toHaveTextContent(/Newfoundland/);
    });
  });
});
