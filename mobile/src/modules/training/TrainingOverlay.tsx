/**
 * "Šola" — the training screen over the child HUD (M5-R03, PRODUCT_SPEC §5; dark glass
 * HUD style, ADR-007).
 *
 * 1. Pick a command: progress bar per command ("naučeno ✓" at 100 %), today's routine,
 *    sessions left today. "Začni vajo" follows the server's `can_start`; when it is off
 *    the reason is explained (a sibling is training, the daily budget is used …).
 * 2. The 50 s game: the cue ("Sedi!"), the dog's reaction from the server's schedule
 *    (free tier: an animated illustration + a line of text; premium: the pet's own
 *    idle / playing video — no new media), a big "Pohvali" button and an immediate,
 *    kind verdict per cue (bravo / prezgodaj / prepozno / počakal(a) si).
 * 3. The server's result: progress gain, "Kuža zna …" at 100 %, never shaming.
 * The overlay can't be closed while a session runs or is being saved.
 */

import { useEffect, useMemo, useRef } from 'react';
import { ActivityIndicator, Animated, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Check, GraduationCap, Heart, X } from 'lucide-react-native';
import { useQueryClient } from '@tanstack/react-query';

import PetMediaView from '@/components/PetMediaView';
import { childPetKey } from '@/hooks/queries/useChildPet';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { selectMediaSource } from '@/modules/petMedia/petMedia';
import { feedbackAt, firstTapsBySlot, frameAt, liveOutcome, type ClockSources, type DogAction } from '@/modules/training/game';
import {
  sessionsLeftToday,
  startBlock,
  TRAINING_STRINGS,
  type CommandProgress,
  type TrainingResult,
  type TrainingSession,
  type TrialOutcome,
} from '@/modules/training/training';
import { useTrainingGame } from '@/modules/training/useTrainingGame';

const S = TRAINING_STRINGS;

/** A press release this soon after its touch-down is the same tap (not a second praise). */
const RELEASE_IGNORE_MS = 2_000;

/** Good verdicts are emerald; the others a calm amber — never the rose alarm colour. */
const GOOD: ReadonlySet<TrialOutcome> = new Set(['in_time', 'waited']);

export interface TrainingOverlayProps {
  view: ChildPetView;
  onClose: () => void;
  /** Injected in tests (game clock). */
  clock?: ClockSources;
  testID?: string;
}

function ProgressBar({ value, learned, testID }: { value: number; learned: boolean; testID?: string }) {
  const clamped = Math.max(0, Math.min(100, value));
  return (
    <View style={styles.track} testID={testID} accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: 100, now: clamped }}>
      <View style={[styles.fill, { width: `${clamped}%` }, learned && styles.fillLearned]} />
    </View>
  );
}

function CommandRow({
  item,
  canStart,
  onStart,
}: {
  item: CommandProgress;
  canStart: boolean;
  onStart: () => void;
}) {
  const text = S.commands[item.command];
  return (
    <View style={styles.commandCard} testID={`training-command-${item.command}`}>
      <View style={styles.commandHeader}>
        <Text style={styles.commandName}>{text.name}</Text>
        {item.learned ? (
          <View style={styles.learnedPill} testID={`training-learned-${item.command}`}>
            <Check color="#ffffff" size={12} />
            <Text style={styles.learnedText}>{S.learned}</Text>
          </View>
        ) : (
          <Text style={styles.percent}>{item.progress} %</Text>
        )}
      </View>
      <ProgressBar value={item.progress} learned={item.learned} testID={`training-progress-${item.command}`} />
      <Pressable
        testID={`training-start-${item.command}`}
        accessibilityRole="button"
        accessibilityLabel={S.startA11y(text.name)}
        accessibilityState={{ disabled: !canStart }}
        disabled={!canStart}
        onPress={onStart}
        style={({ pressed }) => [styles.startButton, !canStart && styles.disabled, pressed && canStart && styles.pressed]}
      >
        <Text style={styles.startText}>{S.start}</Text>
      </Pressable>
    </View>
  );
}

