/**
 * M5-R10-47: the Rhodesian Ridgeback — the breed's own expectations (runbook "Own files only",
 * David 2026-10-15): picker hints EN + SL, fallback catalogue entry, search, species, chips (no new tag),
 * confirm.
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

describe('M5-R10-47 Rhodesian Ridgeback', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('rhodesian_ridgeback'))).toBe('dog');
    expect(isDefaultFreeBreed('rhodesian_ridgeback')).toBe(false);
    expect(hasGroomingRoutine('rhodesian_ridgeback')).toBe(true);
    expect(breedName('rhodesian_ridgeback')).toBe('Rodezijski ridgeback');
    await inEnglish(() => {
      expect(breedName('rhodesian_ridgeback')).toBe('Rhodesian Ridgeback');
    });
  });

  it('quotes 2.000 → 12.000 (at 12 months), young 9.000, adult 12.000, senior 9.000 and brushing once a week — SL and EN', async () => {
    const P = PICKER_STRINGS.ageHints.rhodesian_ridgeback;
    expect(P.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(P.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(P.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(P.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.rhodesian_ridgeback).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.rhodesian_ridgeback;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 12,000 at 12 months.');
      expect(E.young).toBe('2 meals a day; walks of 9,000 steps a day, more each week up to 12,000 at 12 months.');
      expect(E.adult).toBe('2 meals a day; walks of 12,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 9,000 steps a day.');
      expect(PICKER_STRINGS.breedHints.rhodesian_ridgeback).toBe('Part of the 12-week challenge · brushing 1× a week');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue (no new tag)', () => {
    const ridgeback = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'rhodesian_ridgeback');
    expect(ridgeback).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 430 }));
    expect(ridgeback?.search_keywords).toEqual(['rhodesian ridgeback', 'ridgeback', 'rodezijski ridgeback', 'rodezijski', 'african lion dog']);
    expect(ridgeback?.suitability).toEqual({
      suits: ['active_family', 'family_pet', 'large_home'],
      consider: ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets'],
    });
  });

  it('comes right after the Shiba Inu in the fallback catalogue', () => {
    const keys = FALLBACK_CATALOGUE.breeds.map((b) => b.breed);
    expect(keys.indexOf('rhodesian_ridgeback')).toBe(keys.indexOf('shiba_inu') + 1);
  });

  it('is found by its EN / SL names', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Rhodesian Ridgeback')).toEqual(['rhodesian_ridgeback']);
    expect(find('ridgeback')).toEqual(['rhodesian_ridgeback']);
    expect(find('rodezijski ridgeback')).toEqual(['rhodesian_ridgeback']);
    expect(find('Rodezijski')).toEqual(['rhodesian_ridgeback']);
    expect(find('African Lion Dog')).toEqual(['rhodesian_ridgeback']);
  });

  it('shows its chips in the picker, quotes 12.000 / 9.000 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const ridgeback = screen.getByTestId('breed-option-rhodesian_ridgeback');
    expect(ridgeback).toHaveTextContent(/Rodezijski ridgeback/);
    expect(ridgeback).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(ridgeback).toHaveTextContent(/česanje 1× na teden/);
    const suits = screen.getByTestId('breed-suitability-rhodesian_ridgeback-suits');
    expect(suits).toHaveTextContent(/aktivno družino/);
    expect(suits).toHaveTextContent(/družinsko življenje/);
    expect(suits).toHaveTextContent(/veliko hišo z vrtom/);
    const consider = screen.getByTestId('breed-suitability-rhodesian_ridgeback-consider');
    expect(consider).toHaveTextContent(/vsak dan potrebuje veliko gibanja/);
    expect(consider).toHaveTextContent(/potrebuje izkušenega lastnika/);
    expect(consider).toHaveTextContent(/ni za dom z majhnimi otroki/);
    expect(consider).toHaveTextContent(/rad ima družbo/);
    expect(consider).toHaveTextContent(/izpada mu dlaka/);
    expect(consider).toHaveTextContent(/lahko lovi manjše živali/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'ridgeback');
    expect(screen.getByTestId('breed-option-rhodesian_ridgeback')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-rhodesian_ridgeback'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 12\.000 pri 12 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/12\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/9\.000 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'rhodesian_ridgeback', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'rhodesian_ridgeback' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-rhodesian_ridgeback-suits');
      const consider = screen.getByTestId('breed-suitability-rhodesian_ridgeback-consider');
      expect(suits).toHaveTextContent(/an active family/);
      expect(suits).toHaveTextContent(/family life/);
      expect(suits).toHaveTextContent(/a large house and garden/);
      expect(consider).toHaveTextContent(/needs lots of exercise every day/);
      expect(consider).toHaveTextContent(/needs an experienced owner/);
      expect(consider).toHaveTextContent(/not for homes with small children/);
      expect(consider).toHaveTextContent(/loves company/);
      expect(consider).toHaveTextContent(/sheds/);
      expect(consider).toHaveTextContent(/may chase small pets/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).not.toHaveTextContent(/hypoallergenic/i);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-rhodesian_ridgeback')).toHaveTextContent(/Rhodesian Ridgeback/);
    });
  });
});
