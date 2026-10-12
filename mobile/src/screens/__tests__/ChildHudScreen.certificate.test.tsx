/**
 * M2-01d — the child's finished challenge: the award badge in the HUD header and the sheet
 * (the certificate as a card like the PDF + "Odpri PDF"; "the parent will prepare it"; a kind
 * note for a report — never a score). No badge while the challenge runs.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';
import { Linking } from 'react-native';

import { api, type ChildPetState } from '@/api/client';
import ChildCertificateSheet from '@/components/ChildCertificateSheet';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { useAppStore } from '@/store/appStore';
import { i18n } from '@/i18n';
import { readChildCertificate } from '@/modules/certificate/certificate';
import { makeLiveChildState, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, getChildPet: jest.fn(), getChildPetGrowth: jest.fn(), syncSteps: jest.fn(), logout: jest.fn() },
  };
});
jest.mock('@/hooks/usePetWebSocket', () => ({ usePetWebSocket: jest.fn() }));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(20_000);

const PDF = 'https://api.petprep.si/api/certificates/31/pdf?expires=1&v=abc&signature=sig';

const GENERATED = {
  outcome: 'certificate',
  generated: true,
  child_name: 'Luka',
  pet_name: 'Luna',
  care_score: 86,
  number: 'PP-2026-000031',
  completed_at: '2026-10-12T16:00:00+02:00',
  locale: 'sl',
  pdf_url: PDF,
};
const WAITING = { ...GENERATED, generated: false, child_name: null, pet_name: null, locale: null, pdf_url: null };
const REPORT = {
  outcome: 'report',
  generated: false,
  child_name: null,
  pet_name: null,
  care_score: null,
  number: null,
  completed_at: null,
  locale: null,
  pdf_url: null,
};

function stateWith(certificate: Record<string, unknown> | null): ChildPetState {
  return { ...makeLiveChildState(), certificate } as unknown as ChildPetState;
}

async function settle() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
}

let openURL: jest.SpyInstance;

beforeEach(() => {
  jest.clearAllMocks();
  openURL = jest.spyOn(Linking, 'openURL').mockResolvedValue(true);
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({
    token: 'child-token',
    user: { id: 2, name: 'Luka', email: null, role: 'child' },
    pet: makePet({ id: 7 }),
    awaitingContract: false,
  });
  useAppStore.getState().setWsStatus('connected');
});

afterEach(async () => {
  openURL.mockRestore();
  await i18n.changeLanguage('sl');
});

describe('HUD — certificate entry', () => {
  it('no badge while the challenge runs', async () => {
    (api.getChildPet as jest.Mock).mockResolvedValue(stateWith(null));
    renderWithQuery(<ChildHudScreen />);
    await screen.findByTestId('action-feed');
    expect(screen.queryByTestId('hud-certificate-open')).toBeNull();
  });

  it('generated: "Moj certifikat" opens the certificate card; "Odpri PDF" opens the fresh signed URL', async () => {
    const fresh = `${PDF}&fresh=1`;
    (api.getChildPet as jest.Mock).mockResolvedValueOnce(stateWith(GENERATED)).mockResolvedValue(stateWith({ ...GENERATED, pdf_url: fresh }));
    renderWithQuery(<ChildHudScreen />);
    await screen.findByTestId('action-feed');

    const open = screen.getByTestId('hud-certificate-open');
    expect(open.props.accessibilityLabel).toBe('Moj certifikat');
    fireEvent.press(open);

    expect(screen.getByText('Certifikat odgovornosti')).toBeTruthy();
    expect(screen.getByTestId('hud-certificate-name')).toHaveTextContent('Luka');
    expect(screen.getByTestId('hud-certificate-score')).toHaveTextContent('86');
    expect(screen.getByTestId('hud-certificate-pet')).toHaveTextContent('Luna');
    expect(screen.getByTestId('hud-certificate-number')).toHaveTextContent('petprep.si · Št. PP-2026-000031');
    expect(screen.getByTestId('hud-certificate-mark')).toBeTruthy();

    fireEvent.press(screen.getByTestId('hud-certificate-open-pdf'));
    await settle();
    await settle();
    expect(openURL).toHaveBeenCalledWith(fresh);

    fireEvent.press(screen.getByTestId('hud-certificate-close'));
    await settle();
    expect(screen.queryByTestId('hud-certificate')).toBeNull();
  });

  it('not generated yet: "Izziv je končan! Starš ti bo pripravil certifikat."', async () => {
    (api.getChildPet as jest.Mock).mockResolvedValue(stateWith(WAITING));
    renderWithQuery(<ChildHudScreen />);
    await screen.findByTestId('action-feed');

    fireEvent.press(screen.getByTestId('hud-certificate-open'));
    expect(screen.getByTestId('hud-certificate-waiting')).toBeTruthy();
    expect(screen.getByText('Izziv je končan!')).toBeTruthy();
    expect(screen.getByText('Starš ti bo pripravil certifikat.')).toBeTruthy();
    expect(screen.queryByTestId('hud-certificate-open-pdf')).toBeNull();
  });
});

describe('ChildCertificateSheet', () => {
  it('report: a kind "finished" note — no score, no number, no PDF', () => {
    renderWithQuery(<ChildCertificateSheet visible certificate={readChildCertificate(REPORT)} onClose={jest.fn()} />);
    expect(screen.getByTestId('hud-certificate-finished')).toBeTruthy();
    expect(screen.getByText('Izziv je končan')).toBeTruthy();
    expect(screen.getByText(/Bravo, da si vztrajal\(a\)!/)).toBeTruthy();
    expect(screen.queryByTestId('hud-certificate-score')).toBeNull();
    expect(screen.queryByTestId('hud-certificate-open-pdf')).toBeNull();
    expect(screen.queryByText(/Care Score/)).toBeNull();
  });

  it('nothing without a certificate', () => {
    renderWithQuery(<ChildCertificateSheet visible certificate={null} onClose={jest.fn()} />);
    expect(screen.queryByTestId('hud-certificate')).toBeNull();
  });

  it('English', async () => {
    await act(async () => {
      await i18n.changeLanguage('en');
    });
    renderWithQuery(<ChildCertificateSheet visible certificate={readChildCertificate(GENERATED)} onClose={jest.fn()} />);
    expect(screen.getByText('Responsibility Certificate')).toBeTruthy();
    expect(screen.getByText('This certificate is awarded to')).toBeTruthy();
    expect(screen.getByText('12 Oct 2026')).toBeTruthy();
    expect(screen.getByText('Open PDF')).toBeTruthy();
  });
});