/** Free-tier dog: a calm animated illustration that sits / runs / looks away with the schedule. */
function DogIllustration({ action }: { action: DogAction }) {
  const pose = useRef(new Animated.Value(0)).current;
  const tilt = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    Animated.parallel([
      Animated.timing(pose, { toValue: action === 'obeying' ? 1 : 0, duration: 250, useNativeDriver: true }),
      Animated.timing(tilt, { toValue: action === 'ignoring' ? 1 : 0, duration: 300, useNativeDriver: true }),
    ]).start();
  }, [action, pose, tilt]);
  const translateY = pose.interpolate({ inputRange: [0, 1], outputRange: [0, 14] });
  const scale = pose.interpolate({ inputRange: [0, 1], outputRange: [1, 1.08] });
  const rotate = tilt.interpolate({ inputRange: [0, 1], outputRange: ['0deg', '-14deg'] });
  return (
    <View style={styles.dogStage} testID="training-dog-illustration">
      <Animated.View style={[styles.dogCircle, action === 'obeying' && styles.dogCircleObeying, { transform: [{ translateY }, { scale }, { rotate }] }]}>
        <Text style={styles.dogEmoji}>🐕</Text>
      </Animated.View>
    </View>
  );
}

function dogLine(session: TrainingSession, action: DogAction): string {
  const text = S.commands[session.command];
  switch (action) {
    case 'waiting':
      return S.getReady;
    case 'listening':
      return S.listening;
    case 'obeying':
      return text.obeys;
    case 'ignoring':
      return text.ignores;
  }
}

function TrialDots({ session, taps, elapsedMs }: { session: TrainingSession; taps: readonly number[]; elapsedMs: number }) {
  const firsts = firstTapsBySlot(session.trials, taps);
  return (
    <View style={styles.dots}>
      {session.trials.map((trial, i) => {
        const outcome = liveOutcome(session, i, firsts.get(i) ?? null, elapsedMs);
        const style = outcome === null ? styles.dotOpen : GOOD.has(outcome) ? styles.dotGood : styles.dotTry;
        return <View key={trial.index} style={[styles.dot, style]} testID={`training-dot-${i}`} />;
      })}
    </View>
  );
}

function ResultView({ result, onDone, onAgain, canStartAgain }: { result: TrainingResult; onDone: () => void; onAgain: () => void; canStartAgain: boolean }) {
  const name = S.commands[result.command].name;
  const good = result.successes > 0;
  const learned = result.progress_after >= 100;
  let gainLine: string;
  if (result.progress_after > result.progress_before) gainLine = S.result.gain(name, result.progress_before, result.progress_after);
  else if (result.progress_gain > 0) gainLine = S.result.gainSmall(name, result.progress_after);
  else gainLine = S.result.noGain;
  return (
    <View style={styles.resultBox} testID="training-result">
      <Text style={styles.resultTitle}>{good ? S.result.titleGood : S.result.titleLearning}</Text>
      <Text style={styles.body} testID="training-result-successes">
        {S.result.successes(result.successes, result.obeyed)}
      </Text>
      <Text style={styles.bodyStrong} testID="training-result-gain">
        {gainLine}
      </Text>
      {learned && (
        <Text style={styles.learnedLine} testID="training-result-learned">
          {S.result.learned(name)}
        </Text>
      )}
      <Text style={styles.muted}>{S.result.routineDone}</Text>
      <View style={styles.chips}>
        {result.trials.map((t) => (
          <View key={t.index} style={[styles.chip, GOOD.has(t.outcome) ? styles.chipGood : styles.chipTry]} testID={`training-result-trial-${t.index}`}>
            <Text style={styles.chipText}>
              {t.index + 1}. {S.outcomeShort[t.outcome]}
            </Text>
          </View>
        ))}
      </View>
      <View style={styles.row}>
        {canStartAgain && (
          <Pressable accessibilityRole="button" testID="training-again" onPress={onAgain} style={({ pressed }) => [styles.secondaryButton, pressed && styles.pressed]}>
            <Text style={styles.secondaryText}>{S.result.again}</Text>
          </Pressable>
        )}
        <Pressable accessibilityRole="button" testID="training-done" onPress={onDone} style={({ pressed }) => [styles.startButton, styles.flexButton, pressed && styles.pressed]}>
          <Text style={styles.startText}>{S.result.done}</Text>
        </Pressable>
      </View>
    </View>
  );
}

