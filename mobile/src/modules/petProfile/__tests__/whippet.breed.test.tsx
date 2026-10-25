/**
 * M5-R10-54: the Whippet — the breed's own expectations (runbook "Own files only",
 * David 2026-10-15): picker hints EN + SL, fallback catalogue entry, search, species, chips, confirm.
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

describe('M5-R10-54 Whippet', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('whippet'))).toBe('dog');
    expect(isDefaultFreeBreed('whippet')).toBe(false);
    expect(hasGroomingRoutine('whippet')).toBe(true);
    expect(breedName('whippet')).toBe('Mali angleški hrt');
    await inEnglish(() => {
      expect(breedName('whippet')).toBe('Whippet');
    });
  });

  it('quotes 2.000 → 6.000 (at 6 months), young / adult 6.000, senior 4.500 and brushing 1× a week — SL and EN', async () => {
    const D = PICKER_STRINGS.ageHints.whippet;
    expect(D.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 6.000 pri 6 mesecih.');
    expect(D.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(D.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(D.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.whippet).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.whippet;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 6,000 at 6 months.');
      expect(E.young).toBe('2 meals a day; walks of 6,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 6,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 4,500 steps a day.');
      expect(PICKER_STRINGS.breedHints.whippet).toBe('Part of the 12-week challenge · brushing 1× a week');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue (new sensitive_older_children tag)', () => {
    const wpt = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'whippet');
    expect(wpt).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 500 }));
    expect(wpt?.search_keywords).toEqual(['whippet', 'mali angleški hrt', 'mali angleski hrt', 'angleški hrt', 'angleski hrt', 'vipet', 'hrt', 'snap dog']);
    expect(wpt?.suitability).toEqual({
      suits: ['family_pet', 'children'],
      consider: ['sensitive_older_children', 'needs_company', 'chases_small_pets'],
    });
    expect(CONSIDER_TAGS).toContain('sensitive_older_children');
  });

  it('is found by its EN / SL names', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Whippet')).toEqual(['whippet']);
    expect(find('Mali angleški hrt')).toEqual(['whippet']);
    expect(find('mali angleski hrt')).toEqual(['whippet']);
    expect(find('vipet')).toEqual(['whippet']);
  });

  it('shows its chips in the picker, quotes 6.000 / 4.500 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const wpt = screen.getByTestId('breed-option-whippet');
    expect(wpt).toHaveTextContent(/Mali angleški hrt/);
    expect(wpt).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(wpt).toHaveTextContent(/česanje 1× na teden/);
    const suits = screen.getByTestId('breed-suitability-whippet-suits');
    expect(suits).toHaveTextContent(/družinsko življenje/);
    expect(suits).toHaveTextContent(/družino z otroki/);
    const consider = screen.getByTestId('breed-suitability-whippet-consider');
    expect(consider).toHaveTextContent(/občutljiv pes — bolje za starejše otroke/);
    expect(consider).toHaveTextContent(/rad ima družbo/);
    expect(consider).toHaveTextContent(/močan lovski nagon/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'whippet');
    expect(screen.getByTestId('breed-option-whippet')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-whippet'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 6\.000 pri 6 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/6\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/4\.500 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'whippet', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'whippet' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-whippet-suits');
      const consider = screen.getByTestId('breed-suitability-whippet-consider');
      expect(suits).toHaveTextContent(/family life/);
      expect(suits).toHaveTextContent(/families with children/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).toHaveTextContent(/sensitive dog — better with older children/);
      expect(consider).toHaveTextContent(/loves company/);
      expect(consider).toHaveTextContent(/may chase small pets/);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-whippet')).toHaveTextContent(/Whippet/);
    });
  });
});
