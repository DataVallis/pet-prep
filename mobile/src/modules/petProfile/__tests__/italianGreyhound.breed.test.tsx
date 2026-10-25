/**
 * M5-R10-60: the Italian Greyhound — the breed's own expectations (runbook "Own files only",
 * David 2026-10-15): picker hints EN + SL, fallback catalogue entry (new cold_sensitive chip), search, species, chips, confirm.
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

describe('M5-R10-60 Italian Greyhound', () => {
  it('is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('italian_greyhound'))).toBe('dog');
    expect(isDefaultFreeBreed('italian_greyhound')).toBe(false);
    expect(hasGroomingRoutine('italian_greyhound')).toBe(true);
    expect(breedName('italian_greyhound')).toBe('Mali italijanski hrt');
    await inEnglish(() => {
      expect(breedName('italian_greyhound')).toBe('Italian Greyhound');
    });
  });

  it('quotes 2.000 → 6.000 (at 6 months), adult 6.000, senior 4.500 and brushing 1× a week — SL and EN', async () => {
    const D = PICKER_STRINGS.ageHints.italian_greyhound;
    expect(D.puppy).toBe('Pride star 2 meseca. 4 obroki na dan (nato 3, od 6. meseca 2); sprehod 2.000 korakov na dan, vsak teden več do 6.000 pri 6 mesecih.');
    expect(D.young).toBe('2 obroka na dan; sprehod 6.000 korakov na dan (kot odrasel pes).');
    expect(D.adult).toBe('2 obroka na dan; sprehod 6.000 korakov na dan.');
    expect(D.senior).toBe('2 obroka na dan; krajši sprehod — 4.500 korakov na dan.');
    expect(PICKER_STRINGS.breedHints.italian_greyhound).toBe('Del 12-tedenskega izziva · česanje 1× na teden');
    await inEnglish(() => {
      const E = PICKER_STRINGS.ageHints.italian_greyhound;
      expect(E.puppy).toBe('Arrives at 2 months old. 4 meals a day (then 3, and 2 from 6 months); walks of 2,000 steps a day, more each week up to 6,000 at 6 months.');
      expect(E.young).toBe('2 meals a day; walks of 6,000 steps a day (like an adult dog).');
      expect(E.adult).toBe('2 meals a day; walks of 6,000 steps a day.');
      expect(E.senior).toBe('2 meals a day; shorter walks — 4,500 steps a day.');
      expect(PICKER_STRINGS.breedHints.italian_greyhound).toBe('Part of the 12-week challenge · brushing 1× a week');
    });
  });

  it('mirrors BreedConfigsSeeder and config/breed_suitability.php in the fallback catalogue (new cold_sensitive chip)', () => {
    const ig = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'italian_greyhound');
    expect(ig).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 560 }));
    expect(ig?.search_keywords).toEqual(['italian greyhound', 'mali italijanski hrt', 'italijanski hrt', 'italijanski hrtek', 'piccolo levriero italiano', 'italian sighthound', 'iggy']);
    expect(ig?.suitability).toEqual({
      suits: ['children', 'low_shedding'],
      consider: ['fragile_older_children', 'cold_sensitive'],
    });
    expect(CONSIDER_TAGS).toContain('cold_sensitive');
  });

  it('is found by its EN / SL names, the ASCII spelling and the FCI name', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('Italian Greyhound')).toEqual(['italian_greyhound']);
    expect(find('Mali italijanski hrt')).toEqual(['italian_greyhound']);
    expect(find('italijanski hrt')).toEqual(['italian_greyhound']);
    // »italijanski« alone also finds the Cane Corso (»italijanski korzo«).
    expect(find('italijanski')).toEqual(['cane_corso', 'italian_greyhound']);
    expect(find('piccolo levriero')).toEqual(['italian_greyhound']);
  });

  it('shows its chips in the picker, quotes 6.000 / 4.500 steps and confirms as a challenge dog', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const ig = screen.getByTestId('breed-option-italian_greyhound');
    expect(ig).toHaveTextContent(/Mali italijanski hrt/);
    expect(ig).toHaveTextContent(new RegExp(PICKER_STRINGS.badgeChallenge));
    expect(ig).toHaveTextContent(/česanje 1× na teden/);
    const suits = screen.getByTestId('breed-suitability-italian_greyhound-suits');
    expect(suits).toHaveTextContent(/družino z otroki/);
    expect(suits).toHaveTextContent(/dom, kjer želite manj dlak/);
    const consider = screen.getByTestId('breed-suitability-italian_greyhound-consider');
    expect(consider).toHaveTextContent(/krhke kosti — bolje za starejše otroke/);
    expect(consider).toHaveTextContent(/tanka dlaka — občutljiv na mraz/);
    expect(consider).not.toHaveTextContent(/lahko lovi manjše živali/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'italijanski hrt');
    expect(screen.getByTestId('breed-option-italian_greyhound')).toBeTruthy();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-italian_greyhound'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 6\.000 pri 6 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/6\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/4\.500 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'italian_greyhound', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'italian_greyhound' }),
    );
  });

  it('shows the chips in English, without numbers — never "hypoallergenic"', async () => {
    await inEnglish(() => {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-italian_greyhound-suits');
      const consider = screen.getByTestId('breed-suitability-italian_greyhound-consider');
      expect(suits).toHaveTextContent(/families with children/);
      expect(suits).toHaveTextContent(/homes that prefer less shedding/);
      expect(suits).not.toHaveTextContent(/hypoallergenic/i);
      expect(consider).toHaveTextContent(/fragile bones — better with older children/);
      expect(consider).toHaveTextContent(/thin coat — feels the cold/);
      expect(suits).not.toHaveTextContent(/\d|%/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-italian_greyhound')).toHaveTextContent(/Italian Greyhound/);
    });
  });
});
