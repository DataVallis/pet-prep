/**
 * M5-R06-02: species-aware picker — species step, server breed list with search,
 * badges, locked breeds, summary.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import PetPickerStep from '@/components/parent/PetPickerStep';
import {
  FALLBACK_CATALOGUE,
  INITIAL_PICKER_CHOICE,
  PICKER_STRINGS as PICKER,
  pickerText,
  readBreedCatalogue,
  type BreedCatalogue,
} from '@/modules/petProfile/picker';

function entry(breed: string, species: string, premium: boolean, sort_order: number, keywords: string[]) {
  return {
    breed,
    slug: breed,
    species,
    premium,
    free_plan_allowed: !premium,
    challenge_allowed: premium,
    label_key: `breeds.${breed}`,
    search_keywords: keywords,
    sort_order,
  };
}

const BOTH = readBreedCatalogue({
  species: ['dog', 'cat'],
  breeds: [
    entry('mutt', 'dog', false, 0, ['mešanček', 'mesancek']),
    entry('border_collie', 'dog', true, 10, ['border collie', 'koli']),
    entry('domestic_cat', 'cat', false, 0, ['domača mačka', 'mešanka']),
    entry('maine_coon', 'cat', true, 10, ['maine coon', 'mejnkun']),
  ],
}) as BreedCatalogue;

function renderPicker(catalogue: BreedCatalogue | null, onConfirm = jest.fn(), onBack = jest.fn()) {
  render(
    <PetPickerStep childName="Maja" catalogue={catalogue} initial={INITIAL_PICKER_CHOICE} onConfirm={onConfirm} onBack={onBack} />,
  );
  return { onConfirm, onBack };
}

describe('PetPickerStep', () => {
  it('only one species (cats hidden): no species step — straight to the dog picker, wording unchanged', () => {
    renderPicker(FALLBACK_CATALOGUE);
    expect(screen.queryByTestId('species-picker')).toBeNull();
    expect(screen.getByText(PICKER.title('Maja'))).toBeTruthy();
    expect(screen.queryByTestId('picker-change-species')).toBeNull();
    expect(screen.queryByTestId('breed-option-domestic_cat')).toBeNull();
  });

  it('two species: big tiles first, nothing preselected; the cat leads to the cat breeds', () => {
    renderPicker(BOTH);
    expect(screen.getByTestId('species-picker')).toBeTruthy();
    expect(screen.getByText(PICKER.speciesTitle('Maja'))).toBeTruthy();
    expect(screen.getByTestId('species-option-dog').props.accessibilityState).toEqual({ checked: false });
    expect(screen.getByTestId('species-option-cat').props.accessibilityState).toEqual({ checked: false });
    expect(screen.queryByTestId('dog-picker-confirm')).toBeNull();

    fireEvent.press(screen.getByTestId('species-option-cat'));
    expect(screen.getByText(PICKER.cat.title('Maja'))).toBeTruthy();
    expect(screen.getByTestId('breed-option-domestic_cat')).toBeTruthy();
    expect(screen.getByTestId('breed-option-maine_coon')).toBeTruthy();
    expect(screen.queryByTestId('breed-option-mutt')).toBeNull();
    // Cat wording: kitten ages, draft hints from the catalogue breed.
    expect(screen.getByText('Mucek')).toBeTruthy();
    expect(screen.getByText('Kratka dlaka · brezplačna')).toBeTruthy();
    expect(screen.getByText('Dolga dlaka · česanje 3× na teden')).toBeTruthy();

    // "Spremeni vrsto" goes back to the tiles, the cat stays marked.
    fireEvent.press(screen.getByTestId('picker-change-species'));
    expect(screen.getByTestId('species-option-cat').props.accessibilityState).toEqual({ checked: true });
  });

  it('free breed first with the "Brezplačno" badge, paid with "Izziv"; a locked breed is greyed and explained', () => {
    renderPicker(BOTH);
    fireEvent.press(screen.getByTestId('species-option-cat'));
    fireEvent.press(screen.getByTestId('plan-option-free'));
    expect(screen.getByText(PICKER.badgeFree)).toBeTruthy();
    expect(screen.getByText(PICKER.badgeChallenge)).toBeTruthy();

    const coon = screen.getByTestId('breed-option-maine_coon');
    expect(coon.props.accessibilityState).toEqual({ checked: false, disabled: true });
    expect(coon.props.accessibilityLabel).toBe(PICKER.lockedA11y('Maine Coon'));
    fireEvent.press(coon);
    expect(screen.getByTestId('breed-locked-note')).toHaveTextContent(pickerText('cat').breedFreeNote);
    expect(screen.getByTestId('breed-option-domestic_cat').props.accessibilityState).toEqual({ checked: true, disabled: false });

    // Challenge: Maine Coon selected, the domestic cat locked (M5-F03).
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    expect(screen.getByTestId('breed-option-maine_coon').props.accessibilityState).toEqual({ checked: true, disabled: false });
    expect(screen.getByTestId('breed-option-domestic_cat').props.accessibilityState.disabled).toBe(true);
  });

  it('search filters the server list (diacritics, synonyms) and says when nothing matches', () => {
    renderPicker(BOTH);
    fireEvent.press(screen.getByTestId('species-option-cat'));
    fireEvent.changeText(screen.getByTestId('breed-search'), 'mejnkun');
    expect(screen.getByTestId('breed-option-maine_coon')).toBeTruthy();
    expect(screen.queryByTestId('breed-option-domestic_cat')).toBeNull();

    fireEvent.changeText(screen.getByTestId('breed-search'), 'DOMACA');
    expect(screen.getByTestId('breed-option-domestic_cat')).toBeTruthy();
    expect(screen.queryByTestId('breed-option-maine_coon')).toBeNull();

    fireEvent.changeText(screen.getByTestId('breed-search'), 'sfinga');
    expect(screen.getByTestId('breed-search-empty')).toHaveTextContent(PICKER.searchEmpty('sfinga'));
  });

  it('dogs: "mesancek" finds Mešanček', () => {
    renderPicker(FALLBACK_CATALOGUE);
    fireEvent.changeText(screen.getByTestId('breed-search'), 'mesancek');
    expect(screen.getByTestId('breed-option-mutt')).toBeTruthy();
    expect(screen.queryByTestId('breed-option-border_collie')).toBeNull();
  });

  it('summary before the PIN shows species, breed, origin, age and plan; confirm sends the species', () => {
    const { onConfirm } = renderPicker(BOTH);
    fireEvent.press(screen.getByTestId('species-option-cat'));
    fireEvent.press(screen.getByTestId('plan-option-free'));
    fireEvent.press(screen.getByTestId('origin-option-adopted'));
    expect(screen.queryByTestId('picker-summary')).toBeNull();
    fireEvent.press(screen.getByTestId('age-option-puppy'));

    const summary = screen.getByTestId('picker-summary');
    expect(summary).toHaveTextContent(/Mačka/);
    expect(summary).toHaveTextContent(/Domača mačka/);
    expect(summary).toHaveTextContent(/Posvojena/);
    expect(summary).toHaveTextContent(/Mucek/);
    expect(summary).toHaveTextContent(/Brezplačno/);

    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'cat', breed: 'domestic_cat', origin: 'adopted', age_stage: 'puppy', plan: 'free' },
      expect.objectContaining({ species: 'cat' }),
    );
  });

  it('while the catalogue loads: a spinner, and "Nazaj" still works', () => {
    const { onBack } = renderPicker(null);
    expect(screen.getByTestId('pet-picker-loading')).toBeTruthy();
    expect(screen.queryByTestId('dog-picker-confirm')).toBeNull();
    fireEvent.press(screen.getByTestId('dog-picker-back'));
    expect(onBack).toHaveBeenCalledWith(INITIAL_PICKER_CHOICE);
  });
});
