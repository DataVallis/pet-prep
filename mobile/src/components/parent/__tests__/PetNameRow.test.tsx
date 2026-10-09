/**
 * M5-R08 — the parent's pet name: the "Ime" row + sheet (client-side validation, save, remove,
 * the three server codes, offline, optimistic dashboard update and refetch) and the label on
 * the parent's cards (child card, Nadzor pet card) with and without a name.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';
import { Modal } from 'react-native';

import { ApiError, api, type ParentDashboardResponse } from '@/api/client';
import ChildOverviewCard from '@/components/parent/ChildOverviewCard';
import PetControlsCard from '@/components/parent/PetControlsCard';
import PetNameRow from '@/components/parent/PetNameRow';
import { i18n } from '@/i18n';
import { familyFromDashboard, normalizePet } from '@/modules/family/family';
import { parentDashboardKey } from '@/modules/family/live';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, setPetName: jest.fn() } };
});

const setPetName = api.setPetName as jest.Mock;

const ID = 'pet-name-7';
/** A name inside a label is wrapped in FSI … PDI (bidi isolation). */
const iso = (name: string) => `\u2068${name}\u2069`;

function dashboard(name: string | null = null) {
  return makeScoredDashboard([makeScoredChild({ pet_id: 7 })], [makeFamilyPet({ id: 7, breed_type: 'border_collie', name })]) as unknown as ParentDashboardResponse;
}

function renderRow(name: string | null = null) {
  const utils = renderWithQuery(<PetNameRow pet={{ id: 7, name, breed_type: 'border_collie', species: 'dog' }} />);
  utils.client.setQueryData(parentDashboardKey, dashboard(name));
  return utils;
}

function openSheet() {
  fireEvent.press(screen.getByTestId(`${ID}-edit`));
  return screen.getByTestId(`${ID}-sheet-input`);
}

async function settle() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
}

beforeEach(() => {
  jest.clearAllMocks();
});

afterEach(async () => {
  await i18n.changeLanguage('sl');
});

describe('PetNameRow — the "Ime" row', () => {
  it('without a name: "Še brez imena" and "Dodaj ime" with an accessible label', () => {
    renderRow();
    expect(screen.getByTestId(`${ID}-value`)).toHaveTextContent('Še brez imena');
    expect(screen.getByText('Dodaj ime')).toBeTruthy();
    expect(screen.getByTestId(`${ID}-edit`).props.accessibilityLabel).toBe('Dodaj ime: Border collie');
  });

  it('with a name: the name and "Spremeni"', () => {
    renderRow('Luna');
    expect(screen.getByTestId(`${ID}-value`)).toHaveTextContent('Luna');
    expect(screen.getByText('Spremeni')).toBeTruthy();
    expect(screen.getByTestId(`${ID}-edit`).props.accessibilityLabel).toBe(`Spremeni ime: ${iso('Luna')} · Border collie`);
  });

  it('"Prekliči" closes the sheet without a request', () => {
    renderRow('Luna');
    fireEvent.changeText(openSheet(), 'Bela');
    expect(screen.getByTestId(`${ID}-sheet-cancel`)).toHaveTextContent('Prekliči');
    fireEvent.press(screen.getByTestId(`${ID}-sheet-cancel`));
    expect(screen.queryByTestId(`${ID}-sheet`)).toBeNull();
    expect(setPetName).not.toHaveBeenCalled();
  });

  it('the sheet suggests choosing the name together with the child; no "Odstrani ime" without a name', () => {
    renderRow();
    openSheet();
    expect(screen.getByText('Ime izberita skupaj z otrokom.')).toBeTruthy();
    expect(screen.getByTestId(`${ID}-sheet-counter`)).toHaveTextContent('0/20');
    expect(screen.queryByTestId(`${ID}-sheet-remove`)).toBeNull();
  });
});

describe('PetNameRow — validation before any request', () => {
  it('too long: explained, nothing sent', () => {
    renderRow();
    fireEvent.changeText(openSheet(), 'Abcdefghijklmnopqrstu');
    expect(screen.getByTestId(`${ID}-sheet-counter`)).toHaveTextContent('21/20');
    fireEvent.press(screen.getByTestId(`${ID}-sheet-save`));
    expect(screen.getByTestId(`${ID}-sheet-error`)).toHaveTextContent('Ime je lahko dolgo največ 20 znakov.');
    expect(setPetName).not.toHaveBeenCalled();
  });

  it('digits / symbols: explained, nothing sent; typing again clears the message', () => {
    renderRow();
    const input = openSheet();
    fireEvent.changeText(input, 'Luna 2');
    fireEvent.press(screen.getByTestId(`${ID}-sheet-save`));
    expect(screen.getByTestId(`${ID}-sheet-error`)).toHaveTextContent("Uporabite samo črke, presledek, vezaj (-) in opuščaj (').");
    expect(setPetName).not.toHaveBeenCalled();
    fireEvent.changeText(input, 'Luna');
    expect(screen.queryByTestId(`${ID}-sheet-error`)).toBeNull();
  });

  it('nothing changed: closes without a request', () => {
    renderRow('Luna');
    fireEvent.changeText(openSheet(), '  Luna ');
    fireEvent.press(screen.getByTestId(`${ID}-sheet-save`));
    expect(setPetName).not.toHaveBeenCalled();
    expect(screen.queryByTestId(`${ID}-sheet`)).toBeNull();
  });
});

