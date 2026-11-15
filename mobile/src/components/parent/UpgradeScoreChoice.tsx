/**
 * UpgradeScoreChoice — M6-U03 (FREE_PET_UPGRADE_SPEC U7, David 2026-11-15): on the paywall of a
 * free pet's upgrade the parent chooses what happens to the Care Score so far: »Obdrži dosedanjo
 * oceno« or »Začni na novo«. Each option says in plain words what it means for the score and
 * the certificate (David: the parent must be able to read it before choosing), a warning shows
 * when a score below the certificate threshold would be kept (and a note when a good one would
 * be dropped), and the parent is told the choice is final. Parent app only, no prices.
 */

import { Pressable, StyleSheet, View } from 'react-native';

import { Text } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { t } from '@/i18n';
import { CERTIFICATE_MIN_SCORE, lowestScore, scoreChoiceWarning, type CaretakerScore } from '@/modules/plan/scoreChoice';
import { palette } from '@/theme';

interface Props {
  petId: number;
  scores: readonly CaretakerScore[];
  keep: boolean;
  disabled?: boolean;
  onChange: (keep: boolean) => void;
}

export default function UpgradeScoreChoice({ petId, scores, keep, disabled = false, onChange }: Props) {
  const lowest = lowestScore(scores);
  if (lowest === null) return null;
  const warning = scoreChoiceWarning(scores, keep);
  const current =
    scores.length === 1
      ? t('paywall:scoreChoice.current', { score: scores[0].score })
      : t('paywall:scoreChoice.currentMany', { list: scores.map((s) => `${s.name} ${s.score}`).join(', ') });

  const option = (value: boolean, title: string, body: string) => {
    const selected = keep === value;
    return (
      <Pressable
        style={({ pressed }) => [styles.option, selected && styles.optionSelected, pressed && styles.pressed]}
        disabled={disabled}
        onPress={() => onChange(value)}
        accessibilityRole="radio"
        accessibilityState={{ checked: selected, disabled }}
        testID={`score-choice-${value ? 'keep' : 'fresh'}-${petId}`}
      >
        <View style={[styles.radio, selected && styles.radioSelected]}>{selected && <View style={styles.radioDot} />}</View>
        <View style={styles.flex}>
          <Text style={styles.optionTitle}>{title}</Text>
          <Text style={styles.optionBody}>{body}</Text>
        </View>
      </Pressable>
    );
  };

  return (
    <View style={styles.box} accessibilityRole="radiogroup" accessibilityLabel={t('paywall:scoreChoice.title')} testID={`score-choice-${petId}`}>
      <Text style={styles.title}>{t('paywall:scoreChoice.title')}</Text>
      <Text style={styles.body}>{current}</Text>
      {option(true, t('paywall:scoreChoice.keepTitle'), t('paywall:scoreChoice.keepBody'))}
      {option(false, t('paywall:scoreChoice.freshTitle'), t('paywall:scoreChoice.freshBody'))}
      {warning !== null && (
        <View style={[styles.note, warning === 'keepLow' ? styles.noteWarn : styles.noteInfo]} testID={`score-choice-warning-${petId}`}>
          <Text style={styles.body}>
            {warning === 'keepLow'
              ? t('paywall:scoreChoice.warnKeepLow', { score: lowest, min: CERTIFICATE_MIN_SCORE })
              : t('paywall:scoreChoice.noteDropGood', { score: lowest })}
          </Text>
        </View>
      )}
      <Text style={styles.muted}>{t('paywall:scoreChoice.final', { min: CERTIFICATE_MIN_SCORE })}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  box: { marginTop: 12, gap: 8 },
  flex: { flex: 1 },
  title: { fontSize: 15, fontWeight: '700', color: C.text },
  body: { fontSize: 14, lineHeight: 20, color: C.text },
  muted: { fontSize: 12, lineHeight: 17, color: C.muted },
  option: { flexDirection: 'row', gap: 10, padding: 12, borderRadius: 12, borderWidth: 1, borderColor: C.border, backgroundColor: C.card },
  optionSelected: { borderColor: C.accent, borderWidth: 2, padding: 11 },
  optionTitle: { fontSize: 15, fontWeight: '700', color: C.text, marginBottom: 2 },
  optionBody: { fontSize: 13, lineHeight: 19, color: C.text },
  radio: { width: 22, height: 22, borderRadius: 11, borderWidth: 2, borderColor: C.muted, alignItems: 'center', justifyContent: 'center', marginTop: 1 },
  radioSelected: { borderColor: C.accent },
  radioDot: { width: 10, height: 10, borderRadius: 5, backgroundColor: C.accent },
  note: { padding: 12, borderRadius: 12, borderWidth: 1 },
  noteWarn: { backgroundColor: palette.warnSoft, borderColor: palette.warnBorder },
  noteInfo: { backgroundColor: palette.okSoft, borderColor: palette.okBorder },
  pressed: { opacity: 0.85 },
});
