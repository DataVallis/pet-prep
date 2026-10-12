/**
 * M2-01d — pure certificate logic: readers, which card the parent / child sees, form
 * defaults + validation, 422 codes → field errors, texts, push target, dashboard patch.
 */
import { ApiError, type ParentDashboardResponse } from '@/api/client';
import { i18n } from '@/i18n';
import {
  CERTIFICATE_MIN_SCORE,
  certificateDate,
  certificateErrorText,
  certificateFormDefaults,
  childCertificateEntry,
  classifyCertificateError,
  parentCertificateCard,
  pdfUrlOrNull,
  pushTargetChild,
  readCertificate,
  readChildCertificate,
  reportText,
  validateCertificateForm,
  type ParentCertificate,
} from '@/modules/certificate/certificate';
import { setDashboardCertificate } from '@/modules/certificate/useCertificate';
import { familyFromDashboard } from '@/modules/family/family';
import {
  makeCertificatePayload,
  makeFamilyPet,
  makeGeneratedCertificatePayload,
  makeReportPayload,
  makeScoredChild,
  makeScoredDashboard,
} from '@/test-utils/fixtures';

function cert(raw: Record<string, unknown>): ParentCertificate {
  const c = readCertificate(raw);
  if (c === null) throw new Error('fixture must be readable');
  return c;
}

afterEach(async () => {
  await i18n.changeLanguage('sl');
});

describe('readCertificate', () => {
  it('reads the server payload', () => {
    const c = cert(makeGeneratedCertificatePayload());
    expect(c).toMatchObject({
      id: 31,
      child_id: 2,
      pet_id: 7,
      outcome: 'certificate',
      reason: null,
      care_score: 86,
      number: 'PP-2026-000031',
      completed_local_date: '2026-10-12',
      generated: true,
      child_name: 'Luka',
      pet_name: 'Luna',
      locale: 'sl',
      defaults: { child_name: 'Luka', pet_name: 'Luna', locale: 'sl' },
      pdf_url: 'https://api.petprep.si/api/certificates/31/pdf?expires=1&v=abc&signature=sig',
    });
  });

  it.each([null, undefined, 'x', [], {}, { id: 1 }, { id: 1, outcome: 'gold' }, { outcome: 'certificate' }])('drops %p', (raw) => {
    expect(readCertificate(raw)).toBeNull();
  });

  it('a report never carries a number or a PDF; an unknown reason reads as low_score', () => {
    const c = cert(makeReportPayload({ number: 'PP-2026-1', pdf_url: 'https://x/y', generated: true, reason: 'other' }));
    expect(c).toMatchObject({ outcome: 'report', reason: 'low_score', number: null, pdf_url: null, generated: false });
  });

  it('no PDF URL before generation; defaults fall back to the nickname and Slovenian', () => {
    const c = cert(makeCertificatePayload({ pdf_url: 'https://x/y', defaults: { pet_name: 'Luna' } }));
    expect(c.pdf_url).toBeNull();
    expect(c.defaults).toEqual({ child_name: 'Luka', pet_name: 'Luna', locale: 'sl' });
  });
});

describe('pdfUrlOrNull', () => {
  it('keeps absolute http(s), resolves a relative path against the API, refuses other schemes', () => {
    expect(pdfUrlOrNull('https://api.petprep.si/api/certificates/1/pdf?signature=a')).toBe(
      'https://api.petprep.si/api/certificates/1/pdf?signature=a',
    );
    expect(pdfUrlOrNull('/api/certificates/1/pdf?signature=a', 'http://10.0.2.2:8000/')).toBe(
      'http://10.0.2.2:8000/api/certificates/1/pdf?signature=a',
    );
    expect(pdfUrlOrNull('javascript:alert(1)')).toBeNull();
    expect(pdfUrlOrNull('')).toBeNull();
    expect(pdfUrlOrNull(42)).toBeNull();
  });
});