export default function TrainingOverlay({ view, onClose, clock, testID = 'training-overlay' }: TrainingOverlayProps) {
  const queryClient = useQueryClient();
  const training = view.training;
  const game = useTrainingGame({ clockSkewMs: view.clockSkewMs, timezone: view.timezone, clock });
  const { phase, elapsedMs } = game;
  const busy = phase.kind === 'running' || phase.kind === 'finishing' || phase.kind === 'starting';
  const premiumVideo = useMemo(() => selectMediaSource(view.pet.media, 'idle').kind === 'video', [view.pet.media]);
  const block = startBlock(training);
  const left = sessionsLeftToday(training);

  return (
    <View style={styles.overlay} testID={testID} accessibilityViewIsModal>
      <View style={styles.header}>
        <View style={styles.titleRow}>
          <GraduationCap color="#a5b4fc" size={22} />
          <Text style={styles.title}>{S.title}</Text>
        </View>
        {!busy && (
          <Pressable accessibilityRole="button" accessibilityLabel={S.close} testID="training-close" onPress={onClose} hitSlop={10} style={({ pressed }) => [styles.closeButton, pressed && styles.pressed]}>
            <X color="#cbd5e1" size={20} />
          </Pressable>
        )}
      </View>

      {phase.kind === 'pick' && (
        <ScrollView contentContainerStyle={styles.content} testID="training-pick">
          <Text style={styles.body}>{S.intro}</Text>
          <Text style={[styles.bodyStrong, training.today_done && styles.doneText]} testID="training-today">
            {training.today_done ? S.todayDone : S.todayOpen}
          </Text>
          <Text style={styles.muted} testID="training-left">
            {S.sessionsLeft(left)} {S.sessionLength(training.session_seconds)}
          </Text>
          {block !== null && (
            <Text style={styles.blocked} testID="training-blocked">
              {S.blocked[block]}
            </Text>
          )}
          {training.commands.map((item) => (
            <CommandRow key={item.command} item={item} canStart={training.can_start} onStart={() => game.start(item.command)} />
          ))}
          <Text style={styles.muted}>{S.howTo}</Text>
        </ScrollView>
      )}

      {phase.kind === 'starting' && (
        <View style={styles.centered} testID="training-starting">
          <ActivityIndicator color="#a5b4fc" />
          <Text style={styles.body}>{S.starting}</Text>
        </View>
      )}

      {(phase.kind === 'running' || phase.kind === 'finishing') && (
        <RunningView
          session={phase.session}
          taps={phase.taps}
          elapsedMs={elapsedMs}
          finishing={phase.kind === 'finishing'}
          premiumVideo={premiumVideo}
          view={view}
          onPraise={game.praise}
          onMediaExpired={() => void queryClient.invalidateQueries({ queryKey: childPetKey })}
        />
      )}

      {phase.kind === 'result' && (
        <ScrollView contentContainerStyle={styles.content}>
          <ResultView result={phase.result} onDone={onClose} onAgain={game.reset} canStartAgain={left > 0} />
        </ScrollView>
      )}

      {phase.kind === 'failed' && (
        <View style={styles.centered} testID="training-failed">
          <Text style={styles.bodyCenter}>{phase.message}</Text>
          {phase.retry !== null && (
            <Pressable accessibilityRole="button" testID="training-retry" onPress={game.retryFinish} style={({ pressed }) => [styles.startButton, pressed && styles.pressed]}>
              <Text style={styles.startText}>{S.retry}</Text>
            </Pressable>
          )}
          <Pressable accessibilityRole="button" testID="training-back" onPress={game.reset} style={({ pressed }) => [styles.secondaryButton, pressed && styles.pressed]}>
            <Text style={styles.secondaryText}>{S.result.again}</Text>
          </Pressable>
        </View>
      )}
    </View>
  );
}

