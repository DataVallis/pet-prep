/**
 * M5-R21 (David 2026-10-14): "Barva" in the pet picker — shown only for a paid breed with
 * colours (chips for several, one line for a single colour), first colour preselected,
 * the chosen key in the confirmed profile, hidden for the free breed and for older servers.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import PetPickerStep from '@/components/parent/PetPickerStep';
import {
  INITIAL_PICKER_CHOICE,
  PICKER_STRINGS as PICKER,
  readBreedCatalogue,
  type BreedCatalogue,
  type PickerChoice,
} from '@/modules/petProfile/picker';

function entry(breed: string, premium: boolean, sort_order: number, colours?: unknown) {
  return {
    breed,
    slug: breed,
    species: 'dog',
    premium,
    free_plan_allowed: !premium,
    challenge_allowed: premium,
    label_key: `breeds.${breed}`,
    search_keywords: [],
    sort_order,
    ...(colours !== undefined ? { colours } : {}),
  };
}

const CATALOGUE = readBreedCatalogue({
  species: ['dog'],
  breeds: [
    entry('mutt', false, 0, []),
    entry('border_collie', true, 10, [
      { key: 'black-white', label: 'Črno-bel' },
      { key: 'red-white', label: 'Rdeče-bel' },
      { key: 'blue-merle', label: 'Modri merle' },
    ]),
    entry('west_highland_white_terrier', true, 120, [{ key: 'white', label: 'Bel' }]),
  ],
}) as BreedCatalogue;

/** The same catalogue from a server before M5-R21 (no `colours`). */
const OLD_SERVER = readBreedCatalogue({
  species: ['dog'],
  breeds: [entry('mutt', false, 0), entry('border_collie', true, 10)],
}) as BreedCatalogue;

function renderPicker(catalogue: BreedCatalogue, initial: PickerChoice = INITIAL_PICKER_CHOICE) {
  const onConfirm = jest.fn();
  render(<PetPickerStep childName="Maja" catalogue={catalogue} initial={initial} onConfirm={onConfirm} onBack={jest.fn()} />);
  return onConfirm;
}

function finish() {
  fireEvent.press(screen.getByTestId('origin-option-bought'));
  fireEvent.press(screen.getByTestId('age-option-puppy'));
  fireEvent.press(screen.getByTestId('dog-picker-confirm'));
}

describe('PetPickerStep — coat colour (M5-R21)', () => {
  it('no colour section before a breed is chosen and for the free breed', () => {
    renderPicker(CATALOGUE);
    expect(screen.queryByTestId('colour-choice')).toBeNull();
    fireEvent.press(screen.getByTestId('plan-option-free'));
    expect(screen.getByTestId('breed-option-mutt').props.accessibilityState.checked).toBe(true);
    expect(screen.queryByTestId('colour-choice')).toBeNull();
    expect(screen.queryByTestId('colour-only')).toBeNull();
    expect(screen.queryByText(PICKER.colourTitle)).toBeNull();
  });

  it('paid breed with several colours: chips, the first preselected, the choice can change and is sent', () => {
    const onConfirm = renderPicker(CATALOGUE);
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-border_collie'));
    expect(screen.getByTestId('colour-choice')).toBeTruthy();
    expect(screen.getByText(PICKER.colourTitle)).toBeTruthy();
    expect(screen.getByTestId('colour-option-black-white').props.accessibilityState.checked).toBe(true);
    expect(screen.getByTestId('colour-option-red-white').props.accessibilityState.checked).toBe(false);
    // Accessible: radio chips with a spoken label.
    expect(screen.getByTestId('colour-option-blue-merle').props.accessibilityRole).toBe('radio');
    expect(screen.getByTestId('colour-option-blue-merle').props.accessibilityLabel).toBe(PICKER.colourA11y('Modri merle'));

    fireEvent.press(screen.getByTestId('colour-option-blue-merle'));
    expect(screen.getByTestId('colour-option-blue-merle').props.accessibilityState.checked).toBe(true);
    expect(screen.getByTestId('colour-option-black-white').props.accessibilityState.checked).toBe(false);

    finish();
    expect(screen.getByTestId('picker-summary')).toHaveTextContent(/Modri merle/);
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'border_collie', origin: 'bought', age_stage: 'puppy', plan: 'challenge', colour: 'blue-merle' },
      expect.objectContaining({ colour: 'blue-merle' }),
    );
  });

  it('a single colour is shown as fact (no chips) and sent', () => {
    const onConfirm = renderPicker(CATALOGUE);
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-west_highland_white_terrier'));
    expect(screen.queryByTestId('colour-choice')).toBeNull();
    expect(screen.getByTestId('colour-only')).toHaveTextContent(PICKER.colourOnly('Bel'));
    finish();
    expect(onConfirm.mock.calls[0][0]).toEqual(expect.objectContaining({ breed: 'west_highland_white_terrier', colour: 'white' }));
  });

  it('switching to the free plan hides the colour and sends none', () => {
    const onConfirm = renderPicker(CATALOGUE);
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-border_collie'));
    fireEvent.press(screen.getByTestId('colour-option-red-white'));
    fireEvent.press(screen.getByTestId('plan-option-free'));
    expect(screen.queryByTestId('colour-choice')).toBeNull();
    fireEvent.press(screen.getByTestId('origin-option-adopted'));
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm.mock.calls[0][0]).toEqual({ species: 'dog', breed: 'mutt', origin: 'adopted', age_stage: 'puppy', plan: 'free' });
  });

  it('a kept choice comes back with its colour (back from the PIN step)', () => {
    renderPicker(CATALOGUE, { ...INITIAL_PICKER_CHOICE, species: 'dog', plan: 'challenge', breed: 'border_collie', colour: 'red-white' });
    expect(screen.getByTestId('colour-option-red-white').props.accessibilityState.checked).toBe(true);
  });

  it('a kept colour the breed no longer has (colour_invalid) → the first one preselected, never blocked', () => {
    const onConfirm = renderPicker(CATALOGUE, { ...INITIAL_PICKER_CHOICE, species: 'dog', plan: 'challenge', breed: 'border_collie', colour: null });
    expect(screen.getByTestId('colour-option-black-white').props.accessibilityState.checked).toBe(true);
    finish();
    expect(onConfirm.mock.calls[0][0]).toEqual(expect.objectContaining({ colour: 'black-white' }));
  });

  it('an older server without `colours`: no colour section, no `colour` sent', () => {
    const onConfirm = renderPicker(OLD_SERVER);
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-border_collie'));
    expect(screen.queryByTestId('colour-choice')).toBeNull();
    expect(screen.queryByTestId('colour-only')).toBeNull();
    finish();
    expect(onConfirm.mock.calls[0][0]).toEqual({ species: 'dog', breed: 'border_collie', origin: 'bought', age_stage: 'puppy', plan: 'challenge' });
  });
});
