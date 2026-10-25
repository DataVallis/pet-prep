/**
 * M5-R10-55: the Wirehaired Pointing Griffon — the breed's own expectations (runbook "Own files only",
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

describe('M5-R10-55 Wirehaired Pointing Griffon', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('wirehaired_pointing_griffon'))).toBe('dog');
    expect(isDefaultFreeBreed('wirehaired_pointing_griffon')).toBe(false);
    expect(hasGroomingRoutine('wirehaired_pointing_griffon')).toBe(true);
    expect(breedName('wirehaired_pointing_griffon')).toBe('Francoski ostrodlaki ptičar');
    await inEnglish(() => {
      expect(breedName('wirehaired_pointing_griffon')).toBe('Wirehaired Pointing Griffon');
    });
  });

  it('quotes 2.000 → 12.000 (at 12 months), adult 12.000, senior 9.000 and brushing 1× a week — SL and EN', async () => {
    const D = PICKER_STRINGS.ageHints.wirehaired_pointing_griffon;
    expect(D.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(D.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(D.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(D.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.wirehaired_pointing_griffon).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.wirehaired_pointing_griffon;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 12,000 at 12 months.');
      expect(E.young).toBe('2 meals a day; walks of 9,000 steps a day, more each week up to 12,000 at 12 months.');
      expect(E.adult).toBe('2 meals a day; walks of 12,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 9,000 steps a day.');
      expect(PICKER_STRINGS.breedHints.wirehaired_pointing_griffon).toBe('Part of the 12-week challenge · brushing 1× a week');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue', () => {
    const wpg = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'wirehaired_pointing_griffon');
    expect(wpg).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 510 }));
    expect(wpg?.search_keywords).toEqual(['wirehaired pointing griffon', 'korthals griffon', 'korthals', 'griffon', 'griff', 'francoski ostrodlaki ptičar', 'francoski ostrodlaki pticar', 'ostrodlaki ptičar', 'ostrodlaki pticar', 'korthalsov grifon', 'grifon']);
    expect(wpg?.suitability).toEqual({
      suits: ['active_family', 'family_pet', 'children', 'large_home'],
      consider: ['long_daily_exercise', 'chases_small_pets'],
    });
  });

  it('is found by its EN / SL names and the Korthals name', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Wirehaired Pointing Griffon')).toEqual(['wirehaired_pointing_griffon']);
    expect(find('Korthals Griffon')).toEqual(['wirehaired_pointing_griffon']);
    expect(find('Francoski ostrodlaki ptičar')).toEqual(['wirehaired_pointing_griffon']);
    // M5-R10-65: »ostrodlaki ptičar« also finds the German Wirehaired Pointer (»nemški ostrodlaki ptičar«).
    expect(find('ostrodlaki pticar')).toEqual(['wirehaired_pointing_griffon', 'german_wirehaired_pointer']);
    expect(find('grifon')).toEqual(['wirehaired_pointing_griffon']);
  });

  it('shows its chips in the picker, quotes 12.000 / 9.000 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const wpg = screen.getByTestId('breed-option-wirehaired_pointing_griffon');
    expect(wpg).toHaveTextContent(/Francoski ostrodlaki ptičar/);
    expect(wpg).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(wpg).toHaveTextContent(/česanje 1× na teden/);
    const suits = screen.getByTestId('breed-suitability-wirehaired_pointing_griffon-suits');
    expect(suits).toHaveTextContent(/aktivno družino/);
    expect(suits).toHaveTextContent(/družinsko življenje/);
    expect(suits).toHaveTextContent(/družino z otroki/);
    expect(suits).toHaveTextContent(/veliko hišo z vrtom/);
    const consider = screen.getByTestId('breed-suitability-wirehaired_pointing_griffon-consider');
    expect(consider).toHaveTextContent(/vsak dan potrebuje veliko gibanja/);
    expect(consider).toHaveTextContent(/močan lovski nagon/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'korthals');
    expect(screen.getByTestId('breed-option-wirehaired_pointing_griffon')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-wirehaired_pointing_griffon'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 12\.000 pri 12 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/12\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/9\.000 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'wirehaired_pointing_griffon', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'wirehaired_pointing_griffon' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-wirehaired_pointing_griffon-suits');
      const consider = screen.getByTestId('breed-suitability-wirehaired_pointing_griffon-consider');
      expect(suits).toHaveTextContent(/an active family/);
      expect(suits).toHaveTextContent(/family life/);
      expect(suits).toHaveTextContent(/families with children/);
      expect(suits).toHaveTextContent(/a large house and garden/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).toHaveTextContent(/needs lots of exercise every day/);
      expect(consider).toHaveTextContent(/may chase small pets/);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-wirehaired_pointing_griffon')).toHaveTextContent(/Wirehaired Pointing Griffon/);
    });
  });
});
