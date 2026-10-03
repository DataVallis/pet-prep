/**
 * Parent dashboard: "Dodaj otroka" entry points (M2-02 partial) and the
 * loading / error / logout states added with M1-12.
 */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';

import { api, NO_CHILD_PAIRED_MESSAGE } from '@/api/client';
import { ADD_CHILD_CARD_STRINGS } from '@/components/AddChildCard';
import { logout } from '@/modules/session/logout';
import ParentDashboardScreen, { DASHBOARD_STRINGS } from '@/screens/parent/ParentDashboardScreen';
import { ADD_CHILD_STRINGS } from '@/screens/parent/AddChildScreen';
import { useAppStore } from '@/store/appStore';
import { makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      generatePin: jest.fn(),
      getParentDashboard: jest.fn(),
      getUser: jest.fn(),
      getQuietHours: jest.fn(),
    },
  };
});
jest.mock('@/hooks/usePetWebSocket', () => ({ usePetWebSocket: jest.fn() }));
jest.mock('@/modules/session/logout', () => ({
  logout: jest.fn(() => Promise.resolve()),
  refreshSessionPet: jest.fn(() => Promise.resolve()),
}));

const getParentDashboard = api.getParentDashboard as jest.Mock;
const generatePin = api.generatePin as jest.Mock;

const NO_CHILD = {
  message: NO_CHILD_PAIRED_MESSAGE,
  pet: null,
  traffic_light: 'green',
  quiet_hours: null,
  recent_activities: [],
};

const PAIRED = {
  pet: { id: 7, breed_type: 'mutt', escalation_level: 0 },
  child: { id: 2, name: 'Otrok' },
  traffic_light: 'green',
  quiet_hours: null,
  recent_activities: [],
  weekly_performance: [],
};

/** Let queries settle: TanStack batches notifications with setTimeout(0). */
async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

describe('ParentDashboardScreen — add child', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
    (api.getQuietHours as jest.Mock).mockResolvedValue({ quiet_hours: null });
    generatePin.mockReturnValue(new Promise(() => undefined));
  });

  it('shows a loading state until the dashboard answers', async () => {
    getParentDashboard.mockReturnValue(new Promise(() => undefined));
    renderWithQuery(<ParentDashboardScreen />);
    expect(screen.getByTestId('dashboard-loading')).toBeTruthy();
  });

  it('shows the prominent "Dodaj otroka" card instead of metrics when no child is paired', async () => {
    getParentDashboard.mockResolvedValue(NO_CHILD);
    renderWithQuery(<ParentDashboardScreen />);

    expect(await screen.findByTestId('add-child-card')).toBeTruthy();
    expect(screen.getByText(DASHBOARD_STRINGS.noChildSubtitle)).toBeTruthy();
    expect(screen.queryByText('Hrana')).toBeNull();
  });

  it('tapping the card opens the PIN screen and requests a PIN', async () => {
    getParentDashboard.mockResolvedValue(NO_CHILD);
    renderWithQuery(<ParentDashboardScreen />);

    fireEvent.press(await screen.findByTestId('add-child-card'));
    expect(screen.getByText(ADD_CHILD_STRINGS.instructions)).toBeTruthy();
    await flush();
    expect(generatePin).toHaveBeenCalledTimes(1);
  });

  it('offers "Dodaj otroka" in Controls while no child is paired', async () => {
    getParentDashboard.mockResolvedValue(NO_CHILD);
    renderWithQuery(<ParentDashboardScreen />);
    await screen.findByTestId('add-child-card');

    fireEvent.press(screen.getByText('Nadzor & Ure'));
    fireEvent.press(screen.getByText(ADD_CHILD_CARD_STRINGS.title));
    expect(screen.getByText(ADD_CHILD_STRINGS.instructions)).toBeTruthy();
  });

  it('hides "Dodaj otroka" everywhere once a child is paired (MVP: 1 parent → 1 child)', async () => {
    useAppStore.setState({ pet: makePet() });
    getParentDashboard.mockResolvedValue(PAIRED);
    renderWithQuery(<ParentDashboardScreen />);
    await flush();

    expect(screen.getByText('Hrana')).toBeTruthy();
    expect(screen.queryByTestId('add-child-card')).toBeNull();

    fireEvent.press(screen.getByText('Nadzor & Ure'));
    expect(screen.queryByTestId('add-child-card')).toBeNull();
  });

  it('shows a retryable error but keeps the last known metrics', async () => {
    useAppStore.setState({ pet: makePet() });
    getParentDashboard.mockRejectedValueOnce(new TypeError('Network request failed'));
    renderWithQuery(<ParentDashboardScreen />);

    expect(await screen.findByTestId('dashboard-error', {}, { timeout: 3000 })).toBeTruthy();
    expect(screen.getByText('Hrana')).toBeTruthy();
    getParentDashboard.mockResolvedValueOnce(PAIRED);
    fireEvent.press(screen.getByText(DASHBOARD_STRINGS.retry));
    await flush();
    await waitFor(() => expect(screen.queryByTestId('dashboard-error')).toBeNull());
  });

  it('logs out through the shared session logout', async () => {
    getParentDashboard.mockResolvedValue(NO_CHILD);
    renderWithQuery(<ParentDashboardScreen />);
    await flush();
    fireEvent.press(screen.getByLabelText(DASHBOARD_STRINGS.logout));
    expect(logout).toHaveBeenCalledTimes(1);
  });
});
