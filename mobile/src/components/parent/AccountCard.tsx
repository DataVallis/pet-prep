/**
 * "Račun" section of the Nadzor tab (M2-08): export the family's data (GDPR art. 15 /
 * 20 — JSON through the share sheet) and delete the parent's own account. The
 * deletion explains its consequences first (last parent → the whole family incl.
 * children and pets), then asks for the password and the typed word "IZBRIŠI"; on
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
  shareFamilyExport,
  type DeletionErrorKind,
  type ExportErrorKind,
} from '@/modules/account/account';
import type { FamilyOverview } from '@/modules/family/family';
import { palette } from '@/theme';

const count = (n: number, one: string, two: string, few: string, many: string): string =>
  `${n} ${n === 1 ? one : n === 2 ? two : n === 3 || n === 4 ? few : many}`;

export const ACCOUNT_STRINGS = {
  title: 'Račun',
  exportButton: 'Izvozi moje podatke',
  exportHint: 'Vsi podatki družine v datoteki JSON (otroci, psi, dnevnik, pogodbe, ocene). Povezave do slik in videov veljajo približno eno uro.',
  exportDone: 'Izvoz je pripravljen.',
  deleteButton: 'Izbriši račun',
  deleteSubmit: 'Izbriši za vedno',
  lastParent: (children: number, pets: number) => [
    'Ste edini starš v družini, zato se izbriše CELOTNA družina:',
    `vaš račun, ${count(children, 'otroški profil', 'otroška profila', 'otroški profili', 'otroških profilov')} in ${count(pets, 'pes', 'psa', 'psi', 'psov')},`,
    'z vsemi pogodbami (podpisi), dnevnikom skrbi, ocenami, slikami in videi psov,',
    'vse naprave (tudi otroške) bodo odjavljene.',
  ],
  otherParentStays: [
    'Izbriše se samo vaš račun in vaše naprave se odjavijo.',
    'Družina, otroci in psi ostanejo drugemu staršu.',
  ],
  exportFirst: 'Nasvet: pred brisanjem izvozite svoje podatke.',
  deleteErrors: {
    invalid_password: 'Geslo ni pravilno.',
    protected: 'Tega računa ni mogoče izbrisati v aplikaciji.',
    not_found: 'Računa ni bilo mogoče najti.',
    throttled: 'Preveč napačnih gesel. Poskusite znova čez 15 minut.',
    invalid: 'Vpišite geslo in potrdite brisanje.',
    unknown: 'Ni znano, ali je bil izbris izveden — preverite s ponovno prijavo.',
    server: 'Brisanje ni uspelo. Nič ni bilo izbrisano — poskusite znova.',
  } satisfies Record<DeletionErrorKind, string>,
  exportErrors: {
    too_large: 'Podatkov je preveč za izvoz v aplikaciji. Pišite nam na podporo.',
    too_large_to_share:
      'Izvoz je prevelik za deljenje kot besedilo. Izvoz v datoteko pripravljamo — do takrat nam pišite na podporo.',
    throttled: 'Izvoz je mogoč trikrat na uro. Poskusite pozneje.',
    offline: 'Ni povezave s strežnikom. Poskusite znova.',
    server: 'Izvoz ni uspel. Poskusite znova.',
  } satisfies Record<ExportErrorKind, string>,
} as const;

const S = ACCOUNT_STRINGS;

export default function AccountCard({ family }: { family: FamilyOverview | null }) {
  const [exporting, setExporting] = useState(false);
  const [exportMessage, setExportMessage] = useState<{ text: string; isError: boolean } | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  const impact = accountDeletionImpact(family);
  const consequences = impact.lastParent
    ? [...S.lastParent(impact.children, impact.pets), S.exportFirst]
    : [...S.otherParentStays, S.exportFirst];

  const runExport = async () => {
    setExportMessage(null);
    setExporting(true);
    try {
      const shared = await shareFamilyExport();
      if (shared) setExportMessage({ text: S.exportDone, isError: false });
    } catch (err) {
      setExportMessage({ text: S.exportErrors[classifyExportError(err)], isError: true });
    } finally {
      setExporting(false);
    }
  };

  const runDelete = async (password: string) => {
    setDeleteError(null);
    setDeleting(true);
    try {
      // Success signs out: the navigator unmounts this screen (StartScreen).
      await deleteAccountAndLogout(password);
    } catch (err) {
      setDeleteError(S.deleteErrors[classifyDeletionError(err)]);
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
          {exportMessage.text}
        </Text>
      )}

      {confirming ? (
        <DeletionConfirmForm
          consequences={consequences}
          submitLabel={S.deleteSubmit}
          pending={deleting}
          error={deleteError}
          onCancel={() => {
            setConfirming(false);
            setDeleteError(null);
          }}
          onSubmit={(password) => void runDelete(password)}
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
