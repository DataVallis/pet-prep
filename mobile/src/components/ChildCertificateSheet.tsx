/**
 * M2-01d — the child's view of a finished challenge (CERTIFICATE_SPEC §1), opened from the
 * award button in the HUD header. Same dark glass bottom sheet as the language sheet.
 *
 *  - generated → the certificate as a native card styled like the PDF (white paper, the
 *    "Radovednež" mark, "12-tedenski PetPrep izziv", "Certifikat odgovornosti", the name,
 *    Care Score / pet / completion date, the number) + "Odpri PDF" (system viewer);
 *  - not generated yet → "Izziv je končan! Starš ti bo pripravil certifikat.";
 *  - report → a kind "Izziv je končan. Bravo, da si vztrajal(a)!" — never a score or a
 *    reason (no shaming; the server sends neither).
 *
 * Child voice: "ti", short. The PDF URL is refreshed before opening (it expires).
 */

import { useCallback, useContext } from 'react';
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { useQueryClient } from '@tanstack/react-query';
import { FileText, Heart, PartyPopper, X } from 'lucide-react-native';

import { BrandMark } from '@/components/brand/BrandLogo';
import { Text } from '@/components/ui/Text';
import { childPetKey } from '@/hooks/queries/useChildPet';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import {
  CERTIFICATE_CHILD_STRINGS as S,
  certificateDate,
  childCertificateEntry,
  type ChildCertificate,
} from '@/modules/certificate/certificate';
import { useOpenCertificatePdf } from '@/modules/certificate/useCertificate';
import { MIN_TOUCH, alpha, dark, fonts, light, palette, radius, tightTracking } from '@/theme';

interface ChildCertificateSheetProps {
  visible: boolean;
  certificate: ChildCertificate | null;
  onClose: () => void;
  testID?: string;
}

function PaperCertificate({ certificate, testID }: { certificate: ChildCertificate; testID: string }) {
  const date = certificateDate(certificate.completed_at);
  return (
    <View style={styles.paper} testID={`${testID}-paper`}>
      <BrandMark size={44} tone="light" testID={`${testID}-mark`} />
      <Text style={styles.paperBadge}>{S.badge.toUpperCase()}</Text>
      <Text style={styles.paperTitle} accessibilityRole="header">
        {S.title}
      </Text>
      <Text style={styles.paperAwarded}>{S.awardedTo}</Text>
      {certificate.child_name !== null && (
        <Text style={styles.paperName} testID={`${testID}-name`}>
          {certificate.child_name}
        </Text>
      )}
      <View style={styles.paperFacts}>
        <View style={styles.paperFact}>
          <Text style={styles.paperFactLabel}>{S.careScore}</Text>
          <Text style={styles.paperFactValue} testID={`${testID}-score`}>
            {certificate.care_score ?? '—'}
          </Text>
          <Text style={styles.paperFactSub}>{S.outOf}</Text>
        </View>
        <View style={styles.paperFact}>
          <Text style={styles.paperFactLabel}>{S.pet}</Text>
          <Text style={styles.paperFactText} numberOfLines={2} testID={`${testID}-pet`}>
            {certificate.pet_name ?? '—'}
          </Text>
        </View>
        <View style={styles.paperFact}>
          <Text style={styles.paperFactLabel}>{S.completed}</Text>
          <Text style={styles.paperFactText} numberOfLines={2}>
            {date !== '' ? date : '—'}
          </Text>
        </View>
      </View>
      {certificate.number !== null && (
        <Text style={styles.paperNumber} testID={`${testID}-number`}>
          {`petprep.si · ${S.number(certificate.number)}`}
        </Text>
      )}
    </View>
  );
}

