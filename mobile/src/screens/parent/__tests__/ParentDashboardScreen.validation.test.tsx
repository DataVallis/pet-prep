/** M5-08: H6 dashboard-opened signal, the once-only acquisition card and the H7 fake door. */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import { i18n } from '@/i18n';
import ParentDashboardScreen from '@/screens/parent/ParentDashboardScreen';
import { useAppStore } from '@/store/appStore';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import { OPEN_GRACE_MS } from '@/modules/validation/dashboardOpened';
import { markAcquisitionPromptDone } from '@/modules/validation/acquisitionPrefs';
import { resetDashboardOpenedForTests } from '@/modules/validation/useDashboardOpenedSignal';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      getParentDashboard: jest.fn(),
      getUser: jest.fn(),
      reportDashboardOpened: jest.fn(() => Promise.resolve(null)),
      reportPhase2Viewed: jest.fn(() => Promise.resolve(null)),
      submitPhase2Interest: jest.fn(),
      updateAcquisitionSource: jest.fn(),
    },
  };
});
jest.mock('@/hooks/usePetChannels', () => ({ usePetChannels: jest.fn() }));
jest.mock('@/modules/session/logout', () => ({ logout: jest.fn(() => Promise.resolve()) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

const m = api as unknown as Record<
  'getParentDashboard' | 'getUser' | 'reportDashboardOpened' | 'reportPhase2Viewed' | 'submitPhase2Interest' | 'updateAcquisitionSource',
  jest.Mock
>;
const PET7 = makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] });
const PARENT = { id: 11, name: 'Ana', email: 'ana@example.com', role: 'parent' as const };

jest.setTimeout(20_000);

async function flush(ms = 20) {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, ms));
  });
}

beforeEach(async () => {
  jest.clearAllMocks();
  resetDashboardOpenedForTests();
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.setState({ user: PARENT });
  await i18n.changeLanguage('sl');
  m.getUser.mockResolvedValue({ ...PARENT, pet: null, acquisition_source: 'instagram' });
});

describe('H6 dashboard-opened', () => {
  it('reports one `launch` on mount, never from refetches', async () => {
    m.getParentDashboard.mockResolvedValue(makeScoredDashboard([makeScoredChild()], [PET7]));
    const { client } = renderWithQuery(<ParentDashboardScreen />);
    await flush(OPEN_GRACE_MS + 50);
    expect(m.reportDashboardOpened).toHaveBeenCalledTimes(1);
    expect(m.reportDashboardOpened).toHaveBeenCalledWith('launch');
    await act(async () => {
      await client.refetchQueries();
    });
    await flush(OPEN_GRACE_MS + 50);
    expect(m.reportDashboardOpened).toHaveBeenCalledTimes(1);
  });
});

describe('acquisition card', () => {
  it('shown when the server says null; »Shrani« sends the answer once', async () => {
    m.getUser.mockResolvedValue({ ...PARENT, pet: null, acquisition_source: null });
    m.updateAcquisitionSource.mockResolvedValue({});
    m.getParentDashboard.mockResolvedValue(makeScoredDashboard([makeScoredChild()], [PET7]));
    renderWithQuery(<ParentDashboardScreen />);
    expect(await screen.findByTestId('acquisition-card')).toBeTruthy();
    expect(screen.getByText('Kje ste izvedeli za PetPrep?')).toBeTruthy();

    fireEvent.press(screen.getByTestId('acquisition-card-picker-other'));
    fireEvent.changeText(screen.getByTestId('acquisition-card-picker-detail'), ' Plakat ');
    fireEvent.press(screen.getByTestId('acquisition-card-save'));
    await flush();
    expect(m.updateAcquisitionSource).toHaveBeenCalledWith({ acquisition_source: 'other', acquisition_detail: 'Plakat' });
    expect(screen.queryByTestId('acquisition-card')).toBeNull();
  });

  it('hidden for an older server (no field) and after »Ne zdaj«', async () => {
    m.getUser.mockResolvedValue({ ...PARENT, pet: null });
    m.getParentDashboard.mockResolvedValue(makeScoredDashboard([makeScoredChild()], [PET7]));
    const first = renderWithQuery(<ParentDashboardScreen />);
    await flush(50);
    expect(screen.queryByTestId('acquisition-card')).toBeNull();
    first.unmount();

    m.getUser.mockResolvedValue({ ...PARENT, pet: null, acquisition_source: null });
    renderWithQuery(<ParentDashboardScreen />);
    fireEvent.press(await screen.findByTestId('acquisition-card-close'));
    await flush();
    expect(screen.queryByTestId('acquisition-card')).toBeNull();
    expect(m.updateAcquisitionSource).not.toHaveBeenCalled();
  });

  it('never asks a parent who already saw the question at sign-up on this phone', async () => {
    await markAcquisitionPromptDone(PARENT.id);
    m.getUser.mockResolvedValue({ ...PARENT, pet: null, acquisition_source: null });
    m.getParentDashboard.mockResolvedValue(makeScoredDashboard([makeScoredChild()], [PET7]));
    renderWithQuery(<ParentDashboardScreen />);
    await flush(50);
    expect(screen.queryByTestId('acquisition-card')).toBeNull();
    expect(m.getUser).not.toHaveBeenCalled();
  });
});