describe('readChildCertificate', () => {
  it('reads a generated certificate', () => {
    expect(
      readChildCertificate({
        outcome: 'certificate',
        generated: true,
        child_name: 'Luka',
        pet_name: 'Luna',
        care_score: 86,
        number: 'PP-2026-000031',
        completed_at: '2026-10-12T16:00:00+02:00',
        locale: 'sl',
        pdf_url: 'https://api.petprep.si/api/certificates/31/pdf?signature=s',
      }),
    ).toEqual({
      outcome: 'certificate',
      generated: true,
      child_name: 'Luka',
      pet_name: 'Luna',
      care_score: 86,
      number: 'PP-2026-000031',
      completed_at: '2026-10-12T16:00:00+02:00',
      locale: 'sl',
      pdf_url: 'https://api.petprep.si/api/certificates/31/pdf?signature=s',
    });
  });

  it('a report never exposes a score, number or PDF to the child', () => {
    const c = readChildCertificate({ outcome: 'report', generated: true, care_score: 40, number: 'X', pdf_url: 'https://x' });
    expect(c).toMatchObject({ outcome: 'report', generated: false, care_score: null, number: null, pdf_url: null });
  });

  it.each([null, undefined, 'certificate', { outcome: 'x' }])('null for %p', (raw) => {
    expect(readChildCertificate(raw)).toBeNull();
  });
});

describe('view models', () => {
  it('parent: none / ready / generated / report', () => {
    expect(parentCertificateCard(null).kind).toBe('none');
    expect(parentCertificateCard(cert(makeCertificatePayload())).kind).toBe('ready');
    expect(parentCertificateCard(cert(makeGeneratedCertificatePayload())).kind).toBe('generated');
    expect(parentCertificateCard(cert(makeReportPayload({ reason: 'game_over' })))).toMatchObject({ kind: 'report', reason: 'game_over' });
  });

  it('child: none / waiting / generated / finished', () => {
    expect(childCertificateEntry(null).kind).toBe('none');
    expect(childCertificateEntry(readChildCertificate({ outcome: 'certificate', generated: false })).kind).toBe('waiting');
    expect(
      childCertificateEntry(readChildCertificate({ outcome: 'certificate', generated: true, pdf_url: 'https://x/y' })).kind,
    ).toBe('generated');
    expect(childCertificateEntry(readChildCertificate({ outcome: 'report' })).kind).toBe('finished');
  });
});

describe('form', () => {
  it('defaults: the server defaults first, what is printed once generated', () => {
    expect(certificateFormDefaults(cert(makeCertificatePayload()))).toEqual({ locale: 'sl', child_name: 'Luka', pet_name: 'Luna' });
    expect(
      certificateFormDefaults(cert(makeGeneratedCertificatePayload({ child_name: 'Luka M.', pet_name: 'Lunica', locale: 'en' }))),
    ).toEqual({ locale: 'en', child_name: 'Luka M.', pet_name: 'Lunica' });
  });

  it('validation: names required, at most 30 characters, normalised before sending', () => {
    expect(validateCertificateForm({ locale: 'sl', child_name: '  ', pet_name: 'Luna' })).toEqual({
      ok: false,
      errors: { child_name: 'required' },
    });
    expect(validateCertificateForm({ locale: 'en', child_name: 'Luka', pet_name: 'Ž'.repeat(31) })).toEqual({
      ok: false,
      errors: { pet_name: 'name_too_long' },
    });
    expect(validateCertificateForm({ locale: 'en', child_name: '  Ana   Marija ', pet_name: 'Ž'.repeat(30) })).toEqual({
      ok: true,
      body: { locale: 'en', child_name: 'Ana Marija', pet_name: 'Ž'.repeat(30) },
    });
  });

  it('server errors: 422 codes per field, 409 / 404 / 403 / network / other', () => {
    expect(
      classifyCertificateError(
        new ApiError('x', 422, { codes: { child_name: 'name_not_allowed', pet_name: 'name_too_long' }, reason: 'name_not_allowed' }),
      ),
    ).toEqual({ child_name: 'name_not_allowed', pet_name: 'name_too_long' });
    expect(classifyCertificateError(new ApiError('x', 422, { codes: { pet_name: 'weird' } }))).toEqual({ pet_name: 'name_invalid' });
    expect(classifyCertificateError(new ApiError('x', 422, { codes: { locale: 'locale_invalid' } }))).toEqual({ form: 'locale_invalid' });
    expect(classifyCertificateError(new ApiError('x', 422, {}))).toEqual({ form: 'server' });
    expect(classifyCertificateError(new ApiError('x', 409, { reason: 'not_eligible' }))).toEqual({ form: 'not_eligible' });
    expect(classifyCertificateError(new ApiError('x', 404, {}))).toEqual({ form: 'not_found' });
    expect(classifyCertificateError(new ApiError('x', 403, {}))).toEqual({ form: 'forbidden' });
    expect(classifyCertificateError(new ApiError('x', 500, {}))).toEqual({ form: 'server' });
    expect(classifyCertificateError(new TypeError('Network request failed'))).toEqual({ form: 'offline' });
  });

  it('error texts (sl + en)', () => {
    expect(certificateErrorText('name_too_long')).toBe('Ime je predolgo (največ 30 znakov).');
    expect(certificateErrorText('not_eligible')).toBe('Za ta izziv certifikata ni mogoče ustvariti.');
  });
});

