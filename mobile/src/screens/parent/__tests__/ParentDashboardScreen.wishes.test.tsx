/** M6-W05: the »Želje (N)« row (hidden on an older server) and the `wish_created` push open the wish list. */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { api } from '@/api/client';
import { i18n } from '@/i18n';
import ParentDashboardScreen from '@/screens/parent/ParentDashboardScreen';
import { useAppStore } from '@/store/appStore';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getParentDashboard: jest.fn(), getParentWishes: jest.fn() } };
});
jest.mock('@/hooks/usePetChannels', () => ({ usePetChannels: jest.fn() }));
jest.mock('@/modules/session/logout', () => ({ logout: jest.fn(() => Promise.resolve()) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

const getParentDashboard = api.getParentDashboard as jest.Mock;
const getParentWishes = api.getParentWishes as jest.Mock;
const PET7 = makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] });

// The first render loads the RevenueCat mock + i18n bundles (~5 s cold on CI runners).
jest.setTimeout(20_000);

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

beforeEach(async () => {
  jest.clearAllMocks();
  useAppStore.setState(useAppStore.getInitialState(), true);
  await i18n.changeLanguage('sl');
  getParentWishes.mockReturnValue(new Promise(() => undefined));
});

describe('ParentDashboardScreen — Želje', () => {
  it('older server without wishes_open_count → no row', async () => {
    getParentDashboard.mockResolvedValue(makeScoredDashboard([makeScoredChild()], [PET7]));
    renderWithQuery(<ParentDashboardScreen />);
    await flush();
    expect(screen.getByTestId('child-card-2')).toBeTruthy();
    expect(screen.queryByTestId('dashboard-wishes')).toBeNull();
  });

  it('»Želje (2)« opens the wish list', async () => {
    getParentDashboard.mockResolvedValue({ ...makeScoredDashboard([makeScoredChild()], [PET7]), wishes_open_count: 2 });
    renderWithQuery(<ParentDashboardScreen />);
    await flush();
    expect(screen.getByText('Želje (2)')).toBeTruthy();
    fireEvent.press(screen.getByTestId('dashboard-wishes'));
    expect(screen.getByTestId('wishes-screen')).toBeTruthy();
  });

  it('a tapped wish_created push opens the wish list', async () => {
    getParentDashboard.mockResolvedValue({ ...makeScoredDashboard([makeScoredChild()], [PET7]), wishes_open_count: 1 });
    useAppStore.getState().setPushTarget({ petId: null, type: 'wish_created' });
    renderWithQuery(<ParentDashboardScreen />);
    await flush();
    expect(screen.getByTestId('wishes-screen')).toBeTruthy();
    expect(useAppStore.getState().pushTarget).toBeNull();
  });
});
