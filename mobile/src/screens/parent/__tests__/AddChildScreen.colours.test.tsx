/**
 * M5-R21 (David 2026-10-14): the coat colour through the whole "Dodaj otroka" flow —
 * picker → generate-pin with `colour`, and 422 `colour_invalid` → the colours are loaded
 * again and the parent chooses the colour again.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import AddChildScreen, { ADD_CHILD_STRINGS as S } from '@/screens/parent/AddChildScreen';
import { useAppStore } from '@/store/appStore';
import { makeFamilyChild, makeFamilyDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      generatePin: jest.fn(),
      getBreedCatalogue: jest.fn(),
      getParentDashboard: jest.fn(),
    },
  };
});
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

const generatePin = api.generatePin as jest.Mock;
const getBreedCatalogue = api.getBreedCatalogue as jest.Mock;
const getParentDashboard = api.getParentDashboard as jest.Mock;

const NEW_CHILD = makeFamilyChild({ id: 5, name: 'Maja' });
const MUTT = { breed: 'mutt', slug: 'mutt', species: 'dog', premium: false, free_plan_allowed: true, challenge_allowed: false, label_key: 'breeds.mutt', search_keywords: [], sort_order: 0, colours: [] };
const collie = (colours: Array<{ key: string; label: string }>) => ({
  breed: 'border_collie',
  slug: 'border-collie',
  species: 'dog',
  premium: true,
  free_plan_allowed: false,
  challenge_allowed: true,
  label_key: 'breeds.border_collie',
  search_keywords: [],
  sort_order: 10,
  colours,
});
const CATALOGUE = {
  species: ['dog'],
  breeds: [MUTT, collie([{ key: 'black-white', label: 'Črno-bel' }, { key: 'red-white', label: 'Rdeče-bel' }])],
};

async function flush() {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(0);
  });
}

async function openPicker() {
  renderWithQuery(<AddChildScreen onBack={jest.fn()} child={NEW_CHILD} />);
  await flush();
  fireEvent.press(screen.getByTestId('pet-option-new'));
  await flush();
}

function pickCollie(colour: string) {
  fireEvent.press(screen.getByTestId('plan-option-challenge'));
  fireEvent.press(screen.getByTestId('breed-option-border_collie'));
  fireEvent.press(screen.getByTestId(`colour-option-${colour}`));
  fireEvent.press(screen.getByTestId('origin-option-bought'));
  fireEvent.press(screen.getByTestId('age-option-puppy'));
  fireEvent.press(screen.getByTestId('dog-picker-confirm'));
}

describe('AddChildScreen — coat colour (M5-R21)', () => {
  beforeEach(() => {
    [generatePin, getBreedCatalogue, getParentDashboard].forEach((m) => m.mockReset());
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-10-14T10:00:00Z'));
    getBreedCatalogue.mockResolvedValue(CATALOGUE);
    getParentDashboard.mockResolvedValue(makeFamilyDashboard([NEW_CHILD], []));
    generatePin.mockResolvedValue({
      pin: '734912',
      expires_at: new Date(Date.now() + 15 * 60_000).toISOString(),
      expires_in_minutes: 15,
      child_id: 5,
      pet_id: null,
      mode: 'new_pet',
    });
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('the chosen colour goes to generate-pin with the profile', async () => {
    await openPicker();
    pickCollie('red-white');
    await flush();
    expect(generatePin).toHaveBeenCalledWith({
      child_id: 5,
      pet_id: null,
      profile: { species: 'dog', breed: 'border_collie', origin: 'bought', age_stage: 'puppy', plan: 'challenge', colour: 'red-white' },
    });
    expect(screen.getByText('734 912')).toBeTruthy();
  });

  it('422 colour_invalid → explained; the colours are loaded again and the parent chooses again', async () => {
    generatePin.mockRejectedValueOnce(new ApiError('x', 422, { reason: 'colour_invalid' }));
    await openPicker();
    pickCollie('red-white');
    await flush();
    expect(screen.getByTestId('pin-error')).toHaveTextContent(S.errors.colour_invalid);
    expect(S.errors.colour_invalid).toMatch(/^Te barve pri tej pasmi ni več/);

    // Meanwhile the breed's colours changed on the server (red-white gone, sable added).
    getBreedCatalogue.mockResolvedValue({
      species: ['dog'],
      breeds: [MUTT, collie([{ key: 'black-white', label: 'Črno-bel' }, { key: 'sable', label: 'Sobolji' }])],
    });
    const callsBefore = getBreedCatalogue.mock.calls.length;
    fireEvent.press(screen.getByTestId('pin-change-dog'));
    await flush();
    expect(getBreedCatalogue.mock.calls.length).toBeGreaterThan(callsBefore);
    expect(screen.getByTestId('dog-picker-notice')).toHaveTextContent(S.errors.colour_invalid);
    // The new list, the first colour preselected; breed, origin and age kept.
    expect(screen.queryByTestId('colour-option-red-white')).toBeNull();
    expect(screen.getByTestId('colour-option-black-white').props.accessibilityState.checked).toBe(true);
    expect(screen.getByTestId('breed-option-border_collie').props.accessibilityState.checked).toBe(true);
    expect(screen.getByTestId('origin-option-bought').props.accessibilityState.checked).toBe(true);

    fireEvent.press(screen.getByTestId('colour-option-sable'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    await flush();
    expect(generatePin).toHaveBeenLastCalledWith(
      expect.objectContaining({ profile: expect.objectContaining({ breed: 'border_collie', colour: 'sable' }) }),
    );
  });
});