export default function ChildCertificateSheet({ visible, certificate, onClose, testID = 'hud-certificate' }: ChildCertificateSheetProps) {
  const bottomInset = useContext(SafeAreaInsetsContext)?.bottom ?? 0;
  const entry = childCertificateEntry(certificate);
  const queryClient = useQueryClient();

  const refresh = useCallback(async () => {
    await queryClient.refetchQueries({ queryKey: childPetKey, exact: true });
    return queryClient.getQueryData<ChildPetView>(childPetKey)?.certificate?.pdf_url ?? null;
  }, [queryClient]);
  const pdf = useOpenCertificatePdf(refresh);

  if (entry.kind === 'none') return null;

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <View style={styles.backdrop}>
        <Pressable
          style={StyleSheet.absoluteFill}
          onPress={onClose}
          accessible={false}
          importantForAccessibility="no"
          testID={`${testID}-backdrop`}
        />
        <View style={[styles.sheet, { paddingBottom: 24 + bottomInset }]} testID={testID} accessibilityViewIsModal>
          <View style={styles.headerRow}>
            <View style={styles.flex} />
            <Pressable
              onPress={onClose}
              accessibilityRole="button"
              accessibilityLabel={S.close}
              style={({ pressed }) => [styles.close, pressed && styles.pressed]}
              testID={`${testID}-close`}
            >
              <X color={palette.white} size={20} />
            </Pressable>
          </View>

          <ScrollView contentContainerStyle={styles.body}>
            {entry.kind === 'generated' ? (
              <>
                <PaperCertificate certificate={entry.certificate} testID={testID} />
                <Pressable
                  style={({ pressed }) => [styles.primary, pdf.opening && styles.disabled, pressed && styles.pressed]}
                  onPress={() => void pdf.open(entry.certificate.pdf_url)}
                  disabled={pdf.opening}
                  accessibilityRole="button"
                  accessibilityState={{ disabled: pdf.opening, busy: pdf.opening }}
                  testID={`${testID}-open-pdf`}
                >
                  {pdf.opening ? (
                    <ActivityIndicator color={palette.graphite} />
                  ) : (
                    <>
                      <FileText color={palette.graphite} size={18} />
                      <Text style={styles.primaryText}>{S.openPdf}</Text>
                    </>
                  )}
                </Pressable>
                {pdf.failed && (
                  <Text style={styles.error} accessibilityRole="alert" testID={`${testID}-open-error`}>
                    {S.openError}
                  </Text>
                )}
              </>
            ) : (
              <View style={styles.note} testID={`${testID}-${entry.kind}`}>
                <View style={styles.noteIcon}>
                  {entry.kind === 'waiting' ? (
                    <PartyPopper color={palette.graphite} size={26} />
                  ) : (
                    <Heart color={palette.graphite} size={26} />
                  )}
                </View>
                <Text style={styles.noteTitle} accessibilityRole="header">
                  {entry.kind === 'waiting' ? S.waitingTitle : S.finishedTitle}
                </Text>
                <Text style={styles.noteBody}>{entry.kind === 'waiting' ? S.waitingBody : S.finishedBody}</Text>
              </View>
            )}
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  backdrop: { flex: 1, justifyContent: 'flex-end', backgroundColor: alpha(palette.black, 0.5) },
  sheet: {
    maxHeight: '92%',
    paddingHorizontal: 20,
    paddingTop: 12,
    borderTopLeftRadius: radius.sheet,
    borderTopRightRadius: radius.sheet,
    backgroundColor: dark.glassStrong,
    borderWidth: 1,
    borderColor: dark.glassBorder,
  },
  headerRow: { flexDirection: 'row', alignItems: 'center' },
  close: { width: MIN_TOUCH, height: MIN_TOUCH, alignItems: 'center', justifyContent: 'center' },
  body: { gap: 14, paddingBottom: 4 },
  paper: {
    alignItems: 'center',
    gap: 8,
    paddingHorizontal: 18,
    paddingVertical: 22,
    borderRadius: radius.card,
    backgroundColor: light.surface,
  },
  paperBadge: { marginTop: 4, fontSize: 11, fontWeight: '700', letterSpacing: 1.2, color: light.mintText },
  paperTitle: {
    fontFamily: fonts.display,
    fontSize: 26,
    letterSpacing: tightTracking(26),
    color: light.ink,
    textAlign: 'center',
  },
  paperAwarded: { marginTop: 6, fontSize: 13, color: light.inkMuted },
  paperName: {
    fontFamily: fonts.display,
    fontSize: 32,
    letterSpacing: tightTracking(32),
    color: light.ink,
    textAlign: 'center',
  },
  paperFacts: { flexDirection: 'row', gap: 8, marginTop: 10, alignSelf: 'stretch' },
  paperFact: {
    flex: 1,
    alignItems: 'center',
    gap: 2,
    paddingVertical: 10,
    paddingHorizontal: 6,
    borderRadius: 14,
    backgroundColor: light.bg,
  },
  paperFactLabel: { fontSize: 10, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.4, color: light.inkMuted, textAlign: 'center' },
  paperFactValue: { fontFamily: fonts.display, fontSize: 26, letterSpacing: tightTracking(26), color: light.ink },
  paperFactSub: { fontSize: 11, color: light.inkMuted },
  paperFactText: { fontSize: 14, fontWeight: '700', color: light.ink, textAlign: 'center' },
  paperNumber: { marginTop: 8, fontSize: 11, color: light.inkMuted },
  primary: {
    flexDirection: 'row',
    gap: 8,
    minHeight: 50,
    borderRadius: radius.button,
    backgroundColor: dark.action,
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryText: { fontSize: 16, fontWeight: '700', color: dark.onAction },
  error: { fontSize: 13, lineHeight: 18, color: dark.danger, textAlign: 'center' },
  note: { alignItems: 'center', gap: 10, paddingVertical: 12 },
  noteIcon: {
    width: 56,
    height: 56,
    borderRadius: radius.pill,
    backgroundColor: palette.mint,
    alignItems: 'center',
    justifyContent: 'center',
  },
  noteTitle: { fontFamily: fonts.display, fontSize: 24, letterSpacing: tightTracking(24), color: dark.ink, textAlign: 'center' },
  noteBody: { fontSize: 16, lineHeight: 22, color: dark.ink, textAlign: 'center' },
  disabled: { opacity: 0.6 },
  pressed: { opacity: 0.85 },
});
