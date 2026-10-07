/**
 * Account deletion + data export (M2-08) — pure helpers and the two flows that leave
 * the screen: share the export JSON through the system share sheet, and delete the
 * account followed by a local logout (every token is gone on the server).
 *
 * Deletion is immediate and irreversible (no grace period in the MVP). The server
 * requires the password + `confirm: true`; the app additionally makes the parent
 * type the confirmation word of the app language (`deleteConfirmWord()`).
 */

import { Share } from 'react-native';

import {
  api,
  ApiError,
  type AccountDeletionReason,
  type DeleteAccountResponse,
  type FamilyExport,
} from '@/api/client';
import type { FamilyChild, FamilyOverview } from '@/modules/family/family';
import { logout } from '@/modules/session/logout';
import { t } from '@/i18n';

/**
 * What the parent has to type before the red button unlocks, in the app language
 * ("IZBRIŠI" / "DELETE", `account:confirmWord`). Client-side only: the server checks
 * the password + `confirm: true` (`ConfirmDeletionRequest`), never this word.
 */
export function deleteConfirmWord(): string {
  return t('account:confirmWord');
}

/** True when the typed text is the confirmation word (spaces and letter case forgiven, "š" required). */
export function isConfirmWord(text: string): boolean {
  return text.trim().toUpperCase() === deleteConfirmWord().toUpperCase();
}

/** The delete button is enabled only with a password and the confirmation word. */
export function canSubmitDeletion(password: string, confirmText: string): boolean {
  return password.length > 0 && isConfirmWord(confirmText);
}

export interface AccountDeletionImpact {
  /** This parent is the family's last parent → the whole family goes. */
  lastParent: boolean;
  children: number;
  pets: number;
}

/** What deleting the signed-in parent's account takes with it (null family = only the account). */
export function accountDeletionImpact(family: FamilyOverview | null): AccountDeletionImpact {
  if (!family) return { lastParent: true, children: 0, pets: 0 };
  const others = family.parents.filter((p) => !p.is_me).length;
  return { lastParent: others === 0, children: family.children.length, pets: family.pets.length };
}

export interface ChildDeletionImpact {
  /** Pets only this child cares for — deleted with all their data. */
  deletedPets: number;
  /** Shared pets — they stay with the other children. */
  keptPets: number;
}

/** Which of the family's pets go with the child (sole caretaker) and which stay (shared). */
export function childDeletionImpact(child: Pick<FamilyChild, 'id'>, family: FamilyOverview): ChildDeletionImpact {
  let deletedPets = 0;
  let keptPets = 0;
  for (const pet of family.pets) {
    const ids = pet.caretakers.map((c) => c.child_id);
    if (!ids.includes(child.id)) continue;
    if (ids.length === 1) deletedPets += 1;
    else keptPets += 1;
  }
  return { deletedPets, keptPets };
}

export type DeletionErrorKind =
  | 'invalid_password'
  | 'protected'
  | 'not_found'
  | 'throttled'
  | 'invalid'
  | 'unknown'
  | 'server';

function reasonOf(error: ApiError): AccountDeletionReason | null {
  const data = error.data;
  if (typeof data === 'object' && data !== null && 'reason' in data) {
    const { reason } = data as { reason: unknown };
    if (typeof reason === 'string') return reason as AccountDeletionReason;
  }
  return null;
}

/**
 * Map a failed deletion to a message key. No HTTP answer (offline, timeout, connection
 * dropped) → `unknown`: the request may have reached the server and the deletion may
 * have happened, so the app must not claim "nothing was deleted" (PR #29 m6).
 */
export function classifyDeletionError(error: unknown): DeletionErrorKind {
  if (!(error instanceof ApiError)) return 'unknown';
  const reason = reasonOf(error);
  if (reason === 'invalid_password') return 'invalid_password';
  if (reason === 'superadmin_protected') return 'protected';
  if (error.status === 404) return 'not_found';
  if (error.status === 429) return 'throttled';
  if (error.status === 422) return 'invalid';
  return 'server';
}

export type ExportErrorKind = 'too_large' | 'too_large_to_share' | 'throttled' | 'offline' | 'server';

/**
 * The share sheet carries the export as text; above this size platforms truncate or
 * fail (Android Binder ~1 MB transaction limit, iOS share extensions). A file export
 * (expo-sharing) comes with the dev build (M2-08a).
 */
export const EXPORT_SHARE_MAX_BYTES = 400 * 1024;

/** The export was downloaded but is too large to hand to the share sheet as text. */
export class ExportTooLargeToShareError extends Error {
  constructor(public readonly bytes: number) {
    super(`Export of ${bytes} bytes is too large to share as text`);
    this.name = 'ExportTooLargeToShareError';
  }
}

/** UTF-8 size of a string (Slovenian letters are 2 bytes). */
export function utf8Bytes(text: string): number {
  if (typeof TextEncoder !== 'undefined') return new TextEncoder().encode(text).length;
  let bytes = 0;
  for (const ch of text) {
    const code = ch.codePointAt(0) ?? 0;
    bytes += code < 0x80 ? 1 : code < 0x800 ? 2 : code < 0x10000 ? 3 : 4;
  }
  return bytes;
}

export function classifyExportError(error: unknown): ExportErrorKind {
  if (error instanceof ExportTooLargeToShareError) return 'too_large_to_share';
  if (!(error instanceof ApiError)) return 'offline';
  if (error.status === 413) return 'too_large';
  if (error.status === 429) return 'throttled';
  return 'server';
}

/** `petprep-izvoz-2026-10-05.json` / `petprep-export-…` — the date of the export (from `generated_at`). */
export function exportFileName(data: Pick<FamilyExport, 'generated_at'>): string {
  const date = typeof data.generated_at === 'string' ? data.generated_at.slice(0, 10) : '';
  return /^\d{4}-\d{2}-\d{2}$/.test(date) ? t('account:exportFile', { date }) : t('account:exportFileUndated');
}

/**
 * Fetch the export and hand it to the share sheet as text (pretty JSON). Uses the core
 * `Share` API — no native file module needed in Expo Go; a file share (expo-sharing)
 * can follow with the dev build (M3-01). Resolves to false when the parent dismissed
 * the sheet; throws `ExportTooLargeToShareError` above `EXPORT_SHARE_MAX_BYTES`.
 */
export async function shareFamilyExport(
  fetchExport: () => Promise<FamilyExport> = api.exportFamilyData,
  share: typeof Share.share = Share.share,
): Promise<boolean> {
  const data = await fetchExport();
  const title = exportFileName(data);
  const message = JSON.stringify(data, null, 2);
  const bytes = utf8Bytes(message);
  if (bytes > EXPORT_SHARE_MAX_BYTES) {
    // Never hand an oversized text to the share sheet (it fails or truncates silently).
    throw new ExportTooLargeToShareError(bytes);
  }
  const result = await share({ title, message }, { subject: title, dialogTitle: title });
  return result.action !== Share.dismissedAction;
}

/**
 * Delete the account, then sign out locally. The server already revoked every token,
 * so `logout` skips its own revoke call; the navigator then shows the StartScreen.
 */
export async function deleteAccountAndLogout(
  password: string,
  deleteAccount: (password: string) => Promise<DeleteAccountResponse> = api.deleteAccount,
  signOut: (options: { revoke: boolean }) => Promise<void> = logout,
): Promise<DeleteAccountResponse> {
  const result = await deleteAccount(password);
  await signOut({ revoke: false });
  return result;
}