describe('PetNameRow — save and remove', () => {
  it('saves the normalised name, updates the dashboard at once and refetches it', async () => {
    let answer: (v: { pet_id: number; name: string | null }) => void = () => undefined;
    setPetName.mockReturnValue(new Promise((resolve) => (answer = resolve)));
    const { client } = renderRow();
    fireEvent.changeText(openSheet(), '  Mala   Luna ');
    fireEvent.press(screen.getByTestId(`${ID}-sheet-save`));
    await settle();

    expect(setPetName).toHaveBeenCalledWith(7, 'Mala Luna');
    // Optimistic: the cached dashboard already has the name.
    expect(client.getQueryData<ParentDashboardResponse>(parentDashboardKey)?.family?.pets[0].name).toBe('Mala Luna');

    await act(async () => answer({ pet_id: 7, name: 'Mala Luna' }));
    await settle();
    expect(screen.queryByTestId(`${ID}-sheet`)).toBeNull();
    expect(screen.getByTestId(`${ID}-result`)).toHaveTextContent('Ime je shranjeno.');
    // The server's stored form stays; the dashboard (and so the detail) is marked for refetch.
    expect(client.getQueryData<ParentDashboardResponse>(parentDashboardKey)?.family?.pets[0].name).toBe('Mala Luna');
    expect(client.getQueryState(parentDashboardKey)?.isInvalidated).toBe(true);
  });

  it('a named pet: optimistic new name while saving, the previous name back after a rejection', async () => {
    let fail: (e: unknown) => void = () => undefined;
    setPetName.mockReturnValue(new Promise((_resolve, reject) => (fail = reject)));
    const { client } = renderRow('Luna');
    fireEvent.changeText(openSheet(), 'Bela');
    fireEvent.press(screen.getByTestId(`${ID}-sheet-save`));
    await settle();

    expect(setPetName).toHaveBeenCalledWith(7, 'Bela');
    expect(client.getQueryData<ParentDashboardResponse>(parentDashboardKey)?.family?.pets[0].name).toBe('Bela');
    expect(screen.getByTestId(`${ID}-sheet-save`).props.accessibilityState).toEqual(expect.objectContaining({ busy: true }));

    await act(async () => fail(new ApiError('x', 500)));
    await settle();
    expect(client.getQueryData<ParentDashboardResponse>(parentDashboardKey)?.family?.pets[0].name).toBe('Luna');
    expect(screen.getByTestId(`${ID}-sheet-error`)).toHaveTextContent('Imena ni bilo mogoče shraniti. Poskusite pozneje.');
  });

  it('while saving, the back button / backdrop / ✕ / Prekliči do not close the sheet', async () => {
    let answer: (v: { pet_id: number; name: string | null }) => void = () => undefined;
    setPetName.mockReturnValue(new Promise((resolve) => (answer = resolve)));
    renderRow();
    fireEvent.changeText(openSheet(), 'Luna');
    fireEvent.press(screen.getByTestId(`${ID}-sheet-save`));
    await settle();

    act(() => {
      screen.UNSAFE_getByType(Modal).props.onRequestClose();
    });
    fireEvent.press(screen.getByTestId(`${ID}-sheet-backdrop`, { includeHiddenElements: true }));
    fireEvent.press(screen.getByTestId(`${ID}-sheet-close`));
    fireEvent.press(screen.getByTestId(`${ID}-sheet-cancel`));
    expect(screen.getByTestId(`${ID}-sheet`)).toBeTruthy();

    await act(async () => answer({ pet_id: 7, name: 'Luna' }));
    await settle();
    expect(screen.queryByTestId(`${ID}-sheet`)).toBeNull();

    // Idle again: the back button closes it.
    openSheet();
    act(() => {
      screen.UNSAFE_getByType(Modal).props.onRequestClose();
    });
    expect(screen.queryByTestId(`${ID}-sheet`)).toBeNull();
  });

  it('"Odstrani ime" clears it (null)', async () => {
    setPetName.mockResolvedValue({ pet_id: 7, name: null });
    const { client } = renderRow('Luna');
    openSheet();
    fireEvent.press(screen.getByTestId(`${ID}-sheet-remove`));
    await settle();
    expect(setPetName).toHaveBeenCalledWith(7, null);
    expect(screen.getByTestId(`${ID}-result`)).toHaveTextContent('Ime je odstranjeno.');
    expect(client.getQueryData<ParentDashboardResponse>(parentDashboardKey)?.family?.pets[0].name).toBeNull();
  });

  it('an emptied input on a named pet removes the name too', async () => {
    setPetName.mockResolvedValue({ pet_id: 7, name: null });
    renderRow('Luna');
    fireEvent.changeText(openSheet(), '   ');
    fireEvent.press(screen.getByTestId(`${ID}-sheet-save`));
    await settle();
    expect(setPetName).toHaveBeenCalledWith(7, null);
  });

  it.each([
    ['name_not_allowed', 'Prosimo, izberite drugo ime.'],
    ['name_too_long', 'Ime je lahko dolgo največ 20 znakov.'],
    ['name_invalid', "Uporabite samo črke, presledek, vezaj (-) in opuščaj (')."],
  ])('server 422 %s: friendly text, sheet stays open, the dashboard rolls back', async (code, text) => {
    setPetName.mockRejectedValue(new ApiError('x', 422, { message: 'x', errors: { name: ['x'] }, codes: { name: code }, reason: code }));
    const { client } = renderRow();
    fireEvent.changeText(openSheet(), 'Luna');
    fireEvent.press(screen.getByTestId(`${ID}-sheet-save`));
    await settle();
    expect(screen.getByTestId(`${ID}-sheet-error`)).toHaveTextContent(text);
    expect(screen.getByTestId(`${ID}-sheet`)).toBeTruthy();
    expect(client.getQueryData<ParentDashboardResponse>(parentDashboardKey)?.family?.pets[0].name).toBeNull();
  });

  it('offline: says so, keeps the input', async () => {
    setPetName.mockRejectedValue(new TypeError('Network request failed'));
    renderRow();
    fireEvent.changeText(openSheet(), 'Luna');
    fireEvent.press(screen.getByTestId(`${ID}-sheet-save`));
    await settle();
    expect(screen.getByTestId(`${ID}-sheet-error`)).toHaveTextContent('Ni povezave. Poskusite znova.');
    expect(screen.getByTestId(`${ID}-sheet-input`).props.value).toBe('Luna');
  });

  it('English texts', async () => {
    await i18n.changeLanguage('en');
    setPetName.mockRejectedValue(new ApiError('x', 422, { codes: { name: 'name_not_allowed' }, reason: 'name_not_allowed' }));
    renderRow();
    expect(screen.getByText('Add a name')).toBeTruthy();
    expect(screen.getByTestId(`${ID}-edit`).props.accessibilityLabel).toBe('Add a name: Border Collie');
    fireEvent.changeText(openSheet(), 'Luna');
    expect(screen.getByTestId(`${ID}-sheet-cancel`)).toHaveTextContent('Cancel');
    expect(screen.getByText('Pick the name together with your child.')).toBeTruthy();
    fireEvent.press(screen.getByTestId(`${ID}-sheet-save`));
    await settle();
    expect(screen.getByTestId(`${ID}-sheet-error`)).toHaveTextContent('Please choose a different name.');
  });
});

