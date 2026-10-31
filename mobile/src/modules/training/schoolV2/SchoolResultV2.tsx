/**
 * School v2 result (GAMES_SPEC §4.1 »Zvezdice«, »Predčasen konec«): the server's scored result as a
 * full-screen dark glass card — title by M5-F04 (`trainingResultBucket`, never false praise), the
 * stars (display only: in time or rightly waited), every cue's outcome per round (✓ mint / ✗ amber —
 * never red), the progress line and the routine line (an early end after round 1 counts for the
 * progress, not for today's »Šolanje« routine — `counts_for_routine`).
 *
 * Plain React Native (no game-layer import) — TrainingOverlay renders it for a v2 result.
 */

import { Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Check, Star, X } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { isStarOutcome, resultRounds } from '@/modules/training/schoolV2/schoolV2';
import {
  SCHOOL_V2_STRINGS,
  TRAINING_STRINGS,
  trainingResultBucket,
  trainingResultCounts,
  type TrainingResult,
  type TrainingResultBucket,
} from '@/modules/training/training';
import { alpha, fonts, palette, radius, tightTracking } from '@/theme';

const S = SCHOOL_V2_STRINGS;
const T = TRAINING_STRINGS;

function titleOf(bucket: TrainingResultBucket): string {
  switch (bucket) {
    case 'excellent':
      return T.result.titleExcellent;
    case 'good':
      return T.result.titleGood;
    case 'practice':
      return T.result.titlePractice;
    case 'none':
      return T.result.titleNone;
  }
}

export interface SchoolResultV2Props {
  result: TrainingResult;
  status: 'accepted' | 'unchanged';
  canStartAgain: boolean;
  onAgain: () => void;
  onDone: () => void;
  testID?: string;
}

export default function SchoolResultV2({ result, status, canStartAgain, onAgain, onDone, testID = 'training-result' }: SchoolResultV2Props) {
  const name = T.commands[result.command].name;
  const stored = status === 'unchanged';
  const counts = trainingResultCounts(result);
  const stars = result.trials.filter((t) => isStarOutcome(t.outcome)).length;
  const learned = result.progress_after >= 100;
  let gainLine: string;
  if (result.progress_after > result.progress_before) gainLine = T.result.gain(name, result.progress_before, result.progress_after);
  else if (result.progress_gain > 0) gainLine = T.result.gainSmall(name, result.progress_after);
  else gainLine = T.result.noGain;
  const routineLine = !result.counts_for_routine ? S.result.earlyEnd : stored ? null : T.result.routineDone;

  return (
    <View style={styles.backdrop} testID={testID}>
      <ScrollView contentContainerStyle={styles.content}>
        <Text style={styles.title} testID="training-result-title" accessibilityRole="header">
          {stored ? T.result.titleStored : titleOf(trainingResultBucket(result))}
        </Text>
        {stored && <Text style={styles.muted}>{T.result.stored}</Text>}
        <View style={styles.stars} accessible accessibilityLabel={S.result.stars(stars, result.trials.length)} testID="training-result-stars">
          {result.trials.map((t, i) => (
            <Star key={t.index} size={30} color={palette.warnDark} fill={i < stars ? palette.warnDark : 'transparent'} />
          ))}
        </View>
        {resultRounds(result.trials).map((round, r) => (
          <View key={r} style={styles.round} testID={`training-result-round-${r + 1}`}>
            <Text style={styles.roundTitle}>{S.result.roundTitle(r + 1)}</Text>
            <View style={styles.cues}>
              {round.map((t) => {
                const good = isStarOutcome(t.outcome);
                return (
                  <View
                    key={t.index}
                    style={[styles.cue, good ? styles.cueGood : styles.cueTry]}
                    accessible
                    accessibilityLabel={S.result.trialA11y(t.index + 1, T.outcomeShort[t.outcome])}
                    testID={`training-result-trial-${t.index}`}
                  >
                    {good ? <Check color={palette.mint} size={22} strokeWidth={3} /> : <X color={palette.warnDark} size={22} strokeWidth={3} />}
                    <Text style={styles.cueText}>{T.outcomeShort[t.outcome]}</Text>
                  </View>
                );
              })}
            </View>
          </View>
        ))}
        <Text style={styles.body} testID="training-result-successes">
          {T.result.successes(counts.onTime, counts.obeyed)}
        </Text>
        <Text style={styles.bodyStrong} testID="training-result-gain">
          {gainLine}
        </Text>
        {learned && (
          <Text style={styles.learned} testID="training-result-learned">
            {T.result.learned(name)}
          </Text>
        )}
        {routineLine !== null && (
          <Text style={styles.muted} testID="training-result-routine">
            {routineLine}
          </Text>
        )}
        <View style={styles.row}>
          {canStartAgain && (
            <Pressable accessibilityRole="button" testID="training-again" onPress={onAgain} style={({ pressed }) => [styles.secondary, pressed && styles.pressed]}>
              <Text style={styles.secondaryText}>{T.result.again}</Text>
            </Pressable>
          )}
          <Pressable accessibilityRole="button" testID="training-done" onPress={onDone} style={({ pressed }) => [styles.primary, pressed && styles.pressed]}>
            <Text style={styles.primaryText}>{T.result.done}</Text>
          </Pressable>
        </View>
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  backdrop: { ...StyleSheet.absoluteFill, zIndex: 60, backgroundColor: alpha(palette.graphite, 0.97), paddingTop: 64, paddingHorizontal: 16, paddingBottom: 28 },
  content: { gap: 14, paddingBottom: 24, alignItems: 'stretch' },
  title: { color: palette.white, fontSize: 30, letterSpacing: tightTracking(30), fontFamily: fonts.display, textAlign: 'center' },
  stars: { flexDirection: 'row', justifyContent: 'center', gap: 6 },
  round: {
    gap: 8,
    padding: 14,
    borderRadius: 20,
    backgroundColor: alpha(palette.white, 0.06),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.12),
  },
  roundTitle: { color: palette.n300, fontSize: 14, fontWeight: '700' },
  cues: { flexDirection: 'row', gap: 8 },
  cue: { flex: 1, alignItems: 'center', gap: 4, paddingVertical: 10, borderRadius: 14 },
  cueGood: { backgroundColor: alpha(palette.okDark, 0.2) },
  cueTry: { backgroundColor: alpha(palette.warnDark, 0.16) },
  cueText: { color: palette.n200, fontSize: 12, fontWeight: '600', textAlign: 'center' },
  body: { color: palette.n200, fontSize: 15, textAlign: 'center' },
  bodyStrong: { color: palette.white, fontSize: 16, fontWeight: '700', textAlign: 'center' },
  learned: { color: palette.mint, fontSize: 18, letterSpacing: tightTracking(18), fontFamily: fonts.displayBold, textAlign: 'center' },
  muted: { color: palette.n400, fontSize: 13, textAlign: 'center' },
  row: { flexDirection: 'row', gap: 10, marginTop: 8 },
  primary: { flex: 1, minHeight: 52, borderRadius: radius.button, backgroundColor: palette.mint, alignItems: 'center', justifyContent: 'center' },
  primaryText: { color: palette.graphite, fontSize: 16, fontWeight: '800' },
  secondary: {
    minHeight: 52,
    paddingHorizontal: 18,
    borderRadius: radius.button,
    backgroundColor: alpha(palette.white, 0.08),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.2),
    alignItems: 'center',
    justifyContent: 'center',
  },
  secondaryText: { color: palette.n200, fontSize: 15, fontWeight: '700' },
  pressed: { opacity: 0.8 },
});
