/**
 * M2-01d — Responsibility Certificate (`docs/product/CERTIFICATE_SPEC.md`).
 *
 * Pure logic for both apps: readers for the loose server payloads (Scramble types the
 * dashboard / child-state `certificate` as a string), the view models that decide which
 * card the parent and the child see, the "Ustvari certifikat" form (defaults, client-side
 * checks that mirror the server's 1–30 characters, 422 codes → field errors) and the
 * push target of `challenge_completed`.
 *
 * The server is the authority for everything: outcome, score, number and dates are frozen
 * there; the app never derives "certificate vs report" itself. The child never sees a
 * score or a reason for a report (no shaming).
 */

import { ApiError } from '@/api/client';
import { ENV } from '@/config/env';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';
import { normalizePetName } from '@/modules/petName/petName';

/** Final Care Score needed for a certificate (backend `CERTIFICATE_MIN_SCORE`, CERTIFICATE_SPEC §1). */
export const CERTIFICATE_MIN_SCORE = 70;
/** Printed names: 1–30 characters (backend `ChallengeCertificateService::MAX_NAME_LENGTH`). */
export const CERTIFICATE_NAME_MAX_LENGTH = 30;

export const CERTIFICATE_LOCALES = ['sl', 'en'] as const;
export type CertificateLocale = (typeof CERTIFICATE_LOCALES)[number];

export type CertificateOutcome = 'certificate' | 'report';
export type CertificateReason = 'low_score' | 'game_over';

/** One challenge end for the parent (`CertificatePayload`: list item, generate body, dashboard child). */
export interface ParentCertificate {
  id: number;
  child_id: number;
  child_nickname: string | null;
  pet_id: number;
  species: string;
  breed_type: string;
  outcome: CertificateOutcome;
  reason: CertificateReason | null;
  care_score: number | null;
  number: string | null;
  started_at: string;
  completed_at: string;
  /** Family-local end day `YYYY-MM-DD`. */
  completed_local_date: string;
  generated: boolean;
  generated_at: string | null;
  /** As printed on the last generated PDF (null before the first generation). */
  child_name: string | null;
  pet_name: string | null;
  locale: CertificateLocale | null;
  defaults: { child_name: string; pet_name: string; locale: CertificateLocale };
  /** Signed, expiring (60–90 min) absolute URL of the PDF; null until generated. */
  pdf_url: string | null;
}

/** The child's own challenge end (`ChildCertificatePayload`); a report carries nothing else. */
export interface ChildCertificate {
  outcome: CertificateOutcome;
  generated: boolean;
  child_name: string | null;
  pet_name: string | null;
  care_score: number | null;
  number: string | null;
  completed_at: string | null;
  locale: CertificateLocale | null;
  pdf_url: string | null;
}

// ──────────────────────────────────────────────────────────────
//  Readers
// ──────────────────────────────────────────────────────────────

type Obj = Record<string, unknown>;

function obj(value: unknown): Obj | null {
  return typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as Obj) : null;
}
function str(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null;
}
function int(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? Math.round(value) : null;
}
function locale(value: unknown): CertificateLocale | null {
  return value === 'en' || value === 'sl' ? value : null;
}
function outcome(value: unknown): CertificateOutcome | null {
  return value === 'certificate' || value === 'report' ? value : null;
}
function reason(value: unknown): CertificateReason | null {
  return value === 'low_score' || value === 'game_over' ? value : null;
}

/**
 * A signed PDF URL the app may open: absolute http(s) as the server sends it
 * (`APP_URL` + relative signed route); a bare relative path is resolved against the
 * API base. Anything else (other schemes) → null.
 */
