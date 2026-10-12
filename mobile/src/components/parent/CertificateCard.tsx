/**
 * M2-01d — how a child's 12-week challenge ended, for the parent (CERTIFICATE_SPEC §1):
 *
 *  - certificate, not generated → "Izziv je končan", the final Care Score and
 *    "Ustvari certifikat" (opens `CertificateFormSheet`);
 *  - generated → number, completion date, the printed names, "Odpri certifikat" (the
 *    signed PDF in the system viewer — Share / Save from there) and "Uredi in ustvari
 *    znova" (the same form, prefilled);
 *  - report → "Poročilo o izzivu": final score and why there is no certificate (threshold
 *    70 / the pet went to the shelter), plus a link to the child's 12-week report.
 *
 * Shown on the child card of the overview (`framed={false}`, a soft block inside the
 * card) and on top of the child detail (a card of its own). Light parent theme.
 */

import { useCallback, useState } from 'react';
import { ActivityIndicator, Modal, Pressable, StyleSheet, View } from 'react-native';
import { useQueryClient } from '@tanstack/react-query';
import { Award, ChevronRight, ClipboardList, FileText, Pencil } from 'lucide-react-native';

import type { ParentDashboardResponse } from '@/api/client';
import { Text } from '@/components/ui/Text';
import { Card, PARENT_COLORS as C } from '@/components/parent/ParentUi';
import CertificateFormSheet from '@/components/parent/CertificateFormSheet';
import {
  CERTIFICATE_PARENT_STRINGS as S,
  certificateDate,
  parentCertificateCard,
  reportText,
  type ParentCertificate,
} from '@/modules/certificate/certificate';
import { useOpenCertificatePdf } from '@/modules/certificate/useCertificate';
import { familyFromDashboard } from '@/modules/family/family';
import { parentDashboardKey } from '@/modules/family/live';
import { MIN_TOUCH, fonts, palette, radius, tightTracking } from '@/theme';

interface CertificateCardProps {
  certificate: ParentCertificate | null;
  /** The child's nickname (card texts). */
  childName: string;
  /** false: a soft block inside another card (overview); true: a card of its own (detail). */
  framed?: boolean;
  /** Report: "Odpri 12-tedenski pregled" (overview → the detail; detail → the 84-day period). */
  onOpenReport?: () => void;
  testID?: string;
}

function ScoreBlock({ score, testID }: { score: number | null; testID: string }) {
  return (
    <View style={styles.scoreRow}>
      <Text style={styles.label}>{S.finalScore}</Text>
      {score === null ? (
        <Text style={styles.noScore} testID={testID}>
          {S.noScore}
        </Text>
      ) : (
        <View style={styles.scoreLine}>
          <Text style={styles.score} testID={testID} accessibilityLabel={S.scoreOf(score)}>
            {score}
          </Text>
          <Text style={styles.scoreOf}>/ 100</Text>
        </View>
      )}
    </View>
  );
}

/** Nothing (and no query hooks — the card sits on screens rendered without a client in tests) while the challenge runs. */
export default function CertificateCard(props: CertificateCardProps) {
  return props.certificate ? <FinishedChallengeCard {...props} certificate={props.certificate} /> : null;
}

