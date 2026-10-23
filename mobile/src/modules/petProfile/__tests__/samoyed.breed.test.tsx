/**
 * M5-R10-57: the Samoyed — the breed's own expectations (runbook "Own files only",
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

describe('M5-R10-57 Samoyed', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('samoyed'))).toBe('dog');
    expect(isDefaultFreeBreed('samoyed')).toBe(false);
    expect(hasGroomingRoutine('samoyed')).toBe(true);
    expect(breedName('samoyed')).toBe('Samojed');
    await inEnglish(() => {
      expect(breedName('samoyed')).toBe('Samoyed');
    });
  });

  it('quotes 2.000 → 12.000 (at 12 months), adult 12.000, senior 9.000 and brushing every day — SL and EN', async () => {
    const D = PICKER_STRINGS.ageHints.samoyed;
    expect(D.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(D.young).toBe('2 obroka na dan; sprehod 9.000 korakov na dan, vsak teden več do 12.000 pri 12 mesecih.');
    expect(D.adult).toBe('2 obroka na dan; sprehod 12.000 korakov na dan.');
    expect(D.senior).toBe('2 obroka na dan; krajši sprehod — 9.000 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.samoyed).toBe('Del 12-tedenskega izziva · česanje vsak dan');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.samoyed;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 12,000 at 12 months.');
      expect(E.young).toBe('2 meals a day; walks of 9,000 steps a day, more each week up to 12,000 at 12 months.');
      expect(E.adult).toBe('2 meals a day; walks of 12,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 9,000 steps a day.');
      expect(PICKER_STRINGS.breedHints.samoyed).toBe('Part of the 12-week challenge · brushing every day');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue (new heat_sensitive tag)', () => {
    const sam = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'samoyed');
    expect(sam).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 530 }));
    expect(sam?.search_keywords).toEqual(['samoyed', 'samojed', 'sammy', 'samoyede', 'samojede', 'samojedski pes', 'samoyed dog']);
    expect(sam?.suitability).toEqual({
      suits: ['active_family', 'family_pet', 'children'],
      consider: ['long_daily_exercise', 'needs_mental_stimulation', 'needs_company', 'secure_fencing', 'may_herd_children', 'sheds', 'frequent_grooming', 'heat_sensitive', 'chases_small_pets'],
    });
    expect(CONSIDER_TAGS).toContain('heat_sensitive');
  });

  it('is found by its EN / SL names and the nickname Sammy', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Samoyed')).toEqual(['samoyed']);
    expect(find('Samojed')).toEqual(['samoyed']);
    expect(find('sammy')).toEqual(['samoyed']);
  });

  it('shows its chips in the picker, quotes 12.000 / 9.000 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const sam = screen.getByTestId('breed-option-samoyed');
    expect(sam).toHaveTextContent(/Samojed/);
    expect(sam).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(sam).toHaveTextContent(/česanje vsak dan/);
    const suits = screen.getByTestId('breed-suitability-samoyed-suits');
    expect(suits).toHaveTextContent(/aktivno družino/);
    expect(suits).toHaveTextContent(/družinsko življenje/);
    expect(suits).toHaveTextContent(/družino z otroki/);
    const consider = screen.getByTestId('breed-suitability-samoyed-consider');
    expect(consider).toHaveTextContent(/vsak dan potrebuje veliko gibanja/);
    expect(consider).toHaveTextContent(/potrebuje miselne izzive/);
    expect(consider).toHaveTextContent(/rad ima družbo/);
    expect(consider).toHaveTextContent(/visoko ograjo/);
    expect(consider).toHaveTextContent(/»pase« otroke/);
    expect(consider).toHaveTextContent(/izpada mu dlaka/);
    expect(consider).toHaveTextContent(/česanje večkrat na teden/);
    expect(consider).toHaveTextContent(/gost kožuh — slabo prenaša vročino/);
    expect(consider).toHaveTextContent(/močan lovski nagon/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'samojed');
    expect(screen.getByTestId('breed-option-samoyed')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-samoyed'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 12\.000 pri 12 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/12\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/9\.000 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'samoyed', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'samoyed' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-samoyed-suits');
      const consider = screen.getByTestId('breed-suitability-samoyed-consider');
      expect(suits).toHaveTextContent(/an active family/);
      expect(suits).toHaveTextContent(/family life/);
      expect(suits).toHaveTextContent(/families with children/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).toHaveTextContent(/needs lots of exercise every day/);
      expect(consider).toHaveTextContent(/thick coat — struggles in hot weather/);
      expect(consider).toHaveTextContent(/may chase small pets/);
      expect(consider).toHaveTextContent(/sheds/);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-samoyed')).toHaveTextContent(/Samoyed/);
    });
  });
});
