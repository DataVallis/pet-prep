/**
 * M5-08 H7 fake door »Dobili smo pravo žival« (VALIDATION_PLAN §4.4, `docs/engineering/VALIDATION_API.md` §3, D3
 * monthly prices). The helper for a real pet does NOT exist: every text says »v pripravi« and the screen
 * only asks what the parent would pay and whether to notify them. Pure functions — the dashboard
 * row and `Phase2InterestScreen` only wire them up.
 */

import { ApiError, type ParentDashboardResponse, type Phase2InterestRequest, type Phase2PriceChoice } from '@/api/client';
import { isValidEmail, normalizeEmail } from '@/modules/auth/signup';
import { strings } from '@/i18n/strings';

/** User-visible strings (`parent:phase2`). */
export const PHASE2_STRINGS = strings('parent', 'phase2');

export const PHASE2_PRICE_CHOICES: readonly Phase2PriceChoice[] = ['free', 'monthly_2_99', 'monthly_5_99'];

/** `GET /api/parent/dashboard` → top-level `phase2_interest` as generated (`{submitted, notify, price_choice}`). */
export type DashboardPhase2Interest = Extract<ParentDashboardResponse, { phase2_interest: unknown }>['phase2_interest'];

/** The dashboard's `phase2_interest` as the app reads it — null = older server (the entry stays hidden). */
export interface Phase2InterestState {
  submitted: boolean;
  /** Live »Obvestite me« consent; null only when a payload lacks it (then the notify thanks shows). */
  notify: boolean | null;
  priceChoice: DashboardPhase2Interest['price_choice'];
}

function isPriceChoice(value: unknown): value is Phase2PriceChoice {
  return typeof value === 'string' && (PHASE2_PRICE_CHOICES as readonly string[]).includes(value);
}

/**
 * Read `phase2_interest` defensively (the dashboard cache is also patched locally after a send, and
 * an older server has no field): a non-boolean `submitted` → null.
 */
export function readPhase2Interest(dashboard: unknown): Phase2InterestState | null {
  if (typeof dashboard !== 'object' || dashboard === null || !('phase2_interest' in dashboard)) return null;
  const raw = (dashboard as { phase2_interest: unknown }).phase2_interest;
  if (typeof raw !== 'object' || raw === null) return null;
  const { submitted, notify, price_choice } = raw as { submitted?: unknown; notify?: unknown; price_choice?: unknown };
  if (typeof submitted !== 'boolean') return null;
  return {
    submitted,
    notify: typeof notify === 'boolean' ? notify : null,
    priceChoice: isPriceChoice(price_choice) ? price_choice : null,
  };
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

/**
 * QA #1480 B1 (GDPR withdrawal) — »Ne obveščajte me več«: `{notify: false}` alone; the server keeps the
 * stored price and removes this parent's e-mail signup.
 */
export function withdrawBody(): Phase2InterestRequest {
  return { notify: false };
}

export interface Phase2View {
  /** Form answered (just now or earlier) → only the thank-you shows. */
  done: boolean;
  /** Which thank-you: »Hvala! Obvestili vas bomo.« or the plain »Hvala za odgovor!«. */
  thanks: 'notify' | 'plain';
  /** »Ne obveščajte me več« — only while the consent is live. */
  canWithdraw: boolean;
  /** The body »… obvestili vas bomo, ko bo na voljo« — hidden on the plain thanks (no promise). */
  showBody: boolean;
}

/**
 * What the screen shows. `sentNotify` = the `notify` this screen just sent (null = nothing sent here);
 * it wins over the dashboard until the dashboard catches up.
 */
export function phase2View(state: Phase2InterestState | null, sentNotify: boolean | null): Phase2View {
  const done = sentNotify !== null || state?.submitted === true;
  const notify = sentNotify ?? state?.notify ?? null;
  const thanks = thanksKind(notify);
  return { done, thanks, canWithdraw: done && notify === true, showBody: !done || thanks === 'notify' };
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
