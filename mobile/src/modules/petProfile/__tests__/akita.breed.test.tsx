/**
 * M5-R10-63: the Akita (American type, FCI 344) — the breed's own expectations (runbook "Own files only",
 * David 2026-10-15): picker hints EN + SL, fallback catalogue entry, search, species, chips, confirm.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import PetPickerStep from '@/components/parent/PetPickerStep';
import { i18n } from '@/i18n';
import { FALLBACK_CATALOGUE, INITIAL_PICKER_CHOICE, PICKER_STRINGS, searchBreeds, type BreedCatalogue } from '@/modules/petProfile/picker';
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

describe('M5-R10-63 Akita', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('akita'))).toBe('dog');
    expect(isDefaultFreeBreed('akita')).toBe(false);
    expect(hasGroomingRoutine('akita')).toBe(true);
    expect(breedName('akita')).toBe('Ameriška akita');
    await inEnglish(() => {
      expect(breedName('akita')).toBe('Akita');
    });
  });

  it('quotes 2.000 → 12.000 (at 12 months), adult 12.000, senior 9.000 and brushing once a week — SL and EN', async () => {
    const D = PICKER_STRINGS.ageHints.akita;
    expect(D.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(D.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(D.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(D.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.akita).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.akita;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 12,000 at 12 months.');
      expect(E.young).toBe('2 meals a day; walks of 9,000 steps a day, more each week up to 12,000 at 12 months.');
      expect(E.adult).toBe('2 meals a day; walks of 12,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 9,000 steps a day.');
      expect(PICKER_STRINGS.breedHints.akita).toBe('Part of the 12-week challenge · brushing 1× a week');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue', () => {
    const ak = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'akita');
    expect(ak).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 590 }));
    expect(ak?.search_keywords).toEqual(['akita', 'american akita', 'ameriška akita', 'ameriska akita', 'great japanese dog']);
    expect(ak?.suitability).toEqual({
      suits: ['active_family'],
      consider: ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets'],
    });
  });

  it('is found by its EN name, the Slovenian (with and without the caron) and the FCI name', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Akita')).toEqual(['akita']);
    expect(find('ameriška akita')).toEqual(['akita']);
    expect(find('ameriska akita')).toEqual(['akita']);
    expect(find('American Akita')).toEqual(['akita']);
  });

  it('shows its chips in the picker, quotes 12.000 / 9.000 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const ak = screen.getByTestId('breed-option-akita');
    expect(ak).toHaveTextContent(/Ameriška akita/);
    expect(ak).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(ak).toHaveTextContent(/česanje 1× na teden/);
    const suits = screen.getByTestId('breed-suitability-akita-suits');
    expect(suits).toHaveTextContent(/aktivno družino/);
    expect(suits).not.toHaveTextContent(/veliko hišo z vrtom/);
    expect(suits).not.toHaveTextContent(/družino z otroki/);
    const consider = screen.getByTestId('breed-suitability-akita-consider');
    expect(consider).toHaveTextContent(/vsak dan potrebuje veliko gibanja/);
    expect(consider).toHaveTextContent(/potrebuje izkušenega lastnika/);
    expect(consider).toHaveTextContent(/ni za dom z majhnimi otroki/);
    expect(consider).toHaveTextContent(/rad ima družbo/);
    expect(consider).toHaveTextContent(/izpada mu dlaka/);
    expect(consider).toHaveTextContent(/lahko lovi manjše živali/);
    expect(consider).not.toHaveTextContent(/krajša življenjska doba/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'akita');
    expect(screen.getByTestId('breed-option-akita')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-akita'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 12\.000 pri 12 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/12\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/9\.000 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'akita', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'akita' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-akita-suits');
      const consider = screen.getByTestId('breed-suitability-akita-consider');
      expect(suits).toHaveTextContent(/an active family/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).toHaveTextContent(/loves company/);
      expect(consider).toHaveTextContent(/strong prey drive — may chase small pets/);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-akita')).toHaveTextContent(/Akita/);
    });
  });
});
