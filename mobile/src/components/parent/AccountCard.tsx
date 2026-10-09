/**
 * "Račun" section of the Nadzor tab (M2-08): export the family's data (GDPR art. 15 /
 * 20 — JSON through the share sheet) and delete the parent's own account. The
 * deletion explains its consequences first (last parent → the whole family incl.
 * children and pets), then asks for the password and the typed confirmation word ("IZBRIŠI" / "DELETE"); on
 * success the app signs out to the StartScreen.
 */

import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Download, Trash2 } from 'lucide-react-native';

import DeletionConfirmForm from '@/components/parent/DeletionConfirmForm';
import { Card, PARENT_COLORS as C, SectionTitle } from '@/components/parent/ParentUi';
import {
  accountDeletionImpact,
  classifyDeletionError,
  classifyExportError,
  deleteAccountAndLogout,
  deletionLosesPurchase,
  shareFamilyExport,
  type DeletionErrorKind,
  type ExportErrorKind,
} from '@/modules/account/account';
import { useBilling } from '@/modules/purchases';
import type { FamilyOverview } from '@/modules/family/family';
import { palette } from '@/theme';
import { t, tSpecies } from '@/i18n';
import { catCount, petCountText } from '@/modules/species/species';
import { strings } from '@/i18n/strings';

/** All user-visible strings of the "Račun" section (`account:card`, M1-18). */
export const ACCOUNT_STRINGS = strings('account', 'card', {
  /** Last parent: what the whole-family deletion takes ("vaš račun, 2 otroška profila in 3 psi"). */
  /** M5-R06-08c: `cats` of `pets` are cats ("3 muce"; with dogs "… 2 psa in 1 muca"). */
  lastParent: (children: number, pets: number, cats = 0): string[] => [
    t('account:card.lastParentLines.intro'),
    cats > 0 && cats < pets
      ? t('cat:account.whatMixed', {
          children: t('account:counts.childProfiles', { count: children }),
          dogs: t('account:counts.dogs', { count: pets - cats }),
          cats: tSpecies('account:counts.dogs', 'cat', { count: cats }),
        })
      : t('account:card.lastParentLines.what', {
          children: t('account:counts.childProfiles', { count: children }),
          pets: petCountText('account:counts.dogs', pets, cats),
        }),
    t('account:card.lastParentLines.records'),
    t('account:card.lastParentLines.devices'),
  ],
  get otherParentStays(): string[] {
    return [t('account:card.otherParentStaysLines.account'), t('account:card.otherParentStaysLines.family')];
  },
});

const S = ACCOUNT_STRINGS;

export default function AccountCard({ family }: { family: FamilyOverview | null }) {
  const [exporting, setExporting] = useState(false);
  // Message as a thunk: translated at render, so it follows a language switch (M1-18 review).
  const [exportMessage, setExportMessage] = useState<{ text: () => string; isError: boolean } | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<{ text: () => string } | null>(null);

  const impact = accountDeletionImpact(family);
  // M3-11 P5: the last parent's deletion removes every dog — warn when a purchase is lost.
  const billing = useBilling();
  const [paidRequired, setPaidRequired] = useState(false);
  const consequences = impact.lastParent
    ? [...S.lastParent(impact.children, impact.pets, catCount(family?.pets ?? [])), S.exportFirst]
    : [...S.otherParentStays, S.exportFirst];

  const runExport = async () => {
    setExportMessage(null);
    setExporting(true);
    try {
      const shared = await shareFamilyExport();
      if (shared) setExportMessage({ text: () => S.exportDone, isError: false });
    } catch (err) {
      setExportMessage({ text: () => S.exportErrors[classifyExportError(err)], isError: true });
    } finally {
      setExporting(false);
    }
  };

  const runDelete = async (password: string, acknowledgePaidChallenge: boolean) => {
    setDeleteError(null);
    setDeleting(true);
    try {
      // Success signs out: the navigator unmounts this screen (StartScreen).
      await deleteAccountAndLogout(password, undefined, undefined, acknowledgePaidChallenge);
    } catch (err) {
      const kind = classifyDeletionError(err);
      if (kind === 'paid_challenge') setPaidRequired(true);
      setDeleteError({ text: () => S.deleteErrors[kind] });
      setDeleting(false);
    }
  };

  return (
    <Card testID="account-card">
      <SectionTitle>{S.title}</SectionTitle>

      <Text style={styles.hint}>{S.exportHint}</Text>
      <Pressable
        style={({ pressed }) => [styles.button, pressed && styles.pressed]}
        onPress={() => void runExport()}
        disabled={exporting}
        accessibilityRole="button"
        testID="account-export"
      >
        {exporting ? (
          <ActivityIndicator color={C.accent} />
        ) : (
          <>
            <Download color={C.accent} size={17} />
            <Text style={styles.buttonText}>{S.exportButton}</Text>
          </>
        )}
      </Pressable>
      {exportMessage && (
        <Text style={[styles.result, exportMessage.isError && styles.resultError]} testID="account-export-result">
          {exportMessage.text()}
        </Text>
      )}

      {confirming ? (
        <DeletionConfirmForm
          consequences={consequences}
          submitLabel={S.deleteSubmit}
          pending={deleting}
          error={deleteError?.text() ?? null}
          onCancel={() => {
            setConfirming(false);
            setDeleteError(null);
          }}
          paidChallenge={paidRequired || (impact.lastParent && deletionLosesPurchase((family?.pets ?? []).map((p) => p.id), billing.data))}
          onSubmit={(password, ack) => void runDelete(password, ack)}
          testID="account-delete-form"
        />
      ) : (
        <Pressable
          style={({ pressed }) => [styles.button, styles.dangerOutline, pressed && styles.pressed]}
          onPress={() => setConfirming(true)}
          accessibilityRole="button"
          testID="account-delete"
        >
          <Trash2 color={C.redText} size={17} />
          <Text style={[styles.buttonText, styles.dangerText]}>{S.deleteButton}</Text>
        </Pressable>
      )}
    </Card>
  );
}

const styles = StyleSheet.create({
  hint: { fontSize: 13, lineHeight: 18, color: C.muted },
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    minHeight: 46,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.bg,
  },
  buttonText: { fontSize: 15, fontWeight: '700', color: C.accent },
  dangerOutline: { borderColor: palette.dangerBorder, backgroundColor: C.redSoft },
  dangerText: { color: C.redText },
  result: { fontSize: 13, color: C.greenText },
  resultError: { color: C.redText },
  pressed: { opacity: 0.85 },
});
