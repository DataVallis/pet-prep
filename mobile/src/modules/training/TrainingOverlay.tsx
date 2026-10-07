/**
 * "Šola" — the training screen over the child HUD (M5-R03, PRODUCT_SPEC §5; dark glass
 * HUD style, ADR-007).
 *
 * 1. Pick a command: progress bar per command ("naučeno ✓" at 100 %), today's routine,
 *    sessions left today for THIS child (`my_seconds_left`, M5-R03b; a "deliš" hint when
 *    siblings share the time). "Začni vajo" follows the server's `can_start`; when it is off
 *    the reason is explained (a sibling is training, the daily budget is used …).
 * 2. The 50 s game: the cue ("Sedi!"), the dog's reaction from the server's schedule
 *    (free tier: an animated illustration + a line of text; premium: the pet's own
 *    idle / playing video — no new media), a big "Pohvali" button and an immediate,
 *    kind verdict per cue (bravo / prezgodaj / prepozno / počakal(a) si).
 * 3. The server's result: progress gain, "Kuža zna …" at 100 %, never shaming. M5-F04: the
 *    title follows the outcome (`trainingResultBucket`: Odlično / Dobro / Še malo vaje /
 *    Tokrat ni šlo) — never false praise; a repeated finish keeps the neutral "already saved".
 * The overlay can't be closed while a session runs or is being saved.
 */

import { useEffect, useMemo, useRef } from 'react';
import { ActivityIndicator, Animated, Pressable, ScrollView, StyleSheet, View, type GestureResponderEvent } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Check, GraduationCap, Heart, X } from 'lucide-react-native';
import { useQueryClient } from '@tanstack/react-query';

import PetMediaView from '@/components/PetMediaView';
import { childPetKey } from '@/hooks/queries/useChildPet';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { selectMediaSource } from '@/modules/petMedia/petMedia';
import {
  feedbackAt,
  firstTapsBySlot,
  frameAt,
  liveOutcome,
  missedWhileAway,
  type ClockSources,
  type DogAction,
} from '@/modules/training/game';
import { familyCalendar } from '@/modules/childPet/familyTime';
import {
  isDayEnding,
  isTimeShared,
  sessionsLeftToday,
  startBlock,
  trainingResultBucket,
  TRAINING_STRINGS,
  type CommandProgress,
  type TrainingResult,
  type TrainingResultBucket,
  type TrainingSession,
  type TrialOutcome,
} from '@/modules/training/training';
import { useTrainingGame } from '@/modules/training/useTrainingGame';
import { alpha, fonts, palette, radius, tightTracking } from '@/theme';

const S = TRAINING_STRINGS;