describe('parent cards — the label with / without a name', () => {
  const TZ = 'Europe/Ljubljana';

  it('child card: the breed alone without a name (unchanged), "Luna · Border collie" with one', () => {
    const child = makeScoredChild({ pet_id: 7 });
    const plain = normalizePet(makeFamilyPet({ id: 7, breed_type: 'border_collie' }));
    const { rerender } = render(<ChildOverviewCard child={child} pet={plain} timezone={TZ} onOpen={jest.fn()} onChildPin={jest.fn()} />);
    expect(screen.getByTestId(`child-pet-label-${child.id}`)).toHaveTextContent('Border collie');
    expect(screen.getByTestId(`child-pet-label-${child.id}`).props.children).toBe('Border collie');

    const named = normalizePet(makeFamilyPet({ id: 7, breed_type: 'border_collie', name: 'Luna' }));
    rerender(<ChildOverviewCard child={child} pet={named} timezone={TZ} onOpen={jest.fn()} onChildPin={jest.fn()} />);
    expect(screen.getByTestId(`child-pet-label-${child.id}`).props.children).toBe(`${iso('Luna')} · Border collie`);
  });

  it('Nadzor pet card: title + the "Ime" row, for a cat as well', () => {
    const family = familyFromDashboard(
      makeScoredDashboard(
        [makeScoredChild({ pet_id: 7 })],
        [makeFamilyPet({ id: 7, breed_type: 'border_collie' }), makeFamilyPet({ id: 8, breed_type: 'domestic_cat', species: 'cat', name: 'Muri' })],
      ) as unknown as ParentDashboardResponse,
    )!;
    renderWithQuery(
      <>
        <PetControlsCard pet={family.pets[0]} family={family} />
        <PetControlsCard pet={family.pets[1]} family={family} />
      </>,
    );
    expect(screen.getByTestId('pet-title-7').props.children).toBe('Border collie');
    expect(screen.getByTestId('pet-name-7-value')).toHaveTextContent('Še brez imena');
    expect(screen.getByTestId('pet-title-8').props.children).toBe(`${iso('Muri')} · Domača mačka`);
    expect(screen.getByTestId('pet-name-8-value')).toHaveTextContent('Muri');
  });
});
