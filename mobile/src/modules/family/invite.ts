/**
 * Second parent (M2-01a): invite code for the family and joining another parent's
 * family with it. Pure helpers — the screens use them through
 * `useInviteParent` / `useJoinFamily`.
 */

import { ApiError } from '@/api/client';
import { reasonOf } from '@/modules/pairing/pin';
import { localParts } from '@/modules/childPet/familyTime';

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

/** "5. 10. ob 14:30" in the family's wall clock (falls back to the ISO's own clock). */
export function expiryText(expiresAt: string, timezone: string | null): string | null {
  const parts = localParts(expiresAt, timezone);
  if (!parts) return null;
  const [, m, d] = parts.date.split('-');
  return `${Number(d)}. ${Number(m)}. ob ${parts.time}`;
}

/** Text for the system share sheet (no child data — just the code and where to type it). */
export function inviteShareMessage(code: string, expires: string | null): string {
  const validity = expires ? ` Koda velja do ${expires}.` : '';
  return (
    `Pridruži se naši družini v aplikaciji PetPrep. V aplikaciji izberi "Sem starš", se prijavi ` +
    `in v zavihku Nadzor vnesi kodo družine: ${formatInviteCode(code)}.${validity}`
  );
}

export type JoinErrorKind =
  | 'invalid_code'
  | 'code_expired'
  | 'code_used'
  | 'already_member'
  | 'family_not_empty'
  | 'too_many_attempts'
  | 'invalid_format'
  | 'offline'
  | 'server';

export function classifyJoinError(error: unknown): JoinErrorKind {
  if (!(error instanceof ApiError)) return 'offline';
  const reason = reasonOf(error);
  if (error.status === 429) return 'too_many_attempts';
  if (error.status === 409) return reason === 'already_member' ? 'already_member' : 'family_not_empty';
  if (error.status === 422) {
    if (reason === 'invalid_code' || reason === 'code_expired' || reason === 'code_used') return reason;
    return 'invalid_format';
  }
  return 'server';
}

export type InviteErrorKind = 'too_many' | 'offline' | 'server';

export function classifyInviteError(error: unknown): InviteErrorKind {
  if (!(error instanceof ApiError)) return 'offline';
  return error.status === 429 ? 'too_many' : 'server';
}
