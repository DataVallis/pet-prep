/**
 * M5-08 H7 fake door »Dobili smo pravo žival« (validation plan, M5-08 API contract (2026-11-21) §3, D3 monthly
 * prices). The helper for a real pet does NOT exist: every text says »v pripravi« and the screen
 * only asks what the parent would pay and whether to notify them. Pure functions — the dashboard
 * row and `Phase2InterestScreen` only wire them up.
 */

import { ApiError, type Phase2InterestRequest, type Phase2PriceChoice } from '@/api/client';
import { isValidEmail, normalizeEmail } from '@/modules/auth/signup';
import { strings } from '@/i18n/strings';

/** User-visible strings (`parent:phase2`). */
export const PHASE2_STRINGS = strings('parent', 'phase2');

export const PHASE2_PRICE_CHOICES: readonly Phase2PriceChoice[] = ['free', 'monthly_2_99', 'monthly_5_99'];

/** The dashboard's `phase2_interest` — null = older server (the entry stays hidden). */
export interface Phase2InterestState {
  submitted: boolean;
  /** Not in the contract yet; used when the server adds it (else unknown). */
  notify: boolean | null;
}

/**
 * `GET /api/parent/dashboard` → `phase2_interest` (top level). HAND-TYPED read
 * (M5-08 contract §3) — the generated dashboard type does not have it yet.
 */
export function readPhase2Interest(dashboard: unknown): Phase2InterestState | null {
  if (typeof dashboard !== 'object' || dashboard === null || !('phase2_interest' in dashboard)) return null;
  const raw = (dashboard as { phase2_interest: unknown }).phase2_interest;
  if (typeof raw !== 'object' || raw === null) return null;
  const { submitted, notify } = raw as { submitted?: unknown; notify?: unknown };
  if (typeof submitted !== 'boolean') return null;
  return { submitted, notify: typeof notify === 'boolean' ? notify : null };
}

export interface Phase2Form {
  price: Phase2PriceChoice | null;
  notify: boolean;
  email: string;
}

/** Prefilled with the account e-mail when there is one (social accounts may have none). */
export function initialPhase2Form(accountEmail: string | null | undefined): Phase2Form {
  return { price: null, notify: false, email: accountEmail ?? '' };
}

export type Phase2FormError = 'price_required' | 'email_required' | 'email_invalid';

/** Client check; null = may be sent. The e-mail matters only with »Obvestite me«. */
export function validatePhase2(form: Phase2Form): Phase2FormError | null {
  if (form.price === null) return 'price_required';
  if (!form.notify) return null;
  const email = normalizeEmail(form.email);
  if (email.length === 0) return 'email_required';
  if (!isValidEmail(email)) return 'email_invalid';
  return null;
}

/** Request body of a validated form; without »Obvestite me« no e-mail is sent at all. */
export function phase2Body(form: Phase2Form & { price: Phase2PriceChoice }): Phase2InterestRequest {
  return {
    price_choice: form.price,
    notify: form.notify,
    email: form.notify ? normalizeEmail(form.email) : null,
  };
}

export type Phase2SendError = 'email_required' | 'email_invalid' | 'offline' | 'failed';

function codeOf(data: unknown): string | null {
  if (typeof data !== 'object' || data === null) return null;
  if ('code' in data && typeof (data as { code: unknown }).code === 'string') return (data as { code: string }).code;
  if ('errors' in data) {
    const errors = (data as { errors: unknown }).errors;
    if (typeof errors === 'object' && errors !== null && 'email' in errors) return 'email_invalid';
  }
  return null;
}

/** A failed `POST /api/parent/phase2-interest` → what the screen shows. */
export function mapPhase2Error(err: unknown): Phase2SendError {
  if (!(err instanceof ApiError)) return 'offline';
  if (err.status === 422) {
    const code = codeOf(err.data);
    if (code === 'email_required') return 'email_required';
    if (code === 'email_invalid') return 'email_invalid';
  }
  return 'failed';
}

/**
 * Thank-you text: »Hvala! Obvestili vas bomo.« — unless we know the parent did NOT ask to be
 * notified (just sent without the checkbox, or the server says `notify: false`), then a plain thanks.
 */
export function thanksKind(notify: boolean | null): 'notify' | 'plain' {
  return notify === false ? 'plain' : 'notify';
}
