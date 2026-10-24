/**
 * M5-R10-61: the Giant Schnauzer — the breed's own expectations (runbook "Own files only",
 * David 2026-10-15): picker hints EN + SL, fallback catalogue entry, search, species, chips, confirm.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import PetPickerStep from '@/components/parent/PetPickerStep';
import { i18n } from '@/i18n';
import { FALLBACK_CATALOGUE, INITIAL_PICKER_CHOICE, PICKER_STRINGS, searchBreeds, type BreedCatalogue } from '@/modules/petProfile/picker';
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

describe('M5-R10-61 Giant Schnauzer', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('giant_schnauzer'))).toBe('dog');
    expect(isDefaultFreeBreed('giant_schnauzer')).toBe(false);
    expect(hasGroomingRoutine('giant_schnauzer')).toBe(true);
    expect(breedName('giant_schnauzer')).toBe('Veliki šnavcer');
    await inEnglish(() => {
      expect(breedName('giant_schnauzer')).toBe('Giant Schnauzer');
    });
  });

  it('quotes 2.000 → 12.000 (at 12 months), adult 12.000, senior 9.000 and brushing every day — SL and EN', async () => {
    const D = PICKER_STRINGS.ageHints.giant_schnauzer;
    expect(D.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(D.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(D.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(D.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.giant_schnauzer).toBe('Del 12-tedenskega izziva · česanje vsak dan');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.giant_schnauzer;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 12,000 at 12 months.');
      expect(E.young).toBe('2 meals a day; walks of 9,000 steps a day, more each week up to 12,000 at 12 months.');
      expect(E.adult).toBe('2 meals a day; walks of 12,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 9,000 steps a day.');
      expect(PICKER_STRINGS.breedHints.giant_schnauzer).toBe('Part of the 12-week challenge · brushing every day');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue', () => {
    const gs = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'giant_schnauzer');
    expect(gs).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 570 }));
    expect(gs?.search_keywords).toEqual(['giant schnauzer', 'veliki šnavcer', 'veliki snavcer', 'riesenschnauzer', 'riesen', 'munich schnauzer']);
    expect(gs?.suitability).toEqual({
      suits: ['active_family', 'family_pet', 'large_home', 'low_shedding'],
      consider: ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'frequent_grooming'],
    });
  });

  it('is found by its EN / SL names, the ASCII spelling and the German name', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Giant Schnauzer')).toEqual(['giant_schnauzer']);
    expect(find('Veliki šnavcer')).toEqual(['giant_schnauzer']);
    expect(find('veliki snavcer')).toEqual(['giant_schnauzer']);
    expect(find('Riesenschnauzer')).toEqual(['giant_schnauzer']);
    // »šnavcer« alone also finds the Miniature Schnauzer (»pritlikavi šnavcer«).
    expect(find('šnavcer')).toEqual(['miniature_schnauzer', 'giant_schnauzer']);
  });

  it('shows its chips in the picker, quotes 12.000 / 9.000 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const gs = screen.getByTestId('breed-option-giant_schnauzer');
    expect(gs).toHaveTextContent(/Veliki šnavcer/);
    expect(gs).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(gs).toHaveTextContent(/česanje vsak dan/);
    const suits = screen.getByTestId('breed-suitability-giant_schnauzer-suits');
    expect(suits).toHaveTextContent(/aktivno družino/);
    expect(suits).toHaveTextContent(/družinsko življenje/);
    expect(suits).toHaveTextContent(/veliko hišo z vrtom/);
    expect(suits).toHaveTextContent(/dom, kjer želite manj dlak/);
    expect(suits).not.toHaveTextContent(/družino z otroki/);
    const consider = screen.getByTestId('breed-suitability-giant_schnauzer-consider');
    expect(consider).toHaveTextContent(/vsak dan potrebuje veliko gibanja/);
    expect(consider).toHaveTextContent(/potrebuje izkušenega lastnika/);
    expect(consider).toHaveTextContent(/ni za dom z majhnimi otroki/);
    expect(consider).toHaveTextContent(/rad ima družbo/);
    expect(consider).toHaveTextContent(/potrebuje česanje večkrat na teden/);
    expect(consider).not.toHaveTextContent(/lahko lovi manjše živali/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'veliki šnavcer');
    expect(screen.getByTestId('breed-option-giant_schnauzer')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-giant_schnauzer'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 12\.000 pri 12 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/12\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/9\.000 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'giant_schnauzer', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'giant_schnauzer' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-giant_schnauzer-suits');
      const consider = screen.getByTestId('breed-suitability-giant_schnauzer-consider');
      expect(suits).toHaveTextContent(/an active family/);
      expect(suits).toHaveTextContent(/homes that prefer less shedding/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).toHaveTextContent(/large dog — not for homes with small children/);
      expect(consider).toHaveTextContent(/loves company/);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-giant_schnauzer')).toHaveTextContent(/Giant Schnauzer/);
    });
  });
});