function RunningView({
  session,
  taps,
  elapsedMs,
  finishing,
  premiumVideo,
  view,
  onPraise,
  onMediaExpired,
}: {
  session: TrainingSession;
  taps: readonly number[];
  elapsedMs: number;
  finishing: boolean;
  premiumVideo: boolean;
  view: ChildPetView;
  onPraise: () => void;
  onMediaExpired: () => void;
}) {
  const frame = frameAt(session, elapsedMs);
  const feedback = feedbackAt(session, taps, elapsedMs);
  const text = S.commands[session.command];
  const trialNo = frame.slot === null ? 0 : frame.slot + 1;
  const praiseDisabled = finishing || frame.over;
  const pressedInAt = useRef(Number.NEGATIVE_INFINITY);

  return (
    <View style={styles.running} testID="training-running">
      <View style={styles.rowBetween}>
        <Text style={styles.muted} testID="training-trial">
          {trialNo > 0 ? S.trialOf(trialNo, session.trials.length) : text.name}
        </Text>
        <Text style={styles.muted} testID="training-seconds">
          {S.secondsLeft(frame.secondsLeft)}
        </Text>
      </View>
      <TrialDots session={session} taps={taps} elapsedMs={elapsedMs} />

      <View style={styles.stage}>
        {premiumVideo ? (
          <PetMediaView
            media={view.pet.media}
            petState={frame.dog === 'obeying' ? 'playing' : 'idle'}
            breed={view.pet.breed_type}
            onMediaExpired={onMediaExpired}
            variant="card"
            style={styles.video}
            testID="training-dog-video"
          />
        ) : (
          <DogIllustration action={frame.dog} />
        )}
        {frame.cueVisible && (
          <View style={styles.cueBubble} testID="training-cue">
            <Text style={styles.cueText}>{text.cue}</Text>
          </View>
        )}
      </View>

      <Text style={styles.dogLine} testID="training-dog-text" accessibilityLiveRegion="polite">
        {dogLine(session, frame.dog)}
      </Text>
      <View style={styles.feedbackSlot}>
        {feedback !== null && (
          <Text style={[styles.feedback, GOOD.has(feedback.outcome) ? styles.feedbackGood : styles.feedbackTry]} testID="training-feedback" accessibilityLiveRegion="polite">
            {S.feedback[feedback.outcome]}
          </Text>
        )}
      </View>

      {finishing ? (
        <View style={styles.centeredSmall} testID="training-finishing">
          <ActivityIndicator color="#a5b4fc" />
          <Text style={styles.body}>{S.finishing}</Text>
        </View>
      ) : (
        <Pressable
          testID="training-praise"
          accessibilityRole="button"
          accessibilityLabel={S.praiseA11y}
          accessibilityState={{ disabled: praiseDisabled }}
          disabled={praiseDisabled}
          // The touch counts at once (timing game); a screen reader activates with onPress
          // only. The release of a touch never counts again (it could fall into the next cue).
          onPressIn={() => {
            pressedInAt.current = Date.now();
            onPraise();
          }}
          onPress={() => {
            if (Date.now() - pressedInAt.current > RELEASE_IGNORE_MS) onPraise();
          }}
          style={({ pressed }) => [styles.praiseButton, pressed && styles.praisePressed, praiseDisabled && styles.disabled]}
        >
          <Heart color="#ffffff" fill="#ffffff" size={28} />
          <Text style={styles.praiseText}>{S.praise}</Text>
        </Pressable>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  overlay: {
    ...StyleSheet.absoluteFill,
    zIndex: 50,
    backgroundColor: 'rgba(2, 6, 23, 0.96)',
    paddingTop: 56,
    paddingHorizontal: 16,
    paddingBottom: 28,
  },
  header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 },
  titleRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  title: { color: '#ffffff', fontSize: 22, fontWeight: '800' },
  closeButton: {
    width: 40,
    height: 40,
    borderRadius: 12,
    backgroundColor: 'rgba(255, 255, 255, 0.08)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  content: { gap: 12, paddingBottom: 24 },
  body: { color: '#e2e8f0', fontSize: 15 },
  bodyCenter: { color: '#e2e8f0', fontSize: 16, textAlign: 'center', lineHeight: 22 },
  bodyStrong: { color: '#ffffff', fontSize: 15, fontWeight: '700' },
  doneText: { color: '#6ee7b7' },
  muted: { color: '#94a3b8', fontSize: 13 },
  blocked: {
    color: '#fde68a',
    fontSize: 14,
    padding: 12,
    borderRadius: 14,
    backgroundColor: 'rgba(245, 158, 11, 0.12)',
    borderWidth: 1,
    borderColor: 'rgba(245, 158, 11, 0.3)',
  },
  commandCard: {
    gap: 10,
    padding: 14,
    borderRadius: 20,
    backgroundColor: 'rgba(15, 23, 42, 0.85)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.15)',
  },
  commandHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  commandName: { color: '#ffffff', fontSize: 18, fontWeight: '800' },
  percent: { color: '#cbd5e1', fontSize: 14, fontWeight: '700', fontVariant: ['tabular-nums'] },
  learnedPill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 12,
    backgroundColor: '#10b981',
  },
  learnedText: { color: '#ffffff', fontSize: 12, fontWeight: '800' },
  track: { height: 10, borderRadius: 5, backgroundColor: 'rgba(255, 255, 255, 0.1)', overflow: 'hidden' },
  fill: { height: '100%', borderRadius: 5, backgroundColor: '#6366f1' },
  fillLearned: { backgroundColor: '#10b981' },
  startButton: {
    minHeight: 48,
    paddingHorizontal: 20,
    borderRadius: 16,
    backgroundColor: '#4f46e5',
    alignItems: 'center',
    justifyContent: 'center',
  },
  flexButton: { flex: 1 },
  startText: { color: '#ffffff', fontSize: 16, fontWeight: '800' },
  secondaryButton: {
    minHeight: 48,
    paddingHorizontal: 18,
    borderRadius: 16,
    backgroundColor: 'rgba(255, 255, 255, 0.08)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.2)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  secondaryText: { color: '#e2e8f0', fontSize: 15, fontWeight: '700' },
  disabled: { opacity: 0.45 },
  pressed: { opacity: 0.75 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 16, paddingHorizontal: 12 },
  centeredSmall: { alignItems: 'center', gap: 8, minHeight: 96, justifyContent: 'center' },
  running: { flex: 1, gap: 12 },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  row: { flexDirection: 'row', gap: 10, alignItems: 'center' },
  dots: { flexDirection: 'row', gap: 8, justifyContent: 'center' },
  dot: { width: 14, height: 14, borderRadius: 7 },
  dotOpen: { backgroundColor: 'rgba(255, 255, 255, 0.18)' },
  dotGood: { backgroundColor: '#10b981' },
  dotTry: { backgroundColor: '#f59e0b' },
  stage: {
    flex: 1,
    minHeight: 180,
    borderRadius: 24,
    overflow: 'hidden',
    backgroundColor: 'rgba(15, 23, 42, 0.85)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.12)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  video: { ...StyleSheet.absoluteFill },
  dogStage: { alignItems: 'center', justifyContent: 'center' },
  dogCircle: {
    width: 140,
    height: 140,
    borderRadius: 70,
    backgroundColor: 'rgba(255, 255, 255, 0.08)',
    borderWidth: 2,
    borderColor: 'rgba(255, 255, 255, 0.2)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  dogCircleObeying: { borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.15)' },
  dogEmoji: { fontSize: 72 },
  cueBubble: {
    position: 'absolute',
    top: 14,
    alignSelf: 'center',
    paddingHorizontal: 18,
    paddingVertical: 8,
    borderRadius: 18,
    backgroundColor: '#4f46e5',
  },
  cueText: { color: '#ffffff', fontSize: 22, fontWeight: '900' },
  dogLine: { color: '#ffffff', fontSize: 17, fontWeight: '700', textAlign: 'center' },
  feedbackSlot: { minHeight: 44, justifyContent: 'center' },
  feedback: { fontSize: 15, fontWeight: '700', textAlign: 'center' },
  feedbackGood: { color: '#6ee7b7' },
  feedbackTry: { color: '#fcd34d' },
  praiseButton: {
    minHeight: 96,
    borderRadius: 28,
    backgroundColor: '#10b981',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 12,
    shadowColor: '#10b981',
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.5,
    shadowRadius: 14,
  },
  praisePressed: { transform: [{ scale: 0.97 }], opacity: 0.9 },
  praiseText: { color: '#ffffff', fontSize: 26, fontWeight: '900' },
  resultBox: { gap: 12 },
  resultTitle: { color: '#ffffff', fontSize: 28, fontWeight: '900' },
  learnedLine: { color: '#6ee7b7', fontSize: 17, fontWeight: '800' },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  chip: { paddingHorizontal: 10, paddingVertical: 5, borderRadius: 12 },
  chipGood: { backgroundColor: 'rgba(16, 185, 129, 0.2)' },
  chipTry: { backgroundColor: 'rgba(245, 158, 11, 0.18)' },
  chipText: { color: '#e2e8f0', fontSize: 12, fontWeight: '600' },
});
