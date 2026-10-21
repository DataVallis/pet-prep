/**
 * M5-R10-53: the Australian Cattle Dog — the breed's own expectations (runbook "Own files only",
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

describe('M5-R10-53 Australian Cattle Dog', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('australian_cattle_dog'))).toBe('dog');
    expect(isDefaultFreeBreed('australian_cattle_dog')).toBe(false);
    expect(hasGroomingRoutine('australian_cattle_dog')).toBe(true);
    expect(breedName('australian_cattle_dog')).toBe('Avstralski govedar');
    await inEnglish(() => {
      expect(breedName('australian_cattle_dog')).toBe('Australian Cattle Dog');
    });
  });

  it('quotes 2.000 → 12.000 (at 12 months), young 9.000 → 12.000, adult 12.000, senior 9.000 and brushing 1× a week — SL and EN', async () => {
    const D = PICKER_STRINGS.ageHints.australian_cattle_dog;
    expect(D.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(D.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(D.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(D.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.australian_cattle_dog).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.australian_cattle_dog;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 12,000 at 12 months.');
      expect(E.young).toBe('2 meals a day; walks of 9,000 steps a day, more each week up to 12,000 at 12 months.');
      expect(E.adult).toBe('2 meals a day; walks of 12,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 9,000 steps a day.');
      expect(PICKER_STRINGS.breedHints.australian_cattle_dog).toBe('Part of the 12-week challenge · brushing 1× a week');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue (no new tag)', () => {
    const dal = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'australian_cattle_dog');
    expect(dal).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 490 }));
    expect(dal?.search_keywords).toEqual(['australian cattle dog', 'avstralski govedar', 'avstralski govedarski pes', 'cattle dog', 'blue heeler', 'red heeler', 'queensland heeler', 'heeler', 'acd']);
    expect(dal?.suitability).toEqual({
      suits: ['active_family', 'family_pet'],
      consider: ['long_daily_exercise', 'needs_mental_stimulation', 'may_herd_children', 'sheds'],
    });
  });

  it('is found by its EN / SL names and nicknames', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Australian Cattle Dog')).toEqual(['australian_cattle_dog']);
    expect(find('Avstralski govedar')).toEqual(['australian_cattle_dog']);
    expect(find('blue heeler')).toEqual(['australian_cattle_dog']);
    expect(find('queensland heeler')).toEqual(['australian_cattle_dog']);
  });

  it('shows its chips in the picker, quotes 12.000 / 9.000 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const dal = screen.getByTestId('breed-option-australian_cattle_dog');
    expect(dal).toHaveTextContent(/Avstralski govedar/);
    expect(dal).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(dal).toHaveTextContent(/česanje 1× na teden/);
    const suits = screen.getByTestId('breed-suitability-australian_cattle_dog-suits');
    expect(suits).toHaveTextContent(/aktivno družino/);
    expect(suits).toHaveTextContent(/družinsko življenje/);
    const consider = screen.getByTestId('breed-suitability-australian_cattle_dog-consider');
    expect(consider).toHaveTextContent(/veliko gibanja/);
    expect(consider).toHaveTextContent(/potrebuje miselne izzive/);
    expect(consider).toHaveTextContent(/»pase« otroke/);
    expect(consider).toHaveTextContent(/izpada mu dlaka/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'govedar');
    expect(screen.getByTestId('breed-option-australian_cattle_dog')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-australian_cattle_dog'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 12\.000 pri 12 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/12\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/9\.000 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'australian_cattle_dog', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'australian_cattle_dog' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-australian_cattle_dog-suits');
      const consider = screen.getByTestId('breed-suitability-australian_cattle_dog-consider');
      expect(suits).toHaveTextContent(/an active family/);
      expect(suits).toHaveTextContent(/family life/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).toHaveTextContent(/needs lots of exercise every day/);
      expect(consider).toHaveTextContent(/needs mental challenges/);
      expect(consider).toHaveTextContent(/“herd” children/);
      expect(consider).toHaveTextContent(/sheds/);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-australian_cattle_dog')).toHaveTextContent(/Australian Cattle Dog/);
    });
  });
});
