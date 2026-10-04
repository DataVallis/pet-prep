/**
 * Parent dashboard: "Dodaj otroka" entry points, the family children list with
 * "Nova koda za prijavo" / "Odjavi vse naprave" (M2-02, M2-01a slice) and the
 * loading / error / logout states added with M1-12.
 */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';
import { Alert } from 'react-native';

import { api, NO_CHILD_PAIRED_MESSAGE } from '@/api/client';
import { ADD_CHILD_CARD_STRINGS } from '@/components/AddChildCard';
import { FAMILY_STRINGS } from '@/components/FamilyChildrenCard';
import { logout } from '@/modules/session/logout';
import ParentDashboardScreen, { DASHBOARD_STRINGS } from '@/screens/parent/ParentDashboardScreen';
import { ADD_CHILD_STRINGS } from '@/screens/parent/AddChildScreen';
import { useAppStore } from '@/store/appStore';
import { makeFamilyChild, makeFamilyDashboard, makeFamilyPet, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      generatePin: jest.fn(),
      revokeChildTokens: jest.fn(),
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
const revokeChildTokens = api.revokeChildTokens as jest.Mock;

const LUKA = makeFamilyChild({ id: 2, name: 'Luka', pet_id: 7, devices: 2, contract_signed: true });
const MAJA = makeFamilyChild({ id: 5, name: 'Maja' });

const NO_CHILD = {
  message: NO_CHILD_PAIRED_MESSAGE,
  pet: null,
  traffic_light: 'green',
  quiet_hours: null,
  recent_activities: [],
};

const PAIRED = {
  ...makeFamilyDashboard([LUKA, MAJA], [makeFamilyPet({ caretakers: [{ child_id: 2, contract_signed: true }] })]),
  message: undefined,
  pet: { id: 7, breed_type: 'mutt', escalation_level: 0 },
  child: { id: 2, name: 'Luka' },
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
    revokeChildTokens.mockReset();
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

  it('tapping the card opens the child profile form (no PIN before the profile exists)', async () => {
    getParentDashboard.mockResolvedValue(NO_CHILD);
    renderWithQuery(<ParentDashboardScreen />);

    fireEvent.press(await screen.findByTestId('add-child-card'));
    expect(screen.getByText(ADD_CHILD_STRINGS.privacy)).toBeTruthy();
    await flush();
    expect(generatePin).not.toHaveBeenCalled();
  });

  it('offers "Dodaj otroka" in Controls while there is no child', async () => {
    getParentDashboard.mockResolvedValue(NO_CHILD);
    renderWithQuery(<ParentDashboardScreen />);
    await screen.findByTestId('add-child-card');

    fireEvent.press(screen.getByText('Nadzor & Ure'));
    fireEvent.press(screen.getByText(ADD_CHILD_CARD_STRINGS.title));
    expect(screen.getByText(ADD_CHILD_STRINGS.privacy)).toBeTruthy();
  });

  it('lists the children with pet and devices and keeps "Dodaj otroka" (several children)', async () => {
    useAppStore.setState({ pet: makePet() });
    getParentDashboard.mockResolvedValue(PAIRED);
    renderWithQuery(<ParentDashboardScreen />);
    await flush();

    expect(screen.getByText('Hrana')).toBeTruthy();
    expect(screen.getByText('Luka')).toBeTruthy();
    expect(screen.getByText('Mešanček')).toBeTruthy();
    expect(screen.getByTestId('family-child-devices-2')).toHaveTextContent('2 napravi');
    expect(screen.getByText(FAMILY_STRINGS.noPet)).toBeTruthy(); // Maja
    expect(screen.getByTestId('family-add-child')).toBeTruthy();

    fireEvent.press(screen.getByText('Nadzor & Ure'));
    expect(screen.getByTestId('family-children')).toBeTruthy();
    fireEvent.press(screen.getByTestId('family-add-child'));
    expect(screen.getByText(ADD_CHILD_STRINGS.privacy)).toBeTruthy();
  });

  it('shows the children list even when the family has no active pet yet', async () => {
    getParentDashboard.mockResolvedValue(makeFamilyDashboard([MAJA]));
    renderWithQuery(<ParentDashboardScreen />);
    await flush();

    expect(screen.getByTestId('family-child-5')).toBeTruthy();
    expect(screen.queryByTestId('add-child-card')).toBeNull();
  });

  it('"Nova koda za prijavo" opens a re-login PIN for that child', async () => {
    useAppStore.setState({ pet: makePet() });
    getParentDashboard.mockResolvedValue(PAIRED);
    renderWithQuery(<ParentDashboardScreen />);
    await flush();

    fireEvent.press(screen.getByTestId('child-pin-2'));
    expect(screen.getByText(ADD_CHILD_STRINGS.titleRelogin)).toBeTruthy();
    await flush();
    expect(generatePin).toHaveBeenCalledWith({ child_id: 2, pet_id: null });
  });

  it('a child without a pet gets the pet choice instead', async () => {
    useAppStore.setState({ pet: makePet() });
    getParentDashboard.mockResolvedValue(PAIRED);
    renderWithQuery(<ParentDashboardScreen />);
    await flush();

    fireEvent.press(screen.getByTestId('child-pin-5'));
    expect(screen.getByText(ADD_CHILD_STRINGS.petTitle('Maja'))).toBeTruthy();
    expect(generatePin).not.toHaveBeenCalled();
  });

  it('"Odjavi vse naprave" asks inline (no native alert), then revokes and refreshes', async () => {
    const alertSpy = jest.spyOn(Alert, 'alert');
    useAppStore.setState({ pet: makePet() });
    getParentDashboard.mockResolvedValue(PAIRED);
    revokeChildTokens.mockResolvedValueOnce({ revoked_tokens: 2, revoked_pins: 1 });
    renderWithQuery(<ParentDashboardScreen />);
    await flush();
    const callsBefore = getParentDashboard.mock.calls.length;

    fireEvent.press(screen.getByTestId('child-revoke-2'));
    expect(screen.getByText(FAMILY_STRINGS.confirmRevoke('Luka'))).toBeTruthy();
    expect(revokeChildTokens).not.toHaveBeenCalled();
    expect(alertSpy).not.toHaveBeenCalled();

    fireEvent.press(screen.getByTestId('revoke-confirm-button-2'));
    await flush();
    expect(revokeChildTokens).toHaveBeenCalledWith(2);
    expect(screen.getByTestId('revoke-result-2')).toHaveTextContent(FAMILY_STRINGS.revoked('2 napravi'));
    expect(screen.queryByTestId('revoke-confirm-2')).toBeNull();
    expect(getParentDashboard.mock.calls.length).toBeGreaterThan(callsBefore);
    alertSpy.mockRestore();
  });

  it('cancel keeps the devices signed in; an offline revoke explains and keeps the confirmation', async () => {
    useAppStore.setState({ pet: makePet() });
    getParentDashboard.mockResolvedValue(PAIRED);
    renderWithQuery(<ParentDashboardScreen />);
    await flush();

    fireEvent.press(screen.getByTestId('child-revoke-2'));
    fireEvent.press(screen.getByText(FAMILY_STRINGS.cancel));
    expect(screen.queryByTestId('revoke-confirm-2')).toBeNull();
    expect(revokeChildTokens).not.toHaveBeenCalled();

    revokeChildTokens.mockRejectedValueOnce(new TypeError('Network request failed'));
    fireEvent.press(screen.getByTestId('child-revoke-2'));
    fireEvent.press(screen.getByTestId('revoke-confirm-button-2'));
    await flush();
    expect(screen.getByTestId('revoke-result-2')).toHaveTextContent(FAMILY_STRINGS.errors.offline);
    expect(screen.getByTestId('revoke-confirm-2')).toBeTruthy();
  });

  it('"Odjavi vse naprave" is disabled for a child with no signed-in device', async () => {
    useAppStore.setState({ pet: makePet() });
    getParentDashboard.mockResolvedValue(PAIRED);
    renderWithQuery(<ParentDashboardScreen />);
    await flush();

    fireEvent.press(screen.getByTestId('child-revoke-5'));
    expect(screen.queryByTestId('revoke-confirm-5')).toBeNull();
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
