/**
 * M2-08 — account deletion / export helpers: confirmation word, consequences,
 * error mapping, export sharing, delete-then-logout.
 */
import { Share } from 'react-native';

import { ApiError, type DeleteAccountResponse, type FamilyExport } from '@/api/client';
import {
  accountDeletionImpact,
  canSubmitDeletion,
  childDeletionImpact,
  classifyDeletionError,
  classifyExportError,
  deleteAccountAndLogout,
  EXPORT_SHARE_MAX_BYTES,
  exportFileName,
  ExportTooLargeToShareError,
  isConfirmWord,
  shareFamilyExport,
  utf8Bytes,
} from '@/modules/account/account';
import { familyFromDashboard, type FamilyOverview } from '@/modules/family/family';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';

function family(parents: { id: number; name: string; is_me: boolean }[] = [{ id: 1, name: 'Starš', is_me: true }]): FamilyOverview {
  const data = makeScoredDashboard(
    [makeScoredChild({ id: 2, name: 'Maja', pet_id: 7 }), makeScoredChild({ id: 5, name: 'Luka', pet_id: 8 })],
    [
      makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] }),
      makeFamilyPet({ id: 8, caretakers: [{ child_id: 2, contract_signed: true }, { child_id: 5, contract_signed: true }] }),
    ],
  );
  const overview = familyFromDashboard(data as never) as FamilyOverview;
  return { ...overview, parents };
}

const EXPORT: FamilyExport = { format: 'petprep.family-export', version: 1, generated_at: '2026-10-05T10:00:00+00:00', pets: [] };

describe('confirmation', () => {
  it('accepts IZBRIŠI with spaces / lower case, never without the š', () => {
    expect(isConfirmWord('IZBRIŠI')).toBe(true);
    expect(isConfirmWord('  izbriši ')).toBe(true);
    expect(isConfirmWord('IZBRISI')).toBe(false);
    expect(isConfirmWord('IZBRIŠ')).toBe(false);
    expect(isConfirmWord('')).toBe(false);
  });

  it('needs both the password and the word', () => {
    expect(canSubmitDeletion('geslo', 'IZBRIŠI')).toBe(true);
    expect(canSubmitDeletion('', 'IZBRIŠI')).toBe(false);
    expect(canSubmitDeletion('geslo', 'ja')).toBe(false);
  });
});

describe('consequences', () => {
  it('the only parent takes the whole family', () => {
    expect(accountDeletionImpact(family())).toEqual({ lastParent: true, children: 2, pets: 2 });
  });

  it('with another parent only the account goes', () => {
    const f = family([
      { id: 1, name: 'Mama', is_me: true },
      { id: 3, name: 'Oče', is_me: false },
    ]);
    expect(accountDeletionImpact(f).lastParent).toBe(false);
  });

  it('a parent without a family deletes only the account', () => {
    expect(accountDeletionImpact(null)).toEqual({ lastParent: true, children: 0, pets: 0 });
  });

  it('a child: sole-caretaker pets are deleted, shared pets kept', () => {
    const f = family();
    expect(childDeletionImpact({ id: 2 }, f)).toEqual({ deletedPets: 1, keptPets: 1 });
    expect(childDeletionImpact({ id: 5 }, f)).toEqual({ deletedPets: 0, keptPets: 1 });
    expect(childDeletionImpact({ id: 99 }, f)).toEqual({ deletedPets: 0, keptPets: 0 });
  });
});

