/**
 * M5-R10-50: the Bichon Frise — the breed's own expectations (runbook "Own files only",
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

describe('M5-R10-50 Bichon Frise', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('bichon_frise'))).toBe('dog');
    expect(isDefaultFreeBreed('bichon_frise')).toBe(false);
    expect(hasGroomingRoutine('bichon_frise')).toBe(true);
    expect(breedName('bichon_frise')).toBe('Kodrasti bišon');
    await inEnglish(() => {
      expect(breedName('bichon_frise')).toBe('Bichon Frise');
    });
  });

  it('quotes 2.000 → 3.000 (at 3 months), young / adult 3.000, senior 2.300 and brushing every day — SL and EN', async () => {
    const B = PICKER_STRINGS.ageHints.bichon_frise;
    expect(B.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 3.000 pri 3 mesecih.');
    expect(B.young).toBe('2 obroka na dan; sprehod 3.000 korakov na dan (kot odrasel pes).');
    expect(B.adult).toBe('2 obroka na dan; sprehod 3.000 korakov na dan.');
    expect(B.senior).toBe('2 obroka na dan; krajši sprehod — 2.300 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.bichon_frise).toBe('Del 12-tedenskega izziva · česanje vsak dan');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.bichon_frise;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 3,000 at 3 months.');
      expect(E.young).toBe('2 meals a day; walks of 3,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 3,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 2,300 steps a day.');
      expect(PICKER_STRINGS.breedHints.bichon_frise).toBe('Part of the 12-week challenge · brushing every day');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue (no new tag)', () => {
    const bic = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'bichon_frise');
    expect(bic).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 460 }));
    expect(bic?.search_keywords).toEqual(['bichon frise', 'bichon frisé', 'bichon', 'bichon a poil frise', 'bichon tenerife', 'kodrasti bišon', 'kodrasti bison', 'bišon']);
    expect(bic?.suitability).toEqual({
      suits: ['apartment', 'family_pet', 'children', 'other_pets', 'low_shedding'],
      consider: ['frequent_grooming', 'needs_company'],
    });
  });

  it('is found by its EN / SL names; "bichon" / "bišon" also keep the Havanese and the Maltese', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Bichon Frise')).toEqual(['bichon_frise']);
    expect(find('bichon frisé')).toEqual(['bichon_frise']);
    expect(find('Kodrasti bišon')).toEqual(['bichon_frise']);
    expect(find('kodrasti bison')).toEqual(['bichon_frise']);
    expect(find('bichon tenerife')).toEqual(['bichon_frise']);
    expect(find('bichon')).toContain('bichon_frise');
    expect(find('bišon')).toEqual(expect.arrayContaining(['bichon_frise', 'havanese', 'maltese']));
  });

  it('shows its chips in the picker, quotes 3.000 / 2.300 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const bic = screen.getByTestId('breed-option-bichon_frise');
    expect(bic).toHaveTextContent(/Kodrasti bišon/);
    expect(bic).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(bic).toHaveTextContent(/česanje vsak dan/);
    const suits = screen.getByTestId('breed-suitability-bichon_frise-suits');
    expect(suits).toHaveTextContent(/življenje v stanovanju/);
    expect(suits).toHaveTextContent(/družinsko življenje/);
    expect(suits).toHaveTextContent(/družino z otroki/);
    expect(suits).toHaveTextContent(/dom z drugimi ljubljenčki/);
    expect(suits).toHaveTextContent(/manj dlak/);
    const consider = screen.getByTestId('breed-suitability-bichon_frise-consider');
    expect(consider).toHaveTextContent(/česanje večkrat na teden/);
    expect(consider).toHaveTextContent(/rad ima družbo/);
    expect(consider).not.toHaveTextContent(/izpada mu dlaka/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'kodrasti');
    expect(screen.getByTestId('breed-option-bichon_frise')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-bichon_frise'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 3\.000 pri 3 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/3\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/2\.300 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'bichon_frise', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'bichon_frise' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-bichon_frise-suits');
      const consider = screen.getByTestId('breed-suitability-bichon_frise-consider');
      expect(suits).toHaveTextContent(/apartment living/);
      expect(suits).toHaveTextContent(/families with children/);
      expect(suits).toHaveTextContent(/homes with other pets/);
      expect(suits).toHaveTextContent(/less shedding/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).toHaveTextContent(/needs brushing several times a week/);
      expect(consider).toHaveTextContent(/loves company/);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-bichon_frise')).toHaveTextContent(/Bichon Frise/);
    });
  });
});
