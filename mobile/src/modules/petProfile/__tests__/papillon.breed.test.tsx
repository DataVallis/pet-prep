/**
 * M5-R10-48: the Papillon — the breed's own expectations (runbook "Own files only",
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

describe('M5-R10-48 Papillon', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('papillon'))).toBe('dog');
    expect(isDefaultFreeBreed('papillon')).toBe(false);
    expect(hasGroomingRoutine('papillon')).toBe(true);
    expect(breedName('papillon')).toBe('Papijon');
    await inEnglish(() => {
      expect(breedName('papillon')).toBe('Papillon');
    });
  });

  it('quotes 2.000 → 3.000 (at 3 months), young / adult 3.000, senior 2.300 and brushing 3× a week — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.papillon;
    expect(P.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 3.000 pri 3 mesecih.');
    expect(P.young).toBe('2 obroka na dan; sprehod 3.000 korakov na dan (kot odrasel pes).');
    expect(P.adult).toBe('2 obroka na dan; sprehod 3.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 2.300 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.papillon).toBe('Del 12-tedenskega izziva · česanje 3× na teden');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.papillon;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 3,000 at 3 months.');
      expect(E.young).toBe('2 meals a day; walks of 3,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 3,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 2,300 steps a day.');
      expect(PICKER_STRINGS.breedHints.papillon).toBe('Part of the 12-week challenge · brushing 3× a week');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue (no new tag)', () => {
    const pap = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'papillon');
    expect(pap).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 440 }));
    expect(pap?.search_keywords).toEqual(['papillon', 'phalene', 'phalène', 'butterfly dog', 'papijon', 'falen']);
    expect(pap?.suitability).toEqual({
      suits: ['apartment', 'family_pet', 'children', 'other_pets'],
      consider: ['frequent_grooming', 'needs_mental_stimulation', 'needs_company', 'sheds'],
    });
  });

  it('is found by its EN / SL names and the Phalène name — "pritlikavi" / "toy" stay with the other breeds', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Papillon')).toEqual(['papillon']);
    expect(find('Papijon')).toEqual(['papillon']);
    expect(find('phalene')).toEqual(['papillon']);
    expect(find('Phalène')).toEqual(['papillon']);
    expect(find('falen')).toEqual(['papillon']);
    expect(find('butterfly dog')).toEqual(['papillon']);
    expect(find('Pritlikavi')).not.toContain('papillon');
  });

  it('shows its chips in the picker, quotes 3.000 / 2.300 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const pap = screen.getByTestId('breed-option-papillon');
    expect(pap).toHaveTextContent(/Papijon/);
    expect(pap).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(pap).toHaveTextContent(/česanje 3× na teden/);
    const suits = screen.getByTestId('breed-suitability-papillon-suits');
    expect(suits).toHaveTextContent(/življenje v stanovanju/);
    expect(suits).toHaveTextContent(/družinsko življenje/);
    expect(suits).toHaveTextContent(/družino z otroki/);
    expect(suits).toHaveTextContent(/dom z drugimi ljubljenčki/);
    const consider = screen.getByTestId('breed-suitability-papillon-consider');
    expect(consider).toHaveTextContent(/česanje večkrat na teden/);
    expect(consider).toHaveTextContent(/potrebuje miselne izzive/);
    expect(consider).toHaveTextContent(/rad ima družbo/);
    expect(consider).toHaveTextContent(/izpada mu dlaka/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'papijon');
    expect(screen.getByTestId('breed-option-papillon')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-papillon'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 3\.000 pri 3 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/3\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/2\.300 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'papillon', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'papillon' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-papillon-suits');
      const consider = screen.getByTestId('breed-suitability-papillon-consider');
      expect(suits).toHaveTextContent(/apartment living/);
      expect(suits).toHaveTextContent(/families with children/);
      expect(suits).toHaveTextContent(/homes with other pets/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).toHaveTextContent(/needs brushing several times a week/);
      expect(consider).toHaveTextContent(/needs mental challenges/);
      expect(consider).toHaveTextContent(/loves company/);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-papillon')).toHaveTextContent(/Papillon/);
    });
  });
});