export function pdfUrlOrNull(value: unknown, base: string = ENV.API_BASE_URL): string | null {
  const url = str(value);
  if (url === null) return null;
  if (/^https?:\/\//i.test(url)) return url;
  if (url.startsWith('/')) return `${base.replace(/\/+$/, '')}${url}`;
  return null;
}

/** `CertificatePayload` → typed; malformed (no id / outcome) → null. */
export function readCertificate(raw: unknown): ParentCertificate | null {
  const c = obj(raw);
  if (c === null) return null;
  const id = int(c.id);
  const kind = outcome(c.outcome);
  if (id === null || kind === null) return null;
  const d = obj(c.defaults) ?? {};
  const generated = c.generated === true;
  const pdfUrl = generated ? pdfUrlOrNull(c.pdf_url) : null;
  return {
    id,
    child_id: int(c.child_id) ?? 0,
    child_nickname: str(c.child_nickname),
    pet_id: int(c.pet_id) ?? 0,
    species: str(c.species) ?? 'dog',
    breed_type: str(c.breed_type) ?? '',
    outcome: kind,
    reason: kind === 'report' ? (reason(c.reason) ?? 'low_score') : null,
    care_score: int(c.care_score),
    number: kind === 'certificate' ? str(c.number) : null,
    started_at: str(c.started_at) ?? '',
    completed_at: str(c.completed_at) ?? '',
    completed_local_date: str(c.completed_local_date) ?? '',
    generated: kind === 'certificate' && generated,
    generated_at: str(c.generated_at),
    child_name: str(c.child_name),
    pet_name: str(c.pet_name),
    locale: locale(c.locale),
    defaults: {
      child_name: str(d.child_name) ?? str(c.child_nickname) ?? '',
      pet_name: str(d.pet_name) ?? '',
      locale: locale(d.locale) ?? 'sl',
    },
    pdf_url: kind === 'certificate' ? pdfUrl : null,
  };
}

/** Child state `certificate` → typed; null while the challenge runs / malformed. */
export function readChildCertificate(raw: unknown): ChildCertificate | null {
  const c = obj(raw);
  if (c === null) return null;
  const kind = outcome(c.outcome);
  if (kind === null) return null;
  if (kind === 'report') {
    // Never a score, number or reason for the child (CERTIFICATE_SPEC: no shaming).
    return {
      outcome: 'report',
      generated: false,
      child_name: null,
      pet_name: null,
      care_score: null,
      number: null,
      completed_at: str(c.completed_at),
      locale: null,
      pdf_url: null,
    };
  }
  const generated = c.generated === true;
  return {
    outcome: 'certificate',
    generated,
    child_name: str(c.child_name),
    pet_name: str(c.pet_name),
    care_score: int(c.care_score),
    number: str(c.number),
    completed_at: str(c.completed_at),
    locale: locale(c.locale),
    pdf_url: generated ? pdfUrlOrNull(c.pdf_url) : null,
  };
}

// ──────────────────────────────────────────────────────────────
//  View models
// ──────────────────────────────────────────────────────────────

/** Which certificate card the parent sees for a child (null certificate → none). */
export type ParentCertificateCard =
  | { kind: 'none' }
  | { kind: 'ready'; certificate: ParentCertificate }
  | { kind: 'generated'; certificate: ParentCertificate }
  | { kind: 'report'; certificate: ParentCertificate; reason: CertificateReason };

export function parentCertificateCard(certificate: ParentCertificate | null | undefined): ParentCertificateCard {
  if (!certificate) return { kind: 'none' };
  if (certificate.outcome === 'report') {
    return { kind: 'report', certificate, reason: certificate.reason ?? 'low_score' };
  }
  return certificate.generated ? { kind: 'generated', certificate } : { kind: 'ready', certificate };
}

/** What the child sees: nothing / "the parent will prepare it" / the certificate / a kind "finished". */
export type ChildCertificateEntry =
  | { kind: 'none' }
  | { kind: 'waiting' }
  | { kind: 'generated'; certificate: ChildCertificate }
  | { kind: 'finished' };

export function childCertificateEntry(certificate: ChildCertificate | null | undefined): ChildCertificateEntry {
  if (!certificate) return { kind: 'none' };
  if (certificate.outcome === 'report') return { kind: 'finished' };
  return certificate.generated ? { kind: 'generated', certificate } : { kind: 'waiting' };
}

// ──────────────────────────────────────────────────────────────
//  Form
// ──────────────────────────────────────────────────────────────

export interface CertificateFormValues {
  locale: CertificateLocale;
  child_name: string;
  pet_name: string;
}

export type CertificateNameField = 'child_name' | 'pet_name';
export type CertificateFieldError = 'required' | 'name_too_long' | 'name_invalid' | 'name_not_allowed';
export type CertificateFormErrorKind = 'not_eligible' | 'not_found' | 'forbidden' | 'offline' | 'server' | 'locale_invalid';

export interface CertificateFormErrors {
  child_name?: CertificateFieldError;
  pet_name?: CertificateFieldError;
  /** Not tied to a name field (language, 409, 404, network). */
  form?: CertificateFormErrorKind;
}

/** First form: the server's defaults; "Uredi in ustvari znova": what is printed now. */
export function certificateFormDefaults(certificate: ParentCertificate): CertificateFormValues {
  if (certificate.generated) {
    return {
      locale: certificate.locale ?? certificate.defaults.locale,
      child_name: certificate.child_name ?? certificate.defaults.child_name,
      pet_name: certificate.pet_name ?? certificate.defaults.pet_name,
    };
  }
  return { ...certificate.defaults };
}

/** Length in characters (code points), as the server counts it. */
export function certificateNameLength(value: string): number {
  return Array.from(normalizePetName(value) ?? '').length;
}

function checkName(value: string): CertificateFieldError | undefined {
  const name = normalizePetName(value);
  if (name === null) return 'required';
  if (Array.from(name).length > CERTIFICATE_NAME_MAX_LENGTH) return 'name_too_long';
  return undefined;
}

/**
 * Client-side check before the request: both names present, ≤ 30 characters. Characters
 * and the word filter stay on the server (its 422 codes are shown per field).
 */
export function validateCertificateForm(
  values: CertificateFormValues,
): { ok: true; body: CertificateFormValues } | { ok: false; errors: CertificateFormErrors } {
  const errors: CertificateFormErrors = {};
  const child = checkName(values.child_name);
  const pet = checkName(values.pet_name);
  if (child) errors.child_name = child;
  if (pet) errors.pet_name = pet;
  if (child || pet) return { ok: false, errors };
  return {
    ok: true,
    body: {
      locale: values.locale,
      child_name: normalizePetName(values.child_name) ?? '',
      pet_name: normalizePetName(values.pet_name) ?? '',
    },
  };
}

const FIELD_CODES: readonly string[] = ['name_too_long', 'name_invalid', 'name_not_allowed'];

/** A failed generate → field errors (422 `codes`) or one form-level error. */
export function classifyCertificateError(error: unknown): CertificateFormErrors {
  if (!(error instanceof ApiError)) return { form: 'offline' };
  const body = obj(error.data) ?? {};
  if (error.status === 422) {
    const codes = obj(body.codes) ?? {};
    const out: CertificateFormErrors = {};
    for (const field of ['child_name', 'pet_name'] as const) {
      const code = codes[field];
      if (typeof code === 'string' && FIELD_CODES.includes(code)) out[field] = code as CertificateFieldError;
      else if (code !== undefined) out[field] = 'name_invalid';
    }
    if (codes.locale !== undefined) out.form = 'locale_invalid';
    if (out.child_name === undefined && out.pet_name === undefined && out.form === undefined) out.form = 'server';
    return out;
  }
  if (error.status === 409) return { form: 'not_eligible' };
  if (error.status === 404) return { form: 'not_found' };
  if (error.status === 403) return { form: 'forbidden' };
  return { form: 'server' };
}

// ──────────────────────────────────────────────────────────────
//  Texts
// ──────────────────────────────────────────────────────────────

/** "12. 10. 2026" (sl) / "12 Oct 2026" (en) from `YYYY-MM-DD` or an ISO instant's date part. */
export function certificateDate(value: string | null | undefined): string {
  const match = typeof value === 'string' ? /^(\d{4})-(\d{2})-(\d{2})/.exec(value) : null;
  if (!match) return '';
  const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
  const monthKey = String(month) as '1';
  return t('certificate:date', { day, month: t(`family:date.months.${monthKey}`), year });
}

export const CERTIFICATE_PARENT_STRINGS = strings('certificate', 'parent', {
  readyBody: (name: string) => t('certificate:parent.readyBody', { name }),
  scoreOf: (score: number) => t('certificate:parent.scoreOf', { score }),
  number: (number: string) => t('certificate:parent.number', { number }),
  completed: (date: string) => t('certificate:parent.completed', { date }),
  printedFor: (child: string, pet: string) => t('certificate:parent.printedFor', { child, pet }),
  openReportA11y: (name: string) => t('certificate:parent.openReportA11y', { name }),
});

export const CERTIFICATE_FORM_STRINGS = strings('certificate', 'form', {
  counter: (count: number) => t('certificate:form.counter', { count, max: CERTIFICATE_NAME_MAX_LENGTH }),
});

export const CERTIFICATE_CHILD_STRINGS = strings('certificate', 'child', {
  number: (number: string) => t('certificate:child.number', { number }),
});

/** The report card's explanation (factual, kind; parent = vikanje). */
export function reportText(certificate: Pick<ParentCertificate, 'reason' | 'care_score'>): string {
  if (certificate.reason === 'game_over') return t('certificate:parent.reportGameOver');
  return certificate.care_score === null
    ? t('certificate:parent.reportLowScoreNoScore', { min: CERTIFICATE_MIN_SCORE })
    : t('certificate:parent.reportLowScore', { score: certificate.care_score, min: CERTIFICATE_MIN_SCORE });
}

export function certificateErrorText(kind: CertificateFieldError | CertificateFormErrorKind): string {
  if (kind === 'name_too_long') return t('certificate:form.errors.name_too_long', { max: CERTIFICATE_NAME_MAX_LENGTH });
  return t(`certificate:form.errors.${kind}`);
}

// ──────────────────────────────────────────────────────────────
//  Push target
// ──────────────────────────────────────────────────────────────

interface ChildWithCertificate {
  id: number;
  pet_id: number | null;
  certificate: ParentCertificate | null;
}

/**
 * Which child's detail a tapped push opens. `challenge_completed` (sent once per finished
 * child, payload `{type, pet_id}` only): a child of that pet whose certificate still waits
 * to be generated, else the newest finished one, else the first caretaker. Other pushes:
 * the first child caring for that pet (M3-02).
 */
export function pushTargetChild<C extends ChildWithCertificate>(
  children: readonly C[],
  petId: number,
  type: string | null = null,
): C | undefined {
  const ofPet = children.filter((c) => c.pet_id === petId);
  if (type !== 'challenge_completed') return ofPet[0];
  const finished = ofPet.filter((c) => c.certificate !== null && c.certificate.pet_id === petId);
  const waiting = finished.find((c) => c.certificate?.outcome === 'certificate' && !c.certificate.generated);
  if (waiting) return waiting;
  const newest = [...finished].sort((a, b) =>
    (b.certificate?.completed_at ?? '').localeCompare(a.certificate?.completed_at ?? ''),
  )[0];
  return newest ?? ofPet[0];
}
