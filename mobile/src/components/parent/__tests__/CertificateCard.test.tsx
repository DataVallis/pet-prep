/**
 * M2-01d — the parent's certificate card and the "Ustvari certifikat" form: which card
 * shows, prefill, client-side validation, submit (body, loading, cache update), the
 * server's 422 codes as field errors, 409, opening the PDF with a fresh URL, the report.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';
import { Linking } from 'react-native';

import { ApiError, api, type ParentDashboardResponse } from '@/api/client';
import CertificateCard from '@/components/parent/CertificateCard';
import ChildOverviewCard from '@/components/parent/ChildOverviewCard';
import { i18n } from '@/i18n';
import { readCertificate, type ParentCertificate } from '@/modules/certificate/certificate';
import { familyFromDashboard, normalizePet } from '@/modules/family/family';
import { parentDashboardKey } from '@/modules/family/live';
import {
  makeCertificatePayload,
  makeFamilyPet,
  makeGeneratedCertificatePayload,
  makeReportPayload,
  makeScoredChild,
  makeScoredDashboard,
} from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, generateCertificate: jest.fn(), getParentDashboard: jest.fn() } };
});

const generateCertificate = api.generateCertificate as jest.Mock;
const getParentDashboard = api.getParentDashboard as jest.Mock;

const ID = 'cert';
const FORM = `${ID}-form`;

function cert(raw: Record<string, unknown>): ParentCertificate {
  const c = readCertificate(raw);
  if (c === null) throw new Error('fixture must be readable');
  return c;
}

function dashboardWith(raw: Record<string, unknown> | null) {
  return makeScoredDashboard(
    [makeScoredChild({ certificate: raw === null ? null : cert(raw) })],
    [makeFamilyPet({ id: 7, breed_type: 'border_collie' })],
  ) as unknown as ParentDashboardResponse;
}

function renderCard(raw: Record<string, unknown>, onOpenReport?: () => void) {
  const utils = renderWithQuery(<CertificateCard certificate={cert(raw)} childName="Luka" onOpenReport={onOpenReport} testID={ID} />);
  utils.client.setQueryDefaults(parentDashboardKey, { queryFn: api.getParentDashboard });
  utils.client.setQueryData(parentDashboardKey, dashboardWith(raw));
  return utils;
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
  getParentDashboard.mockReturnValue(new Promise(() => undefined));
});

afterEach(async () => {
  openURL.mockRestore();
  await i18n.changeLanguage('sl');
});

describe('CertificateCard — which card', () => {
  it('renders nothing while the challenge runs (no query client needed)', () => {
    render(<CertificateCard certificate={null} childName="Luka" testID={ID} />);
    expect(screen.queryByTestId(`${ID}-ready`)).toBeNull();
  });

  it('certificate, not generated: "Izziv je končan", the final score and "Ustvari certifikat"', () => {
    renderCard(makeCertificatePayload());
    expect(screen.getByTestId(`${ID}-ready`)).toBeTruthy();
    expect(screen.getByText('Izziv je končan')).toBeTruthy();
    expect(screen.getByTestId(`${ID}-score`)).toHaveTextContent('86');
    expect(screen.getByText('Zaključeno 12. 10. 2026')).toBeTruthy();
    expect(screen.getByText('Ustvari certifikat')).toBeTruthy();
  });

  it('generated: number, printed names, "Odpri certifikat" and "Uredi in ustvari znova"', () => {
    renderCard(makeGeneratedCertificatePayload());
    expect(screen.getByTestId(`${ID}-generated`)).toBeTruthy();
    expect(screen.getByTestId(`${ID}-number`)).toHaveTextContent('Št. PP-2026-000031');
    expect(screen.getByTestId(`${ID}-printed`)).toHaveTextContent('Na certifikatu: Luka · Luna');
    expect(screen.getByText('Odpri certifikat')).toBeTruthy();
    expect(screen.getByText('Uredi in ustvari znova')).toBeTruthy();
  });

  it('report (low score): "Poročilo o izzivu", the score and the 70-point threshold; the link opens the report', () => {
    const onOpenReport = jest.fn();
    renderCard(makeReportPayload(), onOpenReport);
    expect(screen.getByText('Poročilo o izzivu')).toBeTruthy();
    expect(screen.getByTestId(`${ID}-score`)).toHaveTextContent('58');
    expect(screen.getByTestId(`${ID}-reason`)).toHaveTextContent(
      'Končni Care Score je 58. Za certifikat je potrebnih vsaj 70 točk, zato certifikat ni bil izdan.',
    );
    expect(screen.queryByText('Ustvari certifikat')).toBeNull();
    fireEvent.press(screen.getByTestId(`${ID}-open-report`));
    expect(onOpenReport).toHaveBeenCalledTimes(1);
  });

  it('report (game over): the shelter reason, no link without a report screen', () => {
    renderCard(makeReportPayload({ reason: 'game_over', care_score: 40 }));
    expect(screen.getByTestId(`${ID}-reason`)).toHaveTextContent(/odšel v zavetišče/);
    expect(screen.queryByTestId(`${ID}-open-report`)).toBeNull();
    expect(screen.getByText('Celoten pregled 12 tednov najdete v poročilu otroka.')).toBeTruthy();
  });

  it('English', async () => {
    await act(async () => {
      await i18n.changeLanguage('en');
    });
    renderCard(makeCertificatePayload());
    expect(screen.getByText('Challenge complete')).toBeTruthy();
    expect(screen.getByText('Create certificate')).toBeTruthy();
  });

  it('on the overview card: the block appears only once the child has a certificate', () => {
    const family = familyFromDashboard(dashboardWith(makeCertificatePayload()));
    const child = family!.children[0];
    const pet = normalizePet(makeFamilyPet({ id: 7 }));
    const onOpen = jest.fn();
    const first = renderWithQuery(
      <ChildOverviewCard child={child} pet={pet} timezone="Europe/Ljubljana" onOpen={onOpen} onChildPin={jest.fn()} />,
    );
    expect(screen.getByTestId('child-certificate-2-ready')).toBeTruthy();
    first.unmount();
    render(<ChildOverviewCard child={{ ...child, certificate: null }} pet={pet} timezone="Europe/Ljubljana" onOpen={onOpen} onChildPin={jest.fn()} />);
    expect(screen.queryByTestId('child-certificate-2-ready')).toBeNull();
  });
});

describe('CertificateFormSheet — create', () => {
  function openForm(raw: Record<string, unknown> = makeCertificatePayload()) {
    const utils = renderCard(raw);
    fireEvent.press(screen.getByTestId(raw.generated === true ? `${ID}-edit` : `${ID}-create`));
    return utils;
  }

  it('prefills the defaults: Slovenian, the nickname and the pet name, with the no-surname note', () => {
    openForm();
    expect(screen.getByText('Certifikat odgovornosti')).toBeTruthy();
    expect(screen.getByTestId(`${FORM}-child_name`).props.value).toBe('Luka');
    expect(screen.getByTestId(`${FORM}-pet_name`).props.value).toBe('Luna');
    expect(screen.getByTestId(`${FORM}-locale-sl`).props.accessibilityState).toMatchObject({ selected: true });
    expect(screen.getByTestId(`${FORM}-locale-en`).props.accessibilityState).toMatchObject({ selected: false });
    expect(screen.getByTestId(`${FORM}-hint`)).toHaveTextContent('Ime bo izpisano na certifikatu. Priimek ni potreben.');
  });

  it('client-side: an empty name or more than 30 characters never reaches the server', () => {
    openForm();
    fireEvent.changeText(screen.getByTestId(`${FORM}-child_name`), '   ');
    fireEvent.changeText(screen.getByTestId(`${FORM}-pet_name`), 'L'.repeat(31));
    fireEvent.press(screen.getByTestId(`${FORM}-submit`));
    expect(screen.getByTestId(`${FORM}-child_name-error`)).toHaveTextContent('Vpišite ime.');
    expect(screen.getByTestId(`${FORM}-pet_name-error`)).toHaveTextContent('Ime je predolgo (največ 30 znakov).');
    expect(generateCertificate).not.toHaveBeenCalled();
  });

  it('"Ustvari": sends language + names, shows the loading state, closes and puts the result in the cache', async () => {
    let resolve: (v: unknown) => void = () => undefined;
    generateCertificate.mockReturnValue(new Promise((r) => (resolve = r)));
    const { client } = openForm();
    const invalidate = jest.spyOn(client, 'invalidateQueries');

    fireEvent.press(screen.getByTestId(`${FORM}-locale-en`));
    fireEvent.changeText(screen.getByTestId(`${FORM}-pet_name`), '  Lunica ');
    fireEvent.press(screen.getByTestId(`${FORM}-submit`));
    await settle();

    expect(generateCertificate).toHaveBeenCalledWith(31, { locale: 'en', child_name: 'Luka', pet_name: 'Lunica' });
    expect(screen.getByTestId(`${FORM}-loading`)).toBeTruthy();
    expect(screen.getByTestId(`${FORM}-submit`).props.accessibilityState).toMatchObject({ busy: true, disabled: true });

    await act(async () => {
      resolve(makeGeneratedCertificatePayload({ pet_name: 'Lunica', locale: 'en' }));
    });
    await settle();

    expect(screen.queryByTestId(FORM)).toBeNull();
    const child = familyFromDashboard(client.getQueryData<ParentDashboardResponse>(parentDashboardKey))?.children[0];
    expect(child?.certificate).toMatchObject({ generated: true, pet_name: 'Lunica', locale: 'en' });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: parentDashboardKey });
  });

  it('server 422 codes appear under their fields; the sheet stays open', async () => {
    generateCertificate.mockRejectedValue(
      new ApiError('x', 422, { codes: { child_name: 'name_not_allowed', pet_name: 'name_invalid' }, reason: 'name_not_allowed' }),
    );
    openForm();
    fireEvent.press(screen.getByTestId(`${FORM}-submit`));
    await settle();

    expect(screen.getByTestId(`${FORM}-child_name-error`)).toHaveTextContent('Izberite drugo ime.');
    expect(screen.getByTestId(`${FORM}-pet_name-error`)).toHaveTextContent('Ime vsebuje nedovoljene znake.');
    expect(screen.getByTestId(FORM)).toBeTruthy();

    // Typing clears that field's error.
    fireEvent.changeText(screen.getByTestId(`${FORM}-child_name`), 'Lu');
    expect(screen.queryByTestId(`${FORM}-child_name-error`)).toBeNull();
  });

  it('409 not_eligible / offline → one message above the button', async () => {
    generateCertificate.mockRejectedValueOnce(new ApiError('x', 409, { reason: 'not_eligible' }));
    openForm();
    fireEvent.press(screen.getByTestId(`${FORM}-submit`));
    await settle();
    expect(screen.getByTestId(`${FORM}-error`)).toHaveTextContent('Za ta izziv certifikata ni mogoče ustvariti.');

    generateCertificate.mockRejectedValueOnce(new TypeError('Network request failed'));
    fireEvent.press(screen.getByTestId(`${FORM}-submit`));
    await settle();
    expect(screen.getByTestId(`${FORM}-error`)).toHaveTextContent('Ni povezave. Poskusite znova.');
  });

  it('"Uredi in ustvari znova": the same form prefilled with what is printed now', () => {
    openForm(makeGeneratedCertificatePayload({ child_name: 'Luka M.', pet_name: 'Lunica', locale: 'en' }));
    expect(screen.getByText('Uredi certifikat')).toBeTruthy();
    expect(screen.getByTestId(`${FORM}-child_name`).props.value).toBe('Luka M.');
    expect(screen.getByTestId(`${FORM}-pet_name`).props.value).toBe('Lunica');
    expect(screen.getByTestId(`${FORM}-locale-en`).props.accessibilityState).toMatchObject({ selected: true });
  });

  it('"Prekliči" closes without a request', () => {
    openForm();
    fireEvent.press(screen.getByTestId(`${FORM}-cancel`));
    expect(screen.queryByTestId(FORM)).toBeNull();
    expect(generateCertificate).not.toHaveBeenCalled();
  });
});

describe('CertificateCard — open the PDF', () => {
  it('refreshes the signed URL first and opens the fresh one in the system viewer', async () => {
    const fresh = 'https://api.petprep.si/api/certificates/31/pdf?expires=2&v=abc&signature=new';
    getParentDashboard.mockResolvedValue(dashboardWith(makeGeneratedCertificatePayload({ pdf_url: fresh })));
    renderCard(makeGeneratedCertificatePayload());

    fireEvent.press(screen.getByTestId(`${ID}-open`));
    await settle();
    await settle();

    expect(getParentDashboard).toHaveBeenCalled();
    expect(openURL).toHaveBeenCalledWith(fresh);
    expect(screen.queryByTestId(`${ID}-open-error`)).toBeNull();
  });

  it('refresh failed → the URL at hand; the viewer failed → a short error', async () => {
    getParentDashboard.mockRejectedValue(new TypeError('offline'));
    openURL.mockRejectedValueOnce(new Error('no handler'));
    renderCard(makeGeneratedCertificatePayload());

    fireEvent.press(screen.getByTestId(`${ID}-open`));
    await settle();
    await settle();

    expect(openURL).toHaveBeenCalledWith('https://api.petprep.si/api/certificates/31/pdf?expires=1&v=abc&signature=sig');
    expect(screen.getByTestId(`${ID}-open-error`)).toHaveTextContent(
      'Certifikata ni bilo mogoče odpreti. Preverite povezavo in poskusite znova.',
    );
  });
});