describe('errors', () => {
  it('maps deletion refusals', () => {
    expect(classifyDeletionError(new ApiError('x', 422, { reason: 'invalid_password' }))).toBe('invalid_password');
    expect(classifyDeletionError(new ApiError('x', 403, { reason: 'superadmin_protected' }))).toBe('protected');
    expect(classifyDeletionError(new ApiError('x', 404, { reason: 'child_not_found' }))).toBe('not_found');
    expect(classifyDeletionError(new ApiError('x', 429, null, 900))).toBe('throttled');
    expect(classifyDeletionError(new ApiError('x', 422, { errors: { confirm: ['…'] } }))).toBe('invalid');
    expect(classifyDeletionError(new ApiError('x', 500, null))).toBe('server');
    // No HTTP answer: the deletion may have happened — never "nothing deleted".
    expect(classifyDeletionError(new TypeError('Network request failed'))).toBe('unknown');
  });

  it('maps export refusals', () => {
    expect(classifyExportError(new ApiError('x', 413, { reason: 'export_too_large' }))).toBe('too_large');
    expect(classifyExportError(new ApiError('x', 429, null))).toBe('throttled');
    expect(classifyExportError(new ApiError('x', 500, null))).toBe('server');
    expect(classifyExportError(new Error('offline'))).toBe('offline');
    expect(classifyExportError(new ExportTooLargeToShareError(500_000))).toBe('too_large_to_share');
  });
});

describe('export', () => {
  it('names the file after the export date', () => {
    expect(exportFileName(EXPORT)).toBe('petprep-izvoz-2026-10-05.json');
    expect(exportFileName({ generated_at: 'nonsense' })).toBe('petprep-izvoz.json');
  });

  it('shares the pretty JSON through the share sheet', async () => {
    const share = jest.fn().mockResolvedValue({ action: Share.sharedAction });

    await expect(shareFamilyExport(() => Promise.resolve(EXPORT), share)).resolves.toBe(true);

    const [content, options] = share.mock.calls[0];
    expect(content.title).toBe('petprep-izvoz-2026-10-05.json');
    expect(JSON.parse(content.message)).toEqual(EXPORT);
    expect(content.message).toContain('\n  "format"');
    expect(options).toEqual({ subject: 'petprep-izvoz-2026-10-05.json', dialogTitle: 'petprep-izvoz-2026-10-05.json' });
  });

  it('refuses to share a text above ~400 KB (UTF-8) — clear error, no share sheet', async () => {
    const share = jest.fn();
    const big: FamilyExport = { ...EXPORT, blob: 'ž'.repeat(Math.ceil(EXPORT_SHARE_MAX_BYTES / 2) + 10) };

    await expect(shareFamilyExport(() => Promise.resolve(big), share)).rejects.toBeInstanceOf(ExportTooLargeToShareError);
    expect(share).not.toHaveBeenCalled();
    expect(utf8Bytes('žš')).toBe(4);
    expect(utf8Bytes('ab')).toBe(2);
  });

  it('reports a dismissed sheet and passes fetch errors through', async () => {
    const dismissed = jest.fn().mockResolvedValue({ action: Share.dismissedAction });
    await expect(shareFamilyExport(() => Promise.resolve(EXPORT), dismissed)).resolves.toBe(false);

    const share = jest.fn();
    await expect(shareFamilyExport(() => Promise.reject(new ApiError('x', 429, null)), share)).rejects.toBeInstanceOf(ApiError);
    expect(share).not.toHaveBeenCalled();
  });
});

describe('deleteAccountAndLogout', () => {
  const RESULT: DeleteAccountResponse = {
    status: 'deleted',
    scope: 'family',
    family_deleted: true,
    parents_deleted: 1,
    children_deleted: 2,
    pets_deleted: 2,
  };

  it('deletes, then signs out locally without a server revoke (tokens are gone)', async () => {
    const order: string[] = [];
    const del = jest.fn(async () => {
      order.push('delete');
      return RESULT;
    });
    const signOut = jest.fn(async () => {
      order.push('logout');
    });

    await expect(deleteAccountAndLogout('Geslo123', del, signOut)).resolves.toEqual(RESULT);
    expect(del).toHaveBeenCalledWith('Geslo123', false);
    expect(signOut).toHaveBeenCalledWith({ revoke: false });
    expect(order).toEqual(['delete', 'logout']);
  });

  it('stays signed in when the deletion fails', async () => {
    const signOut = jest.fn();
    await expect(
      deleteAccountAndLogout('x', () => Promise.reject(new ApiError('x', 422, { reason: 'invalid_password' })), signOut),
    ).rejects.toBeInstanceOf(ApiError);
    expect(signOut).not.toHaveBeenCalled();
  });
});
