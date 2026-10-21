/**
 * M5-R10-52: the Dalmatian — the breed's own expectations (runbook "Own files only",
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

describe('M5-R10-52 Dalmatian', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('dalmatian'))).toBe('dog');
    expect(isDefaultFreeBreed('dalmatian')).toBe(false);
    expect(hasGroomingRoutine('dalmatian')).toBe(true);
    expect(breedName('dalmatian')).toBe('Dalmatinec');
    await inEnglish(() => {
      expect(breedName('dalmatian')).toBe('Dalmatian');
    });
  });

  it('quotes 2.000 → 12.000 (at 12 months), young 9.000 → 12.000, adult 12.000, senior 9.000 and brushing 1× a week — SL and EN', async () => {
    const D = PICKER_STRINGS.ageHints.dalmatian;
    expect(D.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(D.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(D.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(D.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.dalmatian).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.dalmatian;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 12,000 at 12 months.');
      expect(E.young).toBe('2 meals a day; walks of 9,000 steps a day, more each week up to 12,000 at 12 months.');
      expect(E.adult).toBe('2 meals a day; walks of 12,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 9,000 steps a day.');
      expect(PICKER_STRINGS.breedHints.dalmatian).toBe('Part of the 12-week challenge · brushing 1× a week');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue (no new tag)', () => {
    const dal = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'dalmatian');
    expect(dal).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 480 }));
    expect(dal?.search_keywords).toEqual(['dalmatian', 'dalmatinec', 'dalmatinski pas', 'dalmatiner', 'dalmatinka', 'carriage dog', 'firehouse dog', 'dalmatin']);
    expect(dal?.suitability).toEqual({
      suits: ['active_family', 'large_home'],
      consider: ['long_daily_exercise', 'not_for_small_children', 'needs_company', 'sheds'],
    });
  });

  it('is found by its EN / SL names and nicknames', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Dalmatian')).toEqual(['dalmatian']);
    expect(find('Dalmatinec')).toEqual(['dalmatian']);
    expect(find('dalmatinski pas')).toEqual(['dalmatian']);
    expect(find('carriage dog')).toEqual(['dalmatian']);
  });

  it('shows its chips in the picker, quotes 12.000 / 9.000 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const dal = screen.getByTestId('breed-option-dalmatian');
    expect(dal).toHaveTextContent(/Dalmatinec/);
    expect(dal).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(dal).toHaveTextContent(/česanje 1× na teden/);
    const suits = screen.getByTestId('breed-suitability-dalmatian-suits');
    expect(suits).toHaveTextContent(/aktivno družino/);
    expect(suits).toHaveTextContent(/veliko hišo z vrtom/);
    const consider = screen.getByTestId('breed-suitability-dalmatian-consider');
    expect(consider).toHaveTextContent(/veliko gibanja/);
    expect(consider).toHaveTextContent(/ni za dom z majhnimi otroki/);
    expect(consider).toHaveTextContent(/rad ima družbo/);
    expect(consider).toHaveTextContent(/izpada mu dlaka/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'dalmatin');
    expect(screen.getByTestId('breed-option-dalmatian')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-dalmatian'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 12\.000 pri 12 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/12\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/9\.000 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'dalmatian', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'dalmatian' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-dalmatian-suits');
      const consider = screen.getByTestId('breed-suitability-dalmatian-consider');
      expect(suits).toHaveTextContent(/an active family/);
      expect(suits).toHaveTextContent(/a large house and garden/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).toHaveTextContent(/needs lots of exercise every day/);
      expect(consider).toHaveTextContent(/not for homes with small children/);
      expect(consider).toHaveTextContent(/loves company/);
      expect(consider).toHaveTextContent(/sheds/);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-dalmatian')).toHaveTextContent(/Dalmatian/);
    });
  });
});