describe('texts', () => {
  it('date: "12. 10. 2026" / "12 Oct 2026"', async () => {
    expect(certificateDate('2026-10-12')).toBe('12. 10. 2026');
    expect(certificateDate('2026-10-12T16:00:00+02:00')).toBe('12. 10. 2026');
    expect(certificateDate(null)).toBe('');
    await i18n.changeLanguage('en');
    expect(certificateDate('2026-10-12')).toBe('12 Oct 2026');
  });

  it('report: the threshold or the game over, factual and kind', () => {
    expect(CERTIFICATE_MIN_SCORE).toBe(70);
    expect(reportText({ reason: 'low_score', care_score: 58 })).toBe(
      'Končni Care Score je 58. Za certifikat je potrebnih vsaj 70 točk, zato certifikat ni bil izdan.',
    );
    expect(reportText({ reason: 'low_score', care_score: null })).toContain('vsaj 70 točk');
    expect(reportText({ reason: 'game_over', care_score: 40 })).toBe(
      'Izziv se je končal predčasno, ker je ljubljenček odšel v zavetišče. Certifikat zato ni bil izdan.',
    );
  });
});

describe('pushTargetChild', () => {
  const a = { id: 2, pet_id: 7, certificate: null };
  const b = { id: 5, pet_id: 7, certificate: cert(makeGeneratedCertificatePayload({ id: 40, child_id: 5 })) };
  const c = { id: 6, pet_id: 7, certificate: cert(makeCertificatePayload({ id: 41, child_id: 6, completed_at: '2026-10-11T10:00:00+02:00' })) };
  const other = { id: 9, pet_id: 8, certificate: null };

  it('other pushes: the first child of the pet', () => {
    expect(pushTargetChild([other, a, b], 7, 'critical_alert')?.id).toBe(2);
    expect(pushTargetChild([other], 7)).toBeUndefined();
  });

  it('challenge_completed: a certificate waiting to be generated, else the newest finished, else the first', () => {
    expect(pushTargetChild([a, b, c], 7, 'challenge_completed')?.id).toBe(6);
    expect(pushTargetChild([a, b], 7, 'challenge_completed')?.id).toBe(5);
    expect(pushTargetChild([a, other], 7, 'challenge_completed')?.id).toBe(2);
  });
});

describe('setDashboardCertificate', () => {
  it("replaces the child's certificate in the cached dashboard (read back through the family reader)", () => {
    const old = makeScoredDashboard(
      [makeScoredChild({ certificate: cert(makeCertificatePayload()) }), makeScoredChild({ id: 5, name: 'Maja' })],
      [makeFamilyPet({ id: 7 })],
    ) as unknown as ParentDashboardResponse;
    const generated = cert(makeGeneratedCertificatePayload());

    const next = setDashboardCertificate(old, generated);

    const family = familyFromDashboard(next);
    expect(family?.children.find((c) => c.id === 2)?.certificate).toEqual(generated);
    expect(family?.children.find((c) => c.id === 5)?.certificate).toBeNull();
  });

  it('leaves the cache alone when no child matches', () => {
    const old = makeScoredDashboard([makeScoredChild({ id: 5 })], [makeFamilyPet({ id: 7 })]) as unknown as ParentDashboardResponse;
    expect(setDashboardCertificate(old, cert(makeCertificatePayload()))).toBe(old);
    expect(setDashboardCertificate(undefined, cert(makeCertificatePayload()))).toBeUndefined();
  });
});

describe('name_unsupported_script', () => {
  it('maps the server code to the field', () => {
    expect(
      classifyCertificateError(new ApiError('x', 422, { codes: { child_name: 'name_unsupported_script' }, reason: 'name_unsupported_script' })),
    ).toEqual({ child_name: 'name_unsupported_script' });
  });
});