describe('H7 fake door', () => {
  const dashboard = (submitted: boolean) => ({ ...makeScoredDashboard([makeScoredChild()], [PET7]), phase2_interest: { submitted } });

  it('older server → no row', async () => {
    m.getParentDashboard.mockResolvedValue(makeScoredDashboard([makeScoredChild()], [PET7]));
    renderWithQuery(<ParentDashboardScreen />);
    expect(await screen.findByTestId('child-card-2')).toBeTruthy();
    expect(screen.queryByTestId('dashboard-phase2')).toBeNull();
  });

  it('tap → viewed + »v pripravi« screen; 422 email_required → the e-mail field; then thanks', async () => {
    m.getParentDashboard.mockResolvedValue(dashboard(false));
    m.submitPhase2Interest
      .mockRejectedValueOnce(new ApiError('x', 422, { message: 'x', code: 'email_required' }))
      .mockResolvedValueOnce({ status: 'saved' });
    renderWithQuery(<ParentDashboardScreen />);
    fireEvent.press(await screen.findByTestId('dashboard-phase2'));
    expect(m.reportPhase2Viewed).toHaveBeenCalledTimes(1);
    expect(screen.getByText('Pripravljamo pomočnika za pravega ljubljenčka')).toBeTruthy();
    expect(screen.getByText('V pripravi')).toBeTruthy();

    // No price yet → asked for one, nothing sent.
    fireEvent.press(screen.getByTestId('phase2-submit'));
    expect(screen.getByTestId('phase2-error-price')).toBeTruthy();
    expect(m.submitPhase2Interest).not.toHaveBeenCalled();

    fireEvent.press(screen.getByTestId('phase2-price-monthly_2_99'));
    fireEvent.press(screen.getByTestId('phase2-notify'));
    expect(screen.getByTestId('phase2-email').props.value).toBe('ana@example.com');
    fireEvent.press(screen.getByTestId('phase2-submit'));
    await flush();
    expect(m.submitPhase2Interest).toHaveBeenLastCalledWith({ price_choice: 'monthly_2_99', notify: true, email: 'ana@example.com' });
    expect(screen.getByTestId('phase2-error-email')).toBeTruthy();

    fireEvent.press(screen.getByTestId('phase2-submit'));
    await flush();
    expect(screen.getByTestId('phase2-thanks')).toBeTruthy();
    expect(screen.getByText('Hvala! Obvestili vas bomo.')).toBeTruthy();

    fireEvent.press(screen.getByTestId('phase2-back'));
    expect(screen.getByTestId('dashboard-phase2-subtitle').props.children).toBe('Hvala! Obvestili vas bomo.');
  });

  it('B1: »Ne obveščajte me več« sends {notify: false} and switches to the plain thanks without the promise', async () => {
    m.getParentDashboard.mockResolvedValue({
      ...makeScoredDashboard([makeScoredChild()], [PET7]),
      phase2_interest: { submitted: true, notify: true, price_choice: 'monthly_5_99' },
    });
    m.submitPhase2Interest.mockResolvedValueOnce({ status: 'saved' });
    const { client } = renderWithQuery(<ParentDashboardScreen />);
    fireEvent.press(await screen.findByTestId('dashboard-phase2'));
    expect(screen.getByText('Hvala! Obvestili vas bomo.')).toBeTruthy();
    expect(screen.getByTestId('phase2-body')).toBeTruthy();

    fireEvent.press(screen.getByTestId('phase2-withdraw'));
    await flush();
    expect(m.submitPhase2Interest).toHaveBeenCalledWith({ notify: false });
    expect(screen.getByText('Hvala za odgovor!')).toBeTruthy();
    expect(screen.queryByTestId('phase2-withdraw')).toBeNull();
    expect(screen.queryByTestId('phase2-body')).toBeNull();
    // The cached dashboard keeps the stored price.
    expect(client.getQueryData<{ phase2_interest: unknown }>(['parent', 'dashboard'])?.phase2_interest).toEqual({
      submitted: true,
      notify: false,
      price_choice: 'monthly_5_99',
    });
  });

  it('B1: a failed withdrawal keeps the button and says so', async () => {
    m.getParentDashboard.mockResolvedValue({
      ...makeScoredDashboard([makeScoredChild()], [PET7]),
      phase2_interest: { submitted: true, notify: true, price_choice: 'free' },
    });
    m.submitPhase2Interest.mockRejectedValueOnce(new ApiError('x', 500, null));
    renderWithQuery(<ParentDashboardScreen />);
    fireEvent.press(await screen.findByTestId('dashboard-phase2'));
    fireEvent.press(screen.getByTestId('phase2-withdraw'));
    await flush();
    expect(screen.getByTestId('phase2-withdraw-error')).toBeTruthy();
    expect(screen.getByTestId('phase2-withdraw')).toBeTruthy();
  });

  it('already submitted → only the thanks', async () => {
    m.getParentDashboard.mockResolvedValue(dashboard(true));
    renderWithQuery(<ParentDashboardScreen />);
    fireEvent.press(await screen.findByTestId('dashboard-phase2'));
    expect(screen.getByTestId('phase2-thanks')).toBeTruthy();
    expect(screen.queryByTestId('phase2-submit')).toBeNull();
  });
});
