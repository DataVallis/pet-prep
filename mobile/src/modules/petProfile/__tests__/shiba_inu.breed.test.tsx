/**
 * M5-R10-46: the Shiba Inu — the breed's own expectations (runbook "Own files only",
 * David 2026-10-15): picker hints EN + SL, fallback catalogue entry, search, species, chips (incl. the new
 * independent_experienced_owner key), confirm.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import PetPickerStep from '@/components/parent/PetPickerStep';
import { i18n } from '@/i18n';
import {
  CONSIDER_TAGS,
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

describe('M5-R10-46 Shiba Inu', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('shiba_inu'))).toBe('dog');
    expect(isDefaultFreeBreed('shiba_inu')).toBe(false);
    expect(hasGroomingRoutine('shiba_inu')).toBe(true);
    expect(breedName('shiba_inu')).toBe('Šiba inu');
    await inEnglish(() => {
      expect(breedName('shiba_inu')).toBe('Shiba Inu');
    });
  });

  it('quotes 2.000 → 6.000 (at 6 months), young / adult 6.000, senior 4.500 and brushing once a week — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.shiba_inu;
    expect(P.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 6.000 pri 6 mesecih.');
    expect(P.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(P.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.shiba_inu).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.shiba_inu;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 6,000 at 6 months.');
      expect(E.young).toBe('2 meals a day; walks of 6,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 6,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 4,500 steps a day.');
      expect(PICKER_STRINGS.breedHints.shiba_inu).toBe('Part of the 12-week challenge · brushing 1× a week');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue (new consider key)', () => {
    expect(CONSIDER_TAGS).toContain('independent_experienced_owner');
    const shiba = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'shiba_inu');
    expect(shiba).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 420 }));
    expect(shiba?.search_keywords).toEqual(['shiba inu', 'shiba', 'japanese shiba inu', 'šiba inu', 'šiba', 'siba inu', 'siba', 'japonski šiba inu', 'japonski siba inu']);
    expect(shiba?.suitability).toEqual({
      suits: [],
      consider: ['independent_experienced_owner', 'older_children', 'needs_mental_stimulation', 'sheds'],
    });
  });

  it('is found by its EN / SL names (with and without diacritics)', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Shiba Inu')).toEqual(['shiba_inu']);
    expect(find('Japanese Shiba Inu')).toEqual(['shiba_inu']);
    expect(find('šiba inu')).toEqual(['shiba_inu']);
    expect(find('siba')).toEqual(['shiba_inu']);
    expect(find('shiba')).toEqual(['shiba_inu']);
  });

  it('shows only consider chips in the picker, quotes 6.000 / 4.500 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const shiba = screen.getByTestId('breed-option-shiba_inu');
    expect(shiba).toHaveTextContent(/Šiba inu/);
    expect(shiba).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(shiba).toHaveTextContent(/česanje 1× na teden/);
    // No sourced "suits" tag → no suits group at all.
    expect(screen.queryByTestId('breed-suitability-shiba_inu-suits')).toBeNull();
    const consider = screen.getByTestId('breed-suitability-shiba_inu-consider');
    expect(consider).toHaveTextContent(/samostojen in trmast — najbolje z izkušenim lastnikom/);
    expect(consider).toHaveTextContent(/bolje za starejše otroke/);
    expect(consider).toHaveTextContent(/potrebuje miselne izzive/);
    expect(consider).toHaveTextContent(/izpada mu dlaka/);
    expect(consider).not.toHaveTextContent(/močan pes/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'šiba');
    expect(screen.getByTestId('breed-option-shiba_inu')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-shiba_inu'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 6\.000 pri 6 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/6\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/4\.500 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'shiba_inu', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'shiba_inu' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic" and never "powerful dog"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const consider = screen.getByTestId('breed-suitability-shiba_inu-consider');
      expect(consider).toHaveTextContent(/independent and stubborn — best with an experienced owner/);
      expect(consider).toHaveTextContent(/better with older children/);
      expect(consider).toHaveTextContent(/needs mental challenges/);
      expect(consider).toHaveTextContent(/sheds/);
      expect(consider).not.toHaveTextContent(/powerful dog/);
      expect(consider).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-shiba_inu')).toHaveTextContent(/Shiba Inu/);
    });
  });
});
