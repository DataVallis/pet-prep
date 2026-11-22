/**
 * M5-08: H6 dashboard-opened signal, the once-only acquisition card and the H7 fake door — and the admin
 * switch »Validation signals« that gates them (`validation_signals`, dashboard first, then GET /api/user).
 */
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
/** The family has the switch on (test family or mode All). */
const on = () => ({ ...makeScoredDashboard([makeScoredChild()], [PET7]), validation_signals: true });
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
    m.getParentDashboard.mockResolvedValue(on());
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
    m.getParentDashboard.mockResolvedValue(on());
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
    m.getParentDashboard.mockResolvedValue(on());
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
    m.getParentDashboard.mockResolvedValue(on());
    renderWithQuery(<ParentDashboardScreen />);
    await flush(50);
    expect(screen.queryByTestId('acquisition-card')).toBeNull();
    expect(m.getUser).not.toHaveBeenCalled();
  });
});

describe('H7 fake door', () => {
  const dashboard = (submitted: boolean) => ({ ...on(), phase2_interest: { submitted } });

  it('older server → no row', async () => {
    m.getParentDashboard.mockResolvedValue(on());
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
      ...on(),
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
      ...on(),
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

describe('admin switch »Validation signals«', () => {
  const plain = () => makeScoredDashboard([makeScoredChild()], [PET7]);

  it('off (dashboard false): no card, no row, no H6, nothing sent — even when GET /api/user would say on', async () => {
    m.getUser.mockResolvedValue({ ...PARENT, pet: null, acquisition_source: null, validation_signals: true });
    m.getParentDashboard.mockResolvedValue({ ...plain(), validation_signals: false, phase2_interest: { submitted: false, notify: false, price_choice: null } });
    renderWithQuery(<ParentDashboardScreen />);
    expect(await screen.findByTestId('child-card-2')).toBeTruthy();
    await flush(OPEN_GRACE_MS + 50);
    expect(screen.queryByTestId('acquisition-card')).toBeNull();
    expect(screen.queryByTestId('dashboard-phase2')).toBeNull();
    expect(m.reportDashboardOpened).not.toHaveBeenCalled();
    // The dashboard field wins: GET /api/user is not asked for the flag.
    expect(m.getUser).not.toHaveBeenCalled();
  });

  it('field missing everywhere (older server): hidden and nothing sent', async () => {
    m.getUser.mockResolvedValue({ ...PARENT, pet: null, acquisition_source: null });
    m.getParentDashboard.mockResolvedValue({ ...plain(), phase2_interest: { submitted: false, notify: false, price_choice: null } });
    renderWithQuery(<ParentDashboardScreen />);
    expect(await screen.findByTestId('child-card-2')).toBeTruthy();
    await flush(OPEN_GRACE_MS + 50);
    expect(m.getUser).toHaveBeenCalledTimes(1);
    expect(screen.queryByTestId('acquisition-card')).toBeNull();
    expect(screen.queryByTestId('dashboard-phase2')).toBeNull();
    expect(m.reportDashboardOpened).not.toHaveBeenCalled();
  });

  it('test family on via GET /api/user (dashboard without the field): card, row and H6', async () => {
    m.getUser.mockResolvedValue({ ...PARENT, pet: null, acquisition_source: null, validation_signals: true });
    m.getParentDashboard.mockResolvedValue({ ...plain(), phase2_interest: { submitted: false, notify: false, price_choice: null } });
    renderWithQuery(<ParentDashboardScreen />);
    expect(await screen.findByTestId('acquisition-card')).toBeTruthy();
    expect(screen.getByTestId('dashboard-phase2')).toBeTruthy();
    await flush(OPEN_GRACE_MS + 50);
    expect(m.reportDashboardOpened).toHaveBeenCalledWith('launch');
  });

  it('on (test family or All, dashboard true): card, row and H6', async () => {
    m.getUser.mockResolvedValue({ ...PARENT, pet: null, acquisition_source: null });
    m.getParentDashboard.mockResolvedValue({ ...on(), phase2_interest: { submitted: false, notify: false, price_choice: null } });
    renderWithQuery(<ParentDashboardScreen />);
    expect(await screen.findByTestId('acquisition-card')).toBeTruthy();
    expect(screen.getByTestId('dashboard-phase2')).toBeTruthy();
    await flush(OPEN_GRACE_MS + 50);
    expect(m.reportDashboardOpened).toHaveBeenCalledTimes(1);
  });

  it('QA #1500 m4: a refetch that turns the switch on shows the entries but sends no `launch`', async () => {
    m.getParentDashboard.mockResolvedValue({ ...plain(), validation_signals: false, phase2_interest: { submitted: false, notify: false, price_choice: null } });
    const { client } = renderWithQuery(<ParentDashboardScreen />);
    expect(await screen.findByTestId('child-card-2')).toBeTruthy();
    await flush(OPEN_GRACE_MS + 50);
    m.getParentDashboard.mockResolvedValue({ ...on(), phase2_interest: { submitted: false, notify: false, price_choice: null } });
    await act(async () => {
      await client.refetchQueries();
    });
    await flush(OPEN_GRACE_MS + 50);
    expect(screen.getByTestId('dashboard-phase2')).toBeTruthy();
    expect(m.reportDashboardOpened).not.toHaveBeenCalled();
  });

  it('withdrawal while off: the thank-you row stays, no »viewed«, »Ne obveščajte me več« works', async () => {
    m.getParentDashboard.mockResolvedValue({
      ...plain(),
      validation_signals: false,
      phase2_interest: { submitted: true, notify: true, price_choice: 'monthly_2_99' },
    });
    m.submitPhase2Interest.mockResolvedValueOnce({ status: 'saved' });
    renderWithQuery(<ParentDashboardScreen />);
    fireEvent.press(await screen.findByTestId('dashboard-phase2'));
    expect(m.reportPhase2Viewed).not.toHaveBeenCalled();
    expect(screen.getByTestId('phase2-thanks')).toBeTruthy();
    expect(screen.queryByTestId('phase2-submit')).toBeNull();
    fireEvent.press(screen.getByTestId('phase2-withdraw'));
    await flush();
    expect(m.submitPhase2Interest).toHaveBeenCalledWith({ notify: false });
    expect(screen.getByText('Hvala za odgovor!')).toBeTruthy();
    // Back on the dashboard: no live consent and the switch off → no row any more.
    fireEvent.press(screen.getByTestId('phase2-back'));
    expect(screen.queryByTestId('dashboard-phase2')).toBeNull();
  });

  it('422 feature_unavailable: calm message and the dashboard is refetched', async () => {
    m.getParentDashboard.mockResolvedValue({ ...on(), phase2_interest: { submitted: false, notify: false, price_choice: null } });
    m.submitPhase2Interest.mockRejectedValueOnce(new ApiError('x', 422, { message: 'x', code: 'feature_unavailable' }));
    renderWithQuery(<ParentDashboardScreen />);
    fireEvent.press(await screen.findByTestId('dashboard-phase2'));
    fireEvent.press(screen.getByTestId('phase2-price-free'));
    const before = m.getParentDashboard.mock.calls.length;
    fireEvent.press(screen.getByTestId('phase2-submit'));
    await flush(50);
    expect(screen.getByText('Ta del trenutno ni na voljo. Poskusite znova pozneje.')).toBeTruthy();
    expect(m.getParentDashboard.mock.calls.length).toBeGreaterThan(before);
  });
});
