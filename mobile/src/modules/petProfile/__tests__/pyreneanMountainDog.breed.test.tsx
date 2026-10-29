/**
 * M5-R10-78: the Pyrenean Mountain Dog (FCI 137, AKC »Great Pyrenees«) — the breed's own expectations (runbook "Own files only", David 2026-10-15):
 * picker hints EN + SL, fallback catalogue entry, search, species, chips, confirm.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import PetPickerStep from '@/components/parent/PetPickerStep';
import { i18n } from '@/i18n';
import { CONSIDER_TAGS, FALLBACK_CATALOGUE, INITIAL_PICKER_CHOICE, PICKER_STRINGS, searchBreeds, type BreedCatalogue } from '@/modules/petProfile/picker';
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

const KEY = 'pyrenean_mountain_dog';

describe('M5-R10-78 Pyrenean Mountain Dog', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed(KEY))).toBe('dog');
    expect(isDefaultFreeBreed(KEY)).toBe(false);
    expect(hasGroomingRoutine(KEY)).toBe(true);
    expect(breedName(KEY)).toBe('Pirenejski gorski pes');
    await inEnglish(() => {
      expect(breedName(KEY)).toBe('Pyrenean Mountain Dog');
    });
  });

  it('quotes 2.000 → 6.000 (at 6 months), adult 6.000, senior 4.500 and brushing 2× a week — SL and EN', async () => {
    const D = PICKER_STRINGS.ageHints[KEY];
    expect(D.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 6.000 pri 6 mesecih.');
    expect(D.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(D.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(D.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints[KEY]).toBe('Del 12-tedenskega izziva · česanje 2× na teden');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints[KEY];
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 6,000 at 6 months.');
      expect(E.young).toBe('2 meals a day; walks of 6,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 6,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 4,500 steps a day.');
      expect(PICKER_STRINGS.breedHints[KEY]).toBe('Part of the 12-week challenge · brushing 2× a week');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue', () => {
    const dog = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === KEY);
    expect(dog).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 720 }));
    expect(dog?.search_keywords).toEqual(['pyrenean mountain dog', 'great pyrenees', 'pyrenees', 'pyr', 'patou', 'chien de montagne des pyrenees', 'pirenejski gorski pes', 'pirenejski pes', 'pirenejec', 'pirenejski planšar']);
    expect(dog?.suitability).toEqual({
      suits: ['children', 'large_home', 'other_pets'],
      consider: ['not_for_small_children', 'sheds', 'frequent_grooming', 'shorter_lifespan'],
    });
    expect(CONSIDER_TAGS).toContain('not_for_small_children');
    expect(CONSIDER_TAGS).toContain('shorter_lifespan');
  });

  it('is found by its EN / SL names', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('pirenejski gorski pes')).toEqual([KEY]);
    expect(find('Pirenejski')).toEqual([KEY]);
    expect(find('Great Pyrenees')).toEqual([KEY]);
    expect(find('Patou')).toEqual([KEY]);
    expect(find('Pyrenean Mountain Dog')).toEqual([KEY]);
  });

  it('shows its chips in the picker, quotes 6.000 / 4.500 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const option = screen.getByTestId(`breed-option-${KEY}`);
    expect(option).toHaveTextContent(/Pirenejski gorski pes/);
    expect(option).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(option).toHaveTextContent(/česanje 2× na teden/);
    const suits = screen.getByTestId(`breed-suitability-${KEY}-suits`);
    const consider = screen.getByTestId(`breed-suitability-${KEY}-consider`);
    expect(suits).toHaveTextContent(/družino z otroki/);
    expect(suits).toHaveTextContent(/veliko hišo z vrtom/);
    expect(suits).toHaveTextContent(/dom z drugimi ljubljenčki/);
    expect(consider).toHaveTextContent(/velik pes — ni za dom z majhnimi otroki/);
    expect(consider).toHaveTextContent(/izpada mu dlaka/);
    expect(consider).toHaveTextContent(/potrebuje česanje večkrat na teden/);
    expect(consider).toHaveTextContent(/krajša življenjska doba/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'pirenejski');
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
      expect(suits).toHaveTextContent(/families with children/);
      expect(suits).toHaveTextContent(/a large house and garden/);
      expect(consider).toHaveTextContent(/large dog — not for homes with small children/);
      expect(consider).toHaveTextContent(/shorter lifespan/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId(`breed-option-${KEY}`)).toHaveTextContent(/Pyrenean Mountain Dog/);
    });
  });
});