/** Good verdicts are mint / green; the others a calm yellow — never the danger colour. */
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
            <Check color={palette.graphite} size={12} />
            <Text style={styles.learnedText}>{S.learned}</Text>
          </View>
        ) : (
          <Text style={styles.percent}>{S.percent(item.progress)}</Text>
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

function TrialDots({
  session,
  taps,
  elapsedMs,
  resumedAtMs,
}: {
  session: TrainingSession;
  taps: readonly number[];
  elapsedMs: number;
  resumedAtMs: number | null;
}) {
  const firsts = firstTapsBySlot(session.trials, taps);
  return (
    <View style={styles.dots}>
      {session.trials.map((trial, i) => {
        // Cues that passed while the app was closed are neutral — the child couldn't play them.
        if (missedWhileAway(session, i, resumedAtMs)) {
          return <View key={trial.index} style={[styles.dot, styles.dotAway]} testID={`training-dot-away-${i}`} />;
        }
        const outcome = liveOutcome(session, i, firsts.get(i) ?? null, elapsedMs);
        const style = outcome === null ? styles.dotOpen : GOOD.has(outcome) ? styles.dotGood : styles.dotTry;
        return <View key={trial.index} style={[styles.dot, style]} testID={`training-dot-${i}`} />;
      })}
    </View>
  );
}

/** Result title per outcome (M5-F04), read at render (follows a language switch). */
export function resultTitle(bucket: TrainingResultBucket): string {
  switch (bucket) {
    case 'excellent':
      return S.result.titleExcellent;
    case 'good':
      return S.result.titleGood;
    case 'practice':
      return S.result.titlePractice;
    case 'none':
      return S.result.titleNone;
  }
}

function ResultView({
  result,
  status,
  onDone,
  onAgain,
  canStartAgain,
}: {
  result: TrainingResult;
  /** `unchanged` = the server returned a result it had saved before (a repeated finish). */
  status: 'accepted' | 'unchanged';
  onDone: () => void;
  onAgain: () => void;
  canStartAgain: boolean;
}) {
  const name = S.commands[result.command].name;
  const bucket = trainingResultBucket(result);
  const stored = status === 'unchanged';
  const learned = result.progress_after >= 100;
  let gainLine: string;
  if (result.progress_after > result.progress_before) gainLine = S.result.gain(name, result.progress_before, result.progress_after);
  else if (result.progress_gain > 0) gainLine = S.result.gainSmall(name, result.progress_after);
  else gainLine = S.result.noGain;
  return (
    <View style={styles.resultBox} testID="training-result">
      <Text style={styles.resultTitle} testID="training-result-title" accessibilityRole="header">
        {stored ? S.result.titleStored : resultTitle(bucket)}
      </Text>
      {stored && <Text style={styles.muted}>{S.result.stored}</Text>}
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
      {!stored && (
        <Text style={styles.muted} testID="training-result-routine">
          {S.result.routineDone}
        </Text>
      )}
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
  const ownSchedule = training.session?.mine === true ? training.session.schedule : null;
  const game = useTrainingGame({ clockSkewMs: view.clockSkewMs, timezone: view.timezone, clock, resume: ownSchedule });
  const { phase, elapsedMs } = game;
  const busy = phase.kind === 'running' || phase.kind === 'finishing' || phase.kind === 'starting';
  const premiumVideo = useMemo(() => selectMediaSource(view.pet.media, 'idle').kind === 'video', [view.pet.media]);
  const serverNow = (clock?.wall() ?? Date.now()) + view.clockSkewMs;
  const dayEnding = isDayEnding(training, serverNow, familyCalendar(view.timezone, view.server_time).nextMidnight(serverNow));
  const block = startBlock(training, dayEnding);
  const left = sessionsLeftToday(training);

  return (
    <View style={styles.overlay} testID={testID} accessibilityViewIsModal>
      <View style={styles.header}>
        <View style={styles.titleRow}>
          <GraduationCap color={palette.mint} size={22} />
          <Text style={styles.title}>{S.title}</Text>
        </View>
        {!busy && (
          <Pressable accessibilityRole="button" accessibilityLabel={S.close} testID="training-close" onPress={onClose} hitSlop={10} style={({ pressed }) => [styles.closeButton, pressed && styles.pressed]}>
            <X color={palette.n300} size={20} />
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
          {isTimeShared(training) && (
            <Text style={styles.muted} testID="training-shared">
              {S.sharedTime(training.children_sharing)}
            </Text>
          )}
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
          <ActivityIndicator color={palette.mint} />
          <Text style={styles.body}>{S.starting}</Text>
        </View>
      )}

      {(phase.kind === 'running' || phase.kind === 'finishing') && (
        <RunningView
          session={phase.session}
          taps={phase.taps}
          elapsedMs={elapsedMs}
          finishing={phase.kind === 'finishing'}
          resumedAtMs={phase.resumedAtMs}
          premiumVideo={premiumVideo}
          view={view}
          onPraise={game.praise}
          onMediaExpired={() => void queryClient.invalidateQueries({ queryKey: childPetKey })}
        />
      )}

      {phase.kind === 'result' && (
        <ScrollView contentContainerStyle={styles.content}>
          <ResultView result={phase.result} status={phase.status} onDone={onClose} onAgain={game.reset} canStartAgain={left > 0} />
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
          {/* While the session can still be saved, leaving would throw it away — only "Poskusi znova". */}
          {phase.retry === null && (
            <Pressable accessibilityRole="button" testID="training-back" onPress={game.reset} style={({ pressed }) => [styles.secondaryButton, pressed && styles.pressed]}>
              <Text style={styles.secondaryText}>{S.result.again}</Text>
            </Pressable>
          )}
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
  resumedAtMs,
  premiumVideo,
  view,
  onPraise,
  onMediaExpired,
}: {
  session: TrainingSession;
  taps: readonly number[];
  elapsedMs: number;
  finishing: boolean;
  /** ms into the session when it was resumed after an app restart (null = played from the start). */
  resumedAtMs: number | null;
  premiumVideo: boolean;
  view: ChildPetView;
  onPraise: (touchTimestamp?: number | null) => void;
  onMediaExpired: () => void;
}) {
  const frame = frameAt(session, elapsedMs);
  const feedback = feedbackAt(session, taps, elapsedMs, resumedAtMs);
  const text = S.commands[session.command];
  const trialNo = frame.slot === null ? 0 : frame.slot + 1;
  const praiseDisabled = finishing || frame.over;
  /** A touch is down: its release (onPress) is the same tap, however long it was held. */
  const touchActive = useRef(false);

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
      <TrialDots session={session} taps={taps} elapsedMs={elapsedMs} resumedAtMs={resumedAtMs} />
      {resumedAtMs !== null && (
        <Text style={styles.muted} testID="training-resumed">
          {S.resumed}
        </Text>
      )}

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
          <ActivityIndicator color={palette.mint} />
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
          onPressIn={(e: GestureResponderEvent) => {
            touchActive.current = true;
            // When the finger touched the glass (mapped onto the game clock), not when JS ran.
            onPraise(e?.nativeEvent?.timestamp ?? null);
          }}
          onPress={() => {
            if (touchActive.current) {
              touchActive.current = false;
              return;
            }
            onPraise(null);
          }}
          style={({ pressed }) => [styles.praiseButton, pressed && styles.praisePressed, praiseDisabled && styles.disabled]}
        >
          <Heart color={palette.graphite} fill={palette.raspberry} size={28} />
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
    backgroundColor: alpha(palette.graphite, 0.96),
    paddingTop: 56,
    paddingHorizontal: 16,
    paddingBottom: 28,
  },
  header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 },
  titleRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  title: { color: palette.white, fontSize: 22, letterSpacing: tightTracking(22), fontFamily: fonts.display },
  closeButton: {
    width: 40,
    height: 40,
    borderRadius: 12,
    backgroundColor: alpha(palette.white, 0.08),
    alignItems: 'center',
    justifyContent: 'center',
  },
  content: { gap: 12, paddingBottom: 24 },
  body: { color: palette.n200, fontSize: 15 },
  bodyCenter: { color: palette.n200, fontSize: 16, textAlign: 'center', lineHeight: 22 },
  bodyStrong: { color: palette.white, fontSize: 15, fontWeight: '700' },
  doneText: { color: palette.mint },
  muted: { color: palette.n400, fontSize: 13 },
  blocked: {
    color: palette.warnDark,
    fontSize: 14,
    padding: 12,
    borderRadius: 14,
    backgroundColor: alpha(palette.warnDark, 0.12),
    borderWidth: 1,
    borderColor: alpha(palette.warnDark, 0.3),
  },
  commandCard: {
    gap: 10,
    padding: 14,
    borderRadius: 20,
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
  },
  commandHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  commandName: { color: palette.white, fontSize: 18, letterSpacing: tightTracking(18), fontFamily: fonts.displayBold },
  percent: { color: palette.n300, fontSize: 14, fontWeight: '700', fontVariant: ['tabular-nums'] },
  learnedPill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 12,
    backgroundColor: palette.okDark,
  },
  learnedText: { color: palette.graphite, fontSize: 12, fontWeight: '800' },
  track: { height: 10, borderRadius: 5, backgroundColor: alpha(palette.white, 0.1), overflow: 'hidden' },
  fill: { height: '100%', borderRadius: 5, backgroundColor: palette.mint },
  fillLearned: { backgroundColor: palette.okDark },
  startButton: {
    minHeight: 48,
    paddingHorizontal: 20,
    borderRadius: radius.button,
    backgroundColor: palette.mint,
    alignItems: 'center',
    justifyContent: 'center',
  },
  flexButton: { flex: 1 },
  startText: { color: palette.graphite, fontSize: 16, fontWeight: '800' },
  secondaryButton: {
    minHeight: 48,
    paddingHorizontal: 18,
    borderRadius: radius.button,
    backgroundColor: alpha(palette.white, 0.08),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.2),
    alignItems: 'center',
    justifyContent: 'center',
  },
  secondaryText: { color: palette.n200, fontSize: 15, fontWeight: '700' },
  disabled: { opacity: 0.45 },
  pressed: { opacity: 0.75 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 16, paddingHorizontal: 12 },
  centeredSmall: { alignItems: 'center', gap: 8, minHeight: 96, justifyContent: 'center' },
  running: { flex: 1, gap: 12 },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  row: { flexDirection: 'row', gap: 10, alignItems: 'center' },
  dots: { flexDirection: 'row', gap: 8, justifyContent: 'center' },
  dot: { width: 14, height: 14, borderRadius: 7 },
  dotOpen: { backgroundColor: alpha(palette.white, 0.18) },
  dotGood: { backgroundColor: palette.okDark },
  dotTry: { backgroundColor: palette.warnDark },
  dotAway: { backgroundColor: 'transparent', borderWidth: 1, borderColor: alpha(palette.white, 0.25) },
  stage: {
    flex: 1,
    minHeight: 180,
    borderRadius: 24,
    overflow: 'hidden',
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.12),
    alignItems: 'center',
    justifyContent: 'center',
  },
  video: { ...StyleSheet.absoluteFill },
  dogStage: { alignItems: 'center', justifyContent: 'center' },
  dogCircle: {
    width: 140,
    height: 140,
    borderRadius: 70,
    backgroundColor: alpha(palette.white, 0.08),
    borderWidth: 2,
    borderColor: alpha(palette.white, 0.2),
    alignItems: 'center',
    justifyContent: 'center',
  },
  dogCircleObeying: { borderColor: palette.okDark, backgroundColor: alpha(palette.okDark, 0.15) },
  dogEmoji: { fontSize: 72 },
  cueBubble: {
    position: 'absolute',
    top: 14,
    alignSelf: 'center',
    paddingHorizontal: 18,
    paddingVertical: 8,
    borderRadius: 18,
    backgroundColor: palette.mint,
  },
  cueText: { color: palette.graphite, fontSize: 22, letterSpacing: tightTracking(22), fontFamily: fonts.display },
  dogLine: { color: palette.white, fontSize: 17, fontWeight: '700', textAlign: 'center' },
  feedbackSlot: { minHeight: 44, justifyContent: 'center' },
  feedback: { fontSize: 15, fontWeight: '700', textAlign: 'center' },
  feedbackGood: { color: palette.mint },
  feedbackTry: { color: palette.warnDark },
  praiseButton: {
    minHeight: 96,
    borderRadius: 28,
    backgroundColor: palette.mint,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 12,
    shadowColor: palette.mint,
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.5,
    shadowRadius: 14,
  },
  praisePressed: { transform: [{ scale: 0.97 }], opacity: 0.9 },
  praiseText: { color: palette.graphite, fontSize: 26, letterSpacing: tightTracking(26), fontFamily: fonts.display },
  resultBox: { gap: 12 },
  resultTitle: { color: palette.white, fontSize: 28, letterSpacing: tightTracking(28), fontFamily: fonts.display },
  learnedLine: { color: palette.mint, fontSize: 17, letterSpacing: tightTracking(17), fontFamily: fonts.displayBold },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  chip: { paddingHorizontal: 10, paddingVertical: 5, borderRadius: 12 },
  chipGood: { backgroundColor: alpha(palette.okDark, 0.2) },
  chipTry: { backgroundColor: alpha(palette.warnDark, 0.18) },
  chipText: { color: palette.n200, fontSize: 12, fontWeight: '600' },
});
