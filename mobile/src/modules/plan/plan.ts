/**
 * Plan of a pet (M3-09 / M3-11, PAYMENTS_SPEC — David 2026-10-07): the free mutt sandbox
 * or the 12-week challenge with a 7-day trial from birth. Pure readers + labels; no SDK,
 * no network, so the child app may import it too (it never shows prices or purchase UI).
 *
 * - `free` — forever free mixed breed, no "week N of 12", 7 days of parent history.
 * - `challenge` — `status` null before birth → `trial` (until `trial_ends_at`) →
 *   `payment_required` (server lock, like a hard stop) → `paid`.
 * - A payload without `plan` (server before M3-11) reads as challenge / paid: nothing locked.
 */

import type { BillingPet, ChallengeStatus, PetPlan, PetPlanType } from '@/api/client';
import { t } from '@/i18n';

export type { ChallengeStatus, PetPlan, PetPlanType };

/** What an older server (no `plan`) means: a paid challenge — nobody gets locked by an old payload. */
export const LEGACY_PLAN: PetPlan = { type: 'challenge', status: 'paid', trial_ends_at: null, paid_at: null };

const PLAN_TYPES: readonly PetPlanType[] = ['free', 'challenge'];
const STATUSES: readonly ChallengeStatus[] = ['trial', 'payment_required', 'paid'];

const DAY_MS = 86_400_000;

function strOrNull(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 ? value : null;
}

function readStatus(value: unknown): ChallengeStatus | null {
  return STATUSES.find((s) => s === value) ?? null;
}

/**
 * Loose `plan` (child state, dashboard pet, broadcast) → typed. Missing / not an object →
 * {@link LEGACY_PLAN}; an unknown type → challenge. A free pet never has a challenge status.
 */
export function readPetPlan(raw: unknown): PetPlan {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) return LEGACY_PLAN;
  const o = raw as Record<string, unknown>;
  const type = PLAN_TYPES.find((p) => p === o.type) ?? 'challenge';
  return {
    type,
    status: type === 'free' ? null : readStatus(o.status),
    trial_ends_at: type === 'free' ? null : strOrNull(o.trial_ends_at),
    paid_at: type === 'free' ? null : strOrNull(o.paid_at),
  };
}

/** One `GET /api/parent/billing` pet as a {@link PetPlan}. */
export function planOfBillingPet(pet: BillingPet): PetPlan {
  return { type: pet.plan, status: pet.status, trial_ends_at: pet.trial_ends_at, paid_at: pet.paid_at };
}

export function isFreePlan(plan: PetPlan): boolean {
  return plan.type === 'free';
}

/** The server locks the game until a parent buys (PAYMENTS_SPEC P3). */
export function isPaymentRequired(plan: PetPlan): boolean {
  return plan.type === 'challenge' && plan.status === 'payment_required';
}

/** A challenge pet that can (or must) be bought now: on trial or locked for payment. */
export function needsPurchase(plan: PetPlan): boolean {
  return plan.type === 'challenge' && (plan.status === 'trial' || plan.status === 'payment_required');
}

/** ms of trial left (≤ 0 when over), null when not on trial or the end is unknown. */
export function trialMsLeft(plan: PetPlan, now: number = Date.now()): number | null {
  if (plan.type !== 'challenge' || plan.status !== 'trial' || plan.trial_ends_at === null) return null;
  const end = Date.parse(plan.trial_ends_at);
  return Number.isNaN(end) ? null : end - now;
}

/** Whole days of trial left, rounded up ("še 3 dni"); null when not on a trial with a known end. */
export function trialDaysLeft(plan: PetPlan, now: number = Date.now()): number | null {
  const ms = trialMsLeft(plan, now);
  return ms === null ? null : Math.max(0, Math.ceil(ms / DAY_MS));
}

/** Less than 24 h of trial left (and not over yet) — the parent gets a prominent banner. */
export function isTrialLastDay(plan: PetPlan, now: number = Date.now()): boolean {
  const ms = trialMsLeft(plan, now);
  return ms !== null && ms > 0 && ms <= DAY_MS;
}

/** Look of a plan badge (parent UI): status tokens only, never prices. */
export type PlanBadgeTone = 'neutral' | 'info' | 'warn' | 'danger' | 'ok';

export interface PlanBadge {
  tone: PlanBadgeTone;
  /** Translated at call time — call while rendering. */
  label: string;
}

/**
 * "Brezplačno" · "Preizkus: še N dni" · "Preizkus: zadnji dan" · "Čaka na nakup" · "Plačano".
 * A trial whose end has passed but the server hasn't flipped yet reads as the last day.
 */
export function planBadge(plan: PetPlan, now: number = Date.now()): PlanBadge {
  if (plan.type === 'free') return { tone: 'neutral', label: t('paywall:plan.badge.free') };
  switch (plan.status) {
    case 'paid':
      return { tone: 'ok', label: t('paywall:plan.badge.paid') };
    case 'payment_required':
      return { tone: 'danger', label: t('paywall:plan.badge.paymentRequired') };
    case 'trial': {
      const ms = trialMsLeft(plan, now);
      if (ms !== null && ms <= DAY_MS) return { tone: 'warn', label: t('paywall:plan.badge.trialLastDay') };
      const days = trialDaysLeft(plan, now);
      return {
        tone: 'info',
        label: days === null ? t('paywall:plan.badge.trial') : t('paywall:plan.badge.trialDays', { count: days }),
      };
    }
    default:
      return { tone: 'info', label: t('paywall:plan.badge.trialNotStarted') };
  }
}

/** Which banner (if any) the parent should see for this plan right now. */
export type PlanBanner = 'payment_required' | 'trial_last_day';

export function planBanner(plan: PetPlan, now: number = Date.now()): PlanBanner | null {
  if (isPaymentRequired(plan)) return 'payment_required';
  if (isTrialLastDay(plan, now) || (trialMsLeft(plan, now) ?? 1) <= 0) return 'trial_last_day';
  return null;
}
