/**
 * M5-R10-43: the Pug — the breed's own expectations (runbook "Own files only",
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

describe('M5-R10-43 Pug', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('pug'))).toBe('dog');
    expect(isDefaultFreeBreed('pug')).toBe(false);
    expect(hasGroomingRoutine('pug')).toBe(true);
    expect(breedName('pug')).toBe('Mops');
    await inEnglish(() => {
      expect(breedName('pug')).toBe('Pug');
    });
  });

  it('quotes 2.000 → 6.000 (at 6 months), young / adult 6.000, senior 4.500 and brushing once a week — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.pug;
    expect(P.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 6.000 pri 6 mesecih.');
    expect(P.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(P.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.pug).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.pug;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 6,000 at 6 months.');
      expect(E.young).toBe('2 meals a day; walks of 6,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 6,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 4,500 steps a day.');
      expect(PICKER_STRINGS.breedHints.pug).toBe('Part of the 12-week challenge · brushing 1× a week');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue (no new tag)', () => {
    const pug = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'pug');
    expect(pug).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 390 }));
    expect(pug?.search_keywords).toEqual(['pug', 'mops', 'mopsek', 'pug dog', 'carlin']);
    expect(pug?.suitability).toEqual({
      suits: ['apartment', 'children', 'other_pets'],
      consider: ['brachycephalic_breathing', 'sensitive_skin', 'food_motivated_weight', 'needs_company', 'sheds'],
    });
  });

  it('is found by its EN / SL names and the FCI name', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Pug')).toEqual(['pug']);
    expect(find('Mops')).toEqual(['pug']);
    expect(find('mopsek')).toEqual(['pug']);
    expect(find('carlin')).toEqual(['pug']);
  });

  it('shows its chips in the picker, quotes 6.000 / 4.500 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const pug = screen.getByTestId('breed-option-pug');
    expect(pug).toHaveTextContent(/Mops/);
    expect(pug).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(pug).toHaveTextContent(/česanje 1× na teden/);
    const suits = screen.getByTestId('breed-suitability-pug-suits');
    expect(suits).toHaveTextContent(/življenje v stanovanju/);
    expect(suits).toHaveTextContent(/družino z otroki/);
    expect(suits).toHaveTextContent(/dom z drugimi ljubljenčki/);
    const consider = screen.getByTestId('breed-suitability-pug-consider');
    expect(consider).toHaveTextContent(/kratek gobček — težave z dihanjem in vročino/);
    expect(consider).toHaveTextContent(/občutljiva koža/);
    expect(consider).toHaveTextContent(/pazite na težo/);
    expect(consider).toHaveTextContent(/rad ima družbo/);
    expect(consider).toHaveTextContent(/izpada mu dlaka/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'mops');
    expect(screen.getByTestId('breed-option-pug')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-pug'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 6\.000 pri 6 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/6\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/4\.500 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'pug', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'pug' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-pug-suits');
      const consider = screen.getByTestId('breed-suitability-pug-consider');
      expect(suits).toHaveTextContent(/apartment living/);
      expect(suits).toHaveTextContent(/families with children/);
      expect(suits).toHaveTextContent(/homes with other pets/);
      expect(consider).toHaveTextContent(/flat face — breathing and heat problems/);
      expect(consider).toHaveTextContent(/sensitive skin/);
      expect(consider).toHaveTextContent(/watch the weight/);
      expect(consider).toHaveTextContent(/loves company/);
      expect(consider).toHaveTextContent(/sheds/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-pug')).toHaveTextContent(/Pug/);
    });
  });
});
