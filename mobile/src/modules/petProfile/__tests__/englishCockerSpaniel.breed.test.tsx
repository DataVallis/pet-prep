/**
 * M5-R10-41: the English Cocker Spaniel — the breed's own expectations (runbook "Own files only",
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

describe('M5-R10-41 English Cocker Spaniel', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('english_cocker_spaniel'))).toBe('dog');
    expect(isDefaultFreeBreed('english_cocker_spaniel')).toBe(false);
    expect(hasGroomingRoutine('english_cocker_spaniel')).toBe(true);
    expect(breedName('english_cocker_spaniel')).toBe('Angleški koker španjel');
    await inEnglish(() => {
      expect(breedName('english_cocker_spaniel')).toBe('English Cocker Spaniel');
    });
  });

  it('quotes 2.000 → 6.000 (at 6 months), young / adult 6.000, senior 4.500 and brushing every day — SL and EN', async () => {
    const C = PICKER_STRINGS.ageHints.english_cocker_spaniel;
    expect(C.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 6.000 pri 6 mesecih.');
    expect(C.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(C.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(C.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.english_cocker_spaniel).toBe('Del 12-tedenskega izziva · česanje vsak dan');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.english_cocker_spaniel;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 6,000 at 6 months.');
      expect(E.young).toBe('2 meals a day; walks of 6,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 6,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 4,500 steps a day.');
      expect(PICKER_STRINGS.breedHints.english_cocker_spaniel).toBe('Part of the 12-week challenge · brushing every day');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue (no new tag)', () => {
    const ecs = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'english_cocker_spaniel');
    expect(ecs).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 370 }));
    expect(ecs?.search_keywords).toEqual(['english cocker spaniel', 'angleški koker španjel', 'angleski koker spanjel', 'english cocker', 'angleški koker', 'angleski koker', 'cocker spaniel', 'koker španjel', 'koker spanjel', 'merry cocker']);
    expect(ecs?.suitability).toEqual({
      suits: ['family_pet', 'children', 'other_pets'],
      consider: ['frequent_grooming', 'food_motivated_weight', 'chews_when_bored', 'needs_company', 'sheds'],
    });
  });

  it('is found by its EN / SL names, with and without the carons — "american cocker" stays the American breed', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('English Cocker Spaniel')).toEqual(['english_cocker_spaniel']);
    expect(find('Angleški koker španjel')).toEqual(['english_cocker_spaniel']);
    expect(find('angleski koker spanjel')).toEqual(['english_cocker_spaniel']);
    expect(find('english cocker')).toEqual(['english_cocker_spaniel']);
    expect(find('merry cocker')).toEqual(['english_cocker_spaniel']);
    expect(find('american cocker')).toEqual(['american_cocker_spaniel']);
  });

  it('shows its chips in the picker, quotes 6.000 / 4.500 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const ecs = screen.getByTestId('breed-option-english_cocker_spaniel');
    expect(ecs).toHaveTextContent(/Angleški koker španjel/);
    expect(ecs).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(ecs).toHaveTextContent(/česanje vsak dan/);
    const suits = screen.getByTestId('breed-suitability-english_cocker_spaniel-suits');
    expect(suits).toHaveTextContent(/družinsko življenje/);
    expect(suits).toHaveTextContent(/družino z otroki/);
    expect(suits).toHaveTextContent(/dom z drugimi ljubljenčki/);
    expect(suits).not.toHaveTextContent(/aktivno družino/);
    const consider = screen.getByTestId('breed-suitability-english_cocker_spaniel-consider');
    expect(consider).toHaveTextContent(/česanje večkrat na teden/);
    expect(consider).toHaveTextContent(/pazite na težo/);
    expect(consider).toHaveTextContent(/grize stvari/);
    expect(consider).toHaveTextContent(/rad ima družbo/);
    expect(consider).toHaveTextContent(/izpada mu dlaka/);
    expect(consider).not.toHaveTextContent(/lovski nagon/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'angleški koker');
    expect(screen.getByTestId('breed-option-english_cocker_spaniel')).toBeTruthy();
    expect(screen.queryByTestId('breed-option-american_cocker_spaniel')).toBeNull();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-english_cocker_spaniel'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 6\.000 pri 6 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/6\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/4\.500 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'english_cocker_spaniel', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'english_cocker_spaniel' }),
    );
  });

  it('shows the chips in English, without numbers', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-english_cocker_spaniel-suits');
      const consider = screen.getByTestId('breed-suitability-english_cocker_spaniel-consider');
      expect(suits).toHaveTextContent(/family life/);
      expect(suits).toHaveTextContent(/families with children/);
      expect(suits).toHaveTextContent(/homes with other pets/);
      expect(consider).toHaveTextContent(/needs brushing several times a week/);
      expect(consider).toHaveTextContent(/loves food — watch the weight/);
      expect(consider).toHaveTextContent(/chews things when bored/);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-english_cocker_spaniel')).toHaveTextContent(/English Cocker Spaniel/);
    });
  });
});
