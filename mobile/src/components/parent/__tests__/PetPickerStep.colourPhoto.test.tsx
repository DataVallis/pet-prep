/**
 * David 2026-11-16: the parent sees the AI photo of the breed in the chosen colour above the
 * colour chips (`image_url` from GET /api/breeds, the website register's photos). A colour
 * without a photo, or a photo that fails to load, shows no photo — never another colour's.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';
import { Image } from 'react-native';

import PetPickerStep from '@/components/parent/PetPickerStep';
import { INITIAL_PICKER_CHOICE, PICKER_STRINGS as PICKER, readBreedCatalogue, readColours, type BreedCatalogue } from '@/modules/petProfile/picker';

const BC = 'https://petprep.si/animals/dogs/border-collie';

function entry(breed: string, premium: boolean, sort_order: number, colours: unknown) {
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
    colours,
  };
}

const CATALOGUE = readBreedCatalogue({
  species: ['dog'],
  breeds: [
    entry('mutt', false, 0, []),
    entry('border_collie', true, 10, [
      { key: 'black-white', label: 'Črno-bel', image_url: `${BC}.webp` },
      { key: 'red-white', label: 'Rdeče-bel', image_url: `${BC}--red-white.webp` },
      { key: 'blue-merle', label: 'Modri merle', image_url: null },
    ]),
    entry('west_highland_white_terrier', true, 120, [{ key: 'white', label: 'Bel', image_url: 'https://petprep.si/animals/dogs/west-highland-white-terrier.webp' }]),
  ],
}) as BreedCatalogue;

function renderPicker() {
  render(<PetPickerStep childName="Maja" catalogue={CATALOGUE} initial={INITIAL_PICKER_CHOICE} onConfirm={jest.fn()} onBack={jest.fn()} />);
  fireEvent.press(screen.getByTestId('plan-option-challenge'));
}

describe('PetPickerStep — colour photo', () => {
  beforeEach(() => {
    jest.spyOn(Image, 'prefetch').mockResolvedValue(true);
  });
  afterEach(() => jest.restoreAllMocks());

  it('readColours keeps only https image URLs', () => {
    expect(
      readColours([
        { key: 'a', label: 'A', image_url: 'https://x/a.webp' },
        { key: 'b', label: 'B', image_url: 'http://x/b.webp' },
        { key: 'c', label: 'C' },
      ]),
    ).toEqual([
      { key: 'a', label: 'A', image_url: 'https://x/a.webp' },
      { key: 'b', label: 'B', image_url: null },
      { key: 'c', label: 'C', image_url: null },
    ]);
  });

  it('shows the photo of the selected colour and swaps it with the chip; prefetches every photo', () => {
    renderPicker();
    fireEvent.press(screen.getByTestId('breed-option-border_collie'));
    expect(screen.getByTestId('colour-photo-black-white').props.source).toEqual({ uri: `${BC}.webp` });
    expect(screen.getByText(PICKER.colourPhotoCaption)).toBeTruthy();
    expect(Image.prefetch).toHaveBeenCalledWith(`${BC}--red-white.webp`);

    fireEvent.press(screen.getByTestId('colour-option-red-white'));
    expect(screen.getByTestId('colour-photo-red-white').props.source).toEqual({ uri: `${BC}--red-white.webp` });
    expect(screen.queryByTestId('colour-photo-black-white')).toBeNull();
  });

  it('a colour without a photo shows none', () => {
    renderPicker();
    fireEvent.press(screen.getByTestId('breed-option-border_collie'));
    fireEvent.press(screen.getByTestId('colour-option-blue-merle'));
    expect(screen.queryByTestId('colour-photo')).toBeNull();
  });

  it('a photo that fails to load is hidden', () => {
    renderPicker();
    fireEvent.press(screen.getByTestId('breed-option-border_collie'));
    fireEvent(screen.getByTestId('colour-photo-black-white'), 'error');
    expect(screen.queryByTestId('colour-photo')).toBeNull();
  });

  it('a single-colour breed shows its photo too', () => {
    renderPicker();
    fireEvent.press(screen.getByTestId('breed-option-west_highland_white_terrier'));
    expect(screen.getByTestId('colour-photo-white')).toBeTruthy();
    expect(screen.getByTestId('colour-only')).toBeTruthy();
  });
});