function FinishedChallengeCard({
  certificate,
  childName,
  framed = true,
  onOpenReport,
  testID = 'certificate-card',
}: CertificateCardProps & { certificate: ParentCertificate }) {
  const card = parentCertificateCard(certificate);
  const [formOpen, setFormOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const queryClient = useQueryClient();
  const certificateId = certificate.id;
  const childId = certificate.child_id;

  // A fresh signed URL (they expire after 60–90 min): refetch the dashboard, read this child's.
  const refresh = useCallback(async () => {
    await queryClient.refetchQueries({ queryKey: parentDashboardKey, exact: true });
    const data = queryClient.getQueryData<ParentDashboardResponse>(parentDashboardKey);
    const child = familyFromDashboard(data)?.children.find((c) => c.id === childId);
    return child?.certificate?.id === certificateId ? (child.certificate?.pdf_url ?? null) : null;
  }, [queryClient, childId, certificateId]);
  const pdf = useOpenCertificatePdf(refresh);

  if (card.kind === 'none') return null;
  const c = card.certificate;

  const closeForm = () => {
    if (!busy) setFormOpen(false);
  };

  const content = (
    <>
      <View style={styles.header}>
        <View style={[styles.iconBadge, card.kind === 'report' && styles.iconBadgeNeutral]}>
          {card.kind === 'report' ? <ClipboardList color={C.text} size={20} /> : <Award color={C.text} size={20} />}
        </View>
        <View style={styles.flex}>
          <Text style={styles.title} accessibilityRole="header">
            {card.kind === 'ready' ? S.readyTitle : card.kind === 'generated' ? S.generatedTitle : S.reportTitle}
          </Text>
          {card.kind === 'generated' && c.number !== null && (
            <Text style={styles.muted} testID={`${testID}-number`}>
              {S.number(c.number)}
            </Text>
          )}
          {card.kind !== 'generated' && c.completed_local_date !== '' && (
            <Text style={styles.muted}>{S.completed(certificateDate(c.completed_local_date))}</Text>
          )}
        </View>
      </View>

      {card.kind === 'ready' && (
        <>
          <Text style={styles.body}>{S.readyBody(childName)}</Text>
          <ScoreBlock score={c.care_score} testID={`${testID}-score`} />
          <Pressable
            style={({ pressed }) => [styles.primary, pressed && styles.pressed]}
            onPress={() => setFormOpen(true)}
            accessibilityRole="button"
            testID={`${testID}-create`}
          >
            <Award color={palette.white} size={18} />
            <Text style={styles.primaryText}>{S.create}</Text>
          </Pressable>
        </>
      )}

      {card.kind === 'generated' && (
        <>
          <ScoreBlock score={c.care_score} testID={`${testID}-score`} />
          {c.completed_local_date !== '' && <Text style={styles.muted}>{S.completed(certificateDate(c.completed_local_date))}</Text>}
          {c.child_name !== null && c.pet_name !== null && (
            <Text style={styles.body} testID={`${testID}-printed`}>
              {S.printedFor(c.child_name, c.pet_name)}
            </Text>
          )}
          <Pressable
            style={({ pressed }) => [styles.primary, pdf.opening && styles.disabled, pressed && styles.pressed]}
            onPress={() => void pdf.open(c.pdf_url)}
            disabled={pdf.opening}
            accessibilityRole="button"
            accessibilityState={{ disabled: pdf.opening, busy: pdf.opening }}
            testID={`${testID}-open`}
          >
            {pdf.opening ? (
              <ActivityIndicator color={palette.white} />
            ) : (
              <>
                <FileText color={palette.white} size={18} />
                <Text style={styles.primaryText}>{S.open}</Text>
              </>
            )}
          </Pressable>
          {pdf.failed && (
            <Text style={styles.error} accessibilityRole="alert" testID={`${testID}-open-error`}>
              {S.openError}
            </Text>
          )}
          <Pressable
            style={({ pressed }) => [styles.secondary, pressed && styles.pressed]}
            onPress={() => setFormOpen(true)}
            accessibilityRole="button"
            testID={`${testID}-edit`}
          >
            <Pencil color={C.accent} size={16} />
            <Text style={styles.secondaryText}>{S.edit}</Text>
          </Pressable>
        </>
      )}

      {card.kind === 'report' && (
        <>
          <ScoreBlock score={c.care_score} testID={`${testID}-score`} />
          <Text style={styles.body} testID={`${testID}-reason`}>
            {reportText(c)}
          </Text>
          {onOpenReport ? (
            <Pressable
              style={({ pressed }) => [styles.link, pressed && styles.pressed]}
              onPress={onOpenReport}
              accessibilityRole="link"
              accessibilityLabel={S.openReportA11y(childName)}
              testID={`${testID}-open-report`}
            >
              <Text style={styles.linkText}>{S.openReport}</Text>
              <ChevronRight color={C.link} size={16} />
            </Pressable>
          ) : (
            <Text style={styles.muted}>{S.reportHint}</Text>
          )}
        </>
      )}

      <Modal visible={formOpen} transparent animationType="slide" onRequestClose={closeForm} statusBarTranslucent>
        {formOpen && card.kind !== 'report' && (
          <CertificateFormSheet
            certificate={c}
            onClose={closeForm}
            onBusyChange={setBusy}
            onDone={() => setFormOpen(false)}
            testID={`${testID}-form`}
          />
        )}
      </Modal>
    </>
  );

  return framed ? (
    <Card testID={`${testID}-${card.kind}`}>{content}</Card>
  ) : (
    <View style={styles.inline} testID={`${testID}-${card.kind}`}>
      {content}
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  inline: {
    gap: 12,
    padding: 14,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: C.accentBorder,
    backgroundColor: C.accentSoft,
  },
  header: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  iconBadge: {
    width: 40,
    height: 40,
    borderRadius: radius.pill,
    backgroundColor: palette.mint,
    alignItems: 'center',
    justifyContent: 'center',
  },
  iconBadgeNeutral: { backgroundColor: C.divider },
  title: { fontFamily: fonts.displayBold, fontSize: 18, letterSpacing: tightTracking(18), color: C.text },
  muted: { fontSize: 13, color: C.muted },
  body: { fontSize: 14, lineHeight: 20, color: C.text },
  label: { fontSize: 12, fontWeight: '700', color: C.muted, textTransform: 'uppercase', letterSpacing: 0.4 },
  scoreRow: { gap: 2 },
  scoreLine: { flexDirection: 'row', alignItems: 'baseline', gap: 6 },
  score: { fontFamily: fonts.display, fontSize: 40, letterSpacing: tightTracking(40), color: C.text, fontVariant: ['tabular-nums'] },
  scoreOf: { fontSize: 15, fontWeight: '600', color: C.muted },
  noScore: { fontSize: 18, fontWeight: '700', color: C.text },
  primary: {
    flexDirection: 'row',
    gap: 8,
    minHeight: 48,
    borderRadius: radius.button,
    backgroundColor: C.accent,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 16,
  },
  primaryText: { fontSize: 15, fontWeight: '700', color: palette.white },
  secondary: {
    flexDirection: 'row',
    gap: 8,
    minHeight: MIN_TOUCH,
    borderRadius: radius.button,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.card,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 16,
  },
  secondaryText: { fontSize: 15, fontWeight: '700', color: C.accent },
  link: { flexDirection: 'row', alignItems: 'center', gap: 4, minHeight: MIN_TOUCH },
  linkText: { fontSize: 14, fontWeight: '700', color: C.link },
  error: { fontSize: 13, lineHeight: 18, color: C.redText },
  disabled: { opacity: 0.6 },
  pressed: { opacity: 0.85 },
});
