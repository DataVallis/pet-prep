/**
 * M3-02: a tapped push (parent) opens the detail of the child caring for that pet.
 */
import { act, screen } from '@testing-library/react-native';

import { api } from '@/api/client';
import ParentDashboardScreen from '@/screens/parent/ParentDashboardScreen';
import { useAppStore } from '@/store/appStore';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      getParentDashboard: jest.fn(),
      getChildReport: jest.fn(),
      getPetActivities: jest.fn(),
      getQuietHours: jest.fn(),
    },
  };
});
jest.mock('@/hooks/usePetChannels', () => ({ usePetChannels: jest.fn() }));
jest.mock('@/modules/session/logout', () => ({ logout: jest.fn(() => Promise.resolve()) }));

const getParentDashboard = api.getParentDashboard as jest.Mock;

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

const PET7 = makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] });
const PET8 = makeFamilyPet({ id: 8, caretakers: [{ child_id: 5, contract_signed: true }] });

describe('ParentDashboardScreen — push tap (M3-02)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
    (api.getChildReport as jest.Mock).mockReturnValue(new Promise(() => undefined));
    (api.getPetActivities as jest.Mock).mockReturnValue(new Promise(() => undefined));
    getParentDashboard.mockResolvedValue(
      makeScoredDashboard([makeScoredChild(), makeScoredChild({ id: 5, name: 'Maja', pet_id: 8 })], [PET7, PET8]),
    );
  });

  it('opens the detail of the child of the pushed pet (also when the tap came before the data)', async () => {
    useAppStore.getState().setPushTarget({ petId: 8 });
    renderWithQuery(<ParentDashboardScreen />);
    await flush();

    expect(screen.getByTestId('detail-back')).toBeTruthy();
    expect(api.getChildReport).toHaveBeenCalledWith(5, 7);
    expect(useAppStore.getState().pushTarget).toBeNull();
  });

  it('a tap while the overview is open switches to the detail', async () => {
    renderWithQuery(<ParentDashboardScreen />);
    await flush();
    expect(screen.getByTestId('child-card-2')).toBeTruthy();

    act(() => useAppStore.getState().setPushTarget({ petId: 7 }));
    await flush();

    expect(api.getChildReport).toHaveBeenCalledWith(2, 7);
    expect(useAppStore.getState().pushTarget).toBeNull();
  });

  it('a pet that is not in the family just clears the target', async () => {
    useAppStore.getState().setPushTarget({ petId: 999 });
    renderWithQuery(<ParentDashboardScreen />);
    await flush();

    expect(screen.queryByTestId('detail-back')).toBeNull();
    expect(screen.getByTestId('child-card-2')).toBeTruthy();
    expect(useAppStore.getState().pushTarget).toBeNull();
  });
});
