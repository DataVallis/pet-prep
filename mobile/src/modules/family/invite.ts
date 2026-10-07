/**
 * Second parent (M2-01a): invite code for the family and joining another parent's
 * family with it. Pure helpers — the screens use them through
 * `useInviteParent` / `useJoinFamily`.
 */

import { ApiError } from '@/api/client';
import { reasonOf } from '@/modules/pairing/pin';
import { localParts } from '@/modules/childPet/familyTime';
import { shortDate } from '@/modules/family/scoring';
import { t } from '@/i18n';

/** Invite codes are 8 characters (backend `FamilyInviteService::CODE_LENGTH`). */
export const INVITE_CODE_LENGTH = 8;

/**
 * What the parent typed → what the backend accepts: upper case, no spaces or dashes
 * (the backend forgives case and surrounding spaces only). null = not a plausible code.
 */
export function normalizeInviteCode(input: string): string | null {
  const code = input.replace(/[\s-]+/g, '').toUpperCase();
  return /^[A-Z0-9]{6,16}$/.test(code) ? code : null;
}

/** "ABCD EFGH" — easier to read aloud / copy by hand. */
export function formatInviteCode(code: string): string {
  return code.length === INVITE_CODE_LENGTH ? `${code.slice(0, 4)} ${code.slice(4)}` : code;
}

/** "5. 10. ob 14:30" / "5 Oct at 14:30" in the family's wall clock (falls back to the ISO's own clock). */
export function expiryText(expiresAt: string, timezone: string | null): string | null {
  const parts = localParts(expiresAt, timezone);
  if (!parts) return null;
  const [, m, d] = parts.date.split('-');
  return t('family:date.at', { date: shortDate(Number(d), Number(m)), time: parts.time });
}

/** Text for the system share sheet (no child data — just the code and where to type it). */
export function inviteShareMessage(code: string, expires: string | null): string {
  const message = t('family:invite.share', { code: formatInviteCode(code) });
  return expires ? t('family:invite.shareValidity', { message, expires }) : message;
}

export type JoinErrorKind =
  | 'invalid_code'
  | 'code_expired'
  | 'code_used'
  | 'already_member'
  | 'family_not_empty'
  | 'too_many_attempts'
  | 'rate_limited'
  | 'not_a_parent'
  | 'invalid_format'
  | 'offline'
  | 'server';

const JOIN_REASONS: readonly JoinErrorKind[] = [
  'invalid_code',
  'code_expired',
  'code_used',
  'already_member',
  'family_not_empty',
  'too_many_attempts',
  'not_a_parent',
];

/**
 * The server's `reason` wins; without one: 429 = the route throttle (generic "too
 * many requests"), 422 = validation (format), anything else (unknown 409, 5xx) = generic.
 */
export function classifyJoinError(error: unknown): JoinErrorKind {
  if (!(error instanceof ApiError)) return 'offline';
  const reason = reasonOf(error);
  const known = JOIN_REASONS.find((r) => r === reason);
  if (known) return known;
  if (error.status === 429) return 'rate_limited';
  if (error.status === 422) return 'invalid_format';
  return 'server';
}

export type InviteErrorKind = 'too_many' | 'offline' | 'server';

export function classifyInviteError(error: unknown): InviteErrorKind {
  if (!(error instanceof ApiError)) return 'offline';
  return error.status === 429 ? 'too_many' : 'server';
}
