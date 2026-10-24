/**
 * The breed care mini-games (M5-R14 / R15 / R19 / R20, David 2026-10-24) — one overlay, the
 * task picks texts and the stage:
 *
 * - »Umij zobke« / »Obriši gubice« / »Očisti ušesa«: the brush scratch card of the grooming
 *   (M5-T14 `BrushArea`) — 30 s, ≥ 10 strokes over three steps, the same server check; a
 *   clean card finishes up to 2 s early.
 * - »Pristriži kremplje«: tap each claw of the two front paws once (8 taps, 12 s, one step);
 *   finished up to 2 s early once every claw is trimmed.
 *
 * Same server-led session flow as the cat games (`useCatSessionGame`: start → the server's
 * schedule, finish → its verdict, resume after a restart, stop without consequence). M5-T17:
 * the hold alternative (hold on the stage / the button) only in hold mode (the parent's
 * »Lažje kretnje« or a running screen reader); claws are plain buttons, so a screen reader
 * activates them one by one; reduce motion = no sparkles / fades. A »didn't count« is never
 * shaming and has no penalty.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Droplets, Ear, Scissors, Sparkles } from 'lucide-react-native';
import { useQueryClient } from '@tanstack/react-query';

import { Text } from '@/components/ui/Text';
import { childPetKey } from '@/hooks/queries/useChildPet';
import { useFinishCareTask, useStartCareTask } from '@/hooks/queries/useCareTasks';
import { useReduceMotion } from '@/hooks/useReduceMotion';
import { t } from '@/i18n';
import {
  CARE_TASK_STRINGS,
  careTaskPeriodLine,
  careTaskStep,
  careTaskText,
  careTaskTitle,
  isBrushTask,
  type CareTaskKey,
  type CareTaskResult,
  type CareTaskSession,
} from '@/modules/careTasks/careTasks';
import { clawCount, initialNails, nailsFinishAt, nextClaw, trimClaw, type NailsState } from '@/modules/careTasks/nails';
import { BrushArea, type BrushTexts } from '@/modules/catCare/BrushArea';
import { brushFinishAt, holdGap } from '@/modules/catCare/brushing';
import { catRefusalMessage, onlyScratchingOpen, type CatFinishStatus, type RefusalContext } from '@/modules/catCare/catCare';
import { addStroke, canChoreStillCount, choreProgress, choreStepAt, sessionTime } from '@/modules/catCare/catGames';
import { Busy, CatOverlayFrame, CatStage, FailedView, PrimaryButton, SecondaryButton, SegmentDots, styles, useAnnounce } from '@/modules/catCare/CatGameParts';
import { useCatSessionGame, type CatSessionGame } from '@/modules/catCare/useCatSessionGame';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { useGestureMode, type GestureMode } from '@/modules/gestures/easyGestures';
import type { ClockSources } from '@/modules/training/game';
import { useAppStore } from '@/store/appStore';
import { MIN_TOUCH, alpha, palette, radius } from '@/theme';

const C = CARE_TASK_STRINGS;
const NO_STROKES: readonly number[] = [];
const ICONS = { tooth_brushing: Sparkles, fold_cleaning: Droplets, ear_cleaning: Ear, nail_trim: Scissors } as const;
const FIGURE: Record<CareTaskKey, { start: string; done: string }> = {
  tooth_brushing: { start: '🐕', done: '😁' },
  fold_cleaning: { start: '🐕', done: '🐶' },
  ear_cleaning: { start: '🐕', done: '🐶' },
  nail_trim: { start: '🐾', done: '🐾' },
};

export type CareTaskGame = CatSessionGame<CareTaskSession, readonly number[], CareTaskResult>;

export function useCareTaskGame(task: CareTaskKey, view: ChildPetView, { clock }: { clock?: ClockSources } = {}): CareTaskGame {
  const { mutate: startMutate } = useStartCareTask(task);
  const { mutate: finish } = useFinishCareTask(task);
  const finishMutate = useCallback(
    (v: { session: CareTaskSession; input: readonly number[] }, callbacks: Parameters<typeof finish>[1]) =>
      finish({ sessionId: v.session.id, strokes: v.input }, callbacks),
    [finish],
  );
  const refusal: RefusalContext = useMemo(
    () => ({ nowIso: view.server_time, timezone: view.timezone, scratchingOnly: onlyScratchingOpen(view.cat), dog: true }),
    [view],
  );
  const abandoned = useAppStore((s) => s.abandonedCatSessions);
  const onAbandon = useAppStore((s) => s.abandonCatSession);
  const resume = view.careTasks.find((c) => c.task === task)?.session ?? null;
  return useCatSessionGame<CareTaskSession, readonly number[], CareTaskResult>({
    clockSkewMs: view.clockSkewMs,
    refusal,
    clock,
    resume,
    abandoned,
    onAbandon,
    emptyInput: NO_STROKES,
    finishAtMs: isBrushTask(task) ? brushFinishAt : nailsFinishAt,
    canStillCount: canChoreStillCount,
    startMutate,
    finishMutate,
  });
}

export interface CareTaskOverlayProps {
  task: CareTaskKey;
  view: ChildPetView;
  onClose: () => void;
  reduceMotion?: boolean;
  clock?: ClockSources;
}

/** Host: the task of `appStore.careTaskOverlay`, or nothing (a pet without it never keeps it open). */
export function CareTaskOverlayHost({ view, clock, reduceMotion }: { view: ChildPetView; clock?: ClockSources; reduceMotion?: boolean }) {
  const task = useAppStore((s) => s.careTaskOverlay);
  const close = useAppStore((s) => s.closeCareTask);
  const systemReduceMotion = useReduceMotion();
  const available = task !== null && view.careTasks.some((c) => c.task === task);
  useEffect(() => {
    if (task !== null && !available) close();
  }, [available, close, task]);
  if (task === null || !available) return null;
  return <CareTaskOverlay key={task} task={task} view={view} onClose={close} reduceMotion={reduceMotion ?? systemReduceMotion} clock={clock} />;
}

export default function CareTaskOverlay({ task, view, onClose, reduceMotion = false, clock }: CareTaskOverlayProps) {
  const queryClient = useQueryClient();
  const game = useCareTaskGame(task, view, { clock });
  const gestureMode = useGestureMode(view);
  const { phase } = game;
  const testID = `care-${task}`;
  const busy = phase.kind === 'starting' || phase.kind === 'finishing';
  const entry = view.careTasks.find((c) => c.task === task) ?? null;
  const Icon = ICONS[task];
  const onMediaExpired = () => void queryClient.invalidateQueries({ queryKey: childPetKey });
  const blocked = entry !== null && !entry.can_start ? entry.blocked_reason : null;
  const ctx: RefusalContext = { nowIso: view.server_time, timezone: view.timezone, scratchingOnly: onlyScratchingOpen(view.cat), dog: true };

  return (
    <CatOverlayFrame
      title={careTaskTitle(task)}
      icon={<Icon color={palette.mint} size={22} />}
      onClose={busy ? null : phase.kind === 'running' ? game.stop : onClose}
      closeMode={phase.kind === 'running' ? 'stop' : 'close'}
      testID={testID}
    >
      {phase.kind === 'intro' && (
        <ScrollView contentContainerStyle={styles.content} testID={`${testID}-intro`}>
          <Text style={styles.body}>{careTaskText(task, 'intro')}</Text>
          {entry !== null && (
            <Text style={[styles.bodyStrong, entry.done_this_period >= entry.times && styles.doneText]} testID={`${testID}-period`}>
              {careTaskPeriodLine(entry)}
            </Text>
          )}
          {blocked !== null && entry !== null && (
            <Text style={styles.note} testID={`${testID}-blocked`}>
              {catRefusalMessage(blocked, entry.next_allowed_at, ctx)}
            </Text>
          )}
          <PrimaryButton label={careTaskText(task, 'start')} onPress={game.start} disabled={entry === null || !entry.can_start} testID={`${testID}-start`} />
        </ScrollView>
      )}

      {phase.kind === 'starting' && <Busy text={C.starting} testID={`${testID}-starting`} />}

      {(phase.kind === 'running' || phase.kind === 'finishing') && (
        <CareTaskRunning
          task={task}
          view={view}
          session={phase.session}
          strokes={phase.input}
          elapsedMs={game.elapsedMs}
          finishing={phase.kind === 'finishing'}
          resumed={phase.resumedAtMs !== null}
          reduceMotion={reduceMotion}
          gestureMode={gestureMode}
          msAt={game.msAt}
          onStroke={(ms) => game.setInput((strokes) => addStroke(strokes, ms, phase.session.duration_ms))}
          onMediaExpired={onMediaExpired}
          testID={testID}
        />
      )}

      {phase.kind === 'result' && (
        <ScrollView contentContainerStyle={styles.content}>
          <CareTaskResultView task={task} result={phase.result} status={phase.status} onAgain={game.reset} onDone={onClose} testID={testID} />
        </ScrollView>
      )}

      {phase.kind === 'failed' && (
        <FailedView message={phase.message} canRetry={phase.retry !== null} onRetry={game.retryFinish} onBack={game.reset} testID={`${testID}-failed`} />
      )}
    </CatOverlayFrame>
  );
}

function CareTaskResultView({
  task,
  result,
  status,
  onAgain,
  onDone,
  testID,
}: {
  task: CareTaskKey;
  result: CareTaskResult;
  status: CatFinishStatus;
  onAgain: () => void;
  onDone: () => void;
  testID: string;
}) {
  return (
    <View style={styles.resultBox} testID={`${testID}-result`}>
      <Text style={styles.resultTitle} accessibilityRole="header" testID={`${testID}-result-title`}>
        {result.success ? careTaskText(task, 'successTitle') : careTaskText(task, 'failTitle')}
      </Text>
      {status === 'unchanged' && <Text style={styles.muted}>{C.stored}</Text>}
      <Text style={styles.body} testID={`${testID}-result-text`}>
        {result.success ? careTaskText(task, 'success') : C.reasons[result.reason ?? 'unknown']}
      </Text>
      <Text style={styles.muted}>{C.count(result.strokes)}</Text>
      {!result.success && <Text style={styles.muted}>{C.noPenalty}</Text>}
      <View style={styles.row}>
        {!result.success && <SecondaryButton label={C.again} onPress={onAgain} testID={`${testID}-again`} />}
        <View style={styles.flexButton}>
          <PrimaryButton label={C.done} onPress={onDone} testID={`${testID}-done`} />
        </View>
      </View>
    </View>
  );
}

function brushTexts(): BrushTexts {
  return {
    holdHint: C.brush.holdHint,
    swipeHint: C.brush.swipeHint,
    clean: C.brush.clean,
    action: C.brush.action,
    holdA11yHint: C.brush.holdA11yHint,
    bar: (percent: number) => t('careTasks:common.brush.bar', { percent }),
  };
}

function CareTaskRunning({
  task,
  view,
  session,
  strokes,
  elapsedMs,
  finishing,
  resumed,
  reduceMotion,
  gestureMode,
  msAt,
  onStroke,
  onMediaExpired,
  testID,
}: {
  task: CareTaskKey;
  view: ChildPetView;
  session: CareTaskSession;
  strokes: readonly number[];
  elapsedMs: number;
  finishing: boolean;
  resumed: boolean;
  reduceMotion: boolean;
  gestureMode: GestureMode;
  msAt: (touchTimestamp?: number | null) => number;
  onStroke: (ms: number) => void;
  onMediaExpired: () => void;
  testID: string;
}) {
  const time = sessionTime(session.duration_ms, elapsedMs);
  const step = choreStepAt(session, elapsedMs);
  const progress = choreProgress(session, strokes);
  const brush = isBrushTask(task);
  const stepText = brush ? careTaskStep(task, step) : gestureMode === 'hold' ? C.nails.holdHint : C.nails.hint;
  useAnnounce(stepText);
  const disabled = finishing || time.over;
  const done = progress.strokes >= session.min_strokes;
  const illustration = (
    <Text style={local.figure} accessible={false}>
      {done ? FIGURE[task].done : FIGURE[task].start}
    </Text>
  );

  return (
    <View style={styles.running} testID={`${testID}-running`}>
      <View style={styles.rowBetween}>
        <SegmentDots total={Math.max(1, session.segments)} hit={progress.segmentsHit} current={time.over ? null : step - 1} label={C.stepA11y} testID={`${testID}-steps`} />
        <Text style={styles.muted} testID={`${testID}-seconds`}>
          {t('cat:common.secondsLeft', { seconds: time.secondsLeft })}
        </Text>
      </View>
      {resumed && <Text style={styles.muted}>{t('cat:common.resumed')}</Text>}
      {brush ? (
        <BrushArea
          rules={session}
          texts={brushTexts()}
          stageA11y={careTaskText(task, 'stageA11y')}
          strokeLabel={careTaskText(task, 'stroke')}
          strokeA11y={careTaskText(task, 'strokeA11y')}
          disabled={disabled}
          hideButton={finishing}
          reduceMotion={reduceMotion}
          gestureMode={gestureMode}
          msAt={msAt}
          onStroke={onStroke}
          renderStage={(layers) => (
            <CatStage view={view} petState="idle" illustration={illustration} onMediaExpired={onMediaExpired} testID={`${testID}-stage`}>
              {layers}
            </CatStage>
          )}
          testID={testID}
        />
      ) : (
        <ClawsStage session={session} disabled={disabled} gestureMode={gestureMode} msAt={msAt} onStroke={onStroke} testID={testID} />
      )}
      <Text style={styles.statusLine} testID={`${testID}-step`} accessibilityLiveRegion="polite">
        {done && !brush ? C.nails.allDone : stepText}
      </Text>
      <Text style={[styles.bodyCenter, done && styles.doneText]} testID={`${testID}-progress`}>
        {C.progress(Math.min(progress.strokes, session.min_strokes), session.min_strokes)}
      </Text>
      {finishing && <Busy text={C.finishing} testID={`${testID}-finishing`} />}
    </View>
  );
}

/**
 * Two front paws, `min_strokes / 2` claws each. A tap trims a claw (once); in hold mode the
 * »Pristriži naslednji kremplj« button trims one claw after another while held (irregular
 * `HOLD_GAPS_MS`, like the brushing) — the same taps the server scores.
 */
export function ClawsStage({
  session,
  disabled,
  gestureMode,
  msAt,
  onStroke,
  testID,
}: {
  session: CareTaskSession;
  disabled: boolean;
  gestureMode: GestureMode;
  msAt: (touchTimestamp?: number | null) => number;
  onStroke: (ms: number) => void;
  testID: string;
}) {
  const [nails, setNails] = useState<NailsState>(() => initialNails(session));
  const nailsRef = useRef(nails);
  const holdTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const holdCount = useRef(0);
  const total = clawCount(session);
  const perPaw = Math.ceil(total / 2);

  const trim = useCallback(
    (index: number, timestamp?: number | null) => {
      const next = trimClaw(nailsRef.current, index, msAt(timestamp), session);
      if (next.stroke === null) return false;
      nailsRef.current = next.state;
      setNails(next.state);
      onStroke(next.stroke);
      return true;
    },
    [msAt, onStroke, session],
  );

  const stopHold = useCallback(() => {
    if (holdTimer.current !== null) clearTimeout(holdTimer.current);
    holdTimer.current = null;
  }, []);
  useEffect(() => stopHold, [stopHold]);
  useEffect(() => {
    if (disabled) stopHold();
  }, [disabled, stopHold]);

  const holdStep = useCallback(() => {
    const index = nextClaw(nailsRef.current);
    if (index === -1) {
      stopHold();
      return;
    }
    trim(index);
    holdCount.current += 1;
    holdTimer.current = setTimeout(holdStep, holdGap(holdCount.current));
  }, [stopHold, trim]);

  const paw = (from: number, label: string) => (
    <View style={local.paw} accessibilityLabel={label} testID={`${testID}-paw-${from === 0 ? 'left' : 'right'}`}>
      <Text style={local.pawLabel}>{label}</Text>
      <View style={local.claws}>
        {Array.from({ length: Math.min(perPaw, total - from) }, (_, i) => {
          const index = from + i;
          const isDone = nails.trimmed[index] !== null;
          return (
            <Pressable
              key={index}
              testID={`${testID}-claw-${index}`}
              accessibilityRole="button"
              accessibilityLabel={t(isDone ? 'careTasks:common.nails.clawDoneA11y' : 'careTasks:common.nails.clawA11y', { n: index + 1, total })}
              accessibilityState={{ disabled: disabled || isDone, selected: isDone }}
              disabled={disabled || isDone}
              onPress={(e) => void trim(index, e?.nativeEvent?.timestamp)}
              hitSlop={4}
              style={({ pressed }) => [local.claw, isDone && local.clawDone, pressed && local.pressed]}
            >
              <Text style={[local.clawMark, isDone && local.clawMarkDone]}>{isDone ? '✓' : '▲'}</Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );

  return (
    <View style={local.stage} testID={`${testID}-claws`}>
      <View style={local.paws}>
        {paw(0, C.nails.leftPaw)}
        {paw(perPaw, C.nails.rightPaw)}
      </View>
      {gestureMode === 'hold' && (
        <Pressable
          testID={`${testID}-hold`}
          accessibilityRole="button"
          accessibilityLabel={C.nails.next}
          accessibilityHint={C.nails.nextA11yHint}
          accessibilityState={{ disabled: disabled || nextClaw(nails) === -1 }}
          disabled={disabled || nextClaw(nails) === -1}
          onPressIn={() => {
            stopHold();
            holdCount.current = 0;
            holdStep();
          }}
          onPressOut={stopHold}
          style={({ pressed }) => [local.holdButton, pressed && local.pressed]}
        >
          <Scissors color={palette.graphite} size={18} />
          <Text style={local.holdText}>{C.nails.next}</Text>
        </Pressable>
      )}
    </View>
  );
}

const local = StyleSheet.create({
  figure: { fontSize: 96, textAlign: 'center' },
  stage: { gap: 16, paddingVertical: 12, alignItems: 'center' },
  paws: { flexDirection: 'row', gap: 16, justifyContent: 'center', flexWrap: 'wrap' },
  paw: {
    alignItems: 'center',
    gap: 8,
    padding: 12,
    borderRadius: radius.card,
    backgroundColor: alpha(palette.white, 0.06),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.25),
  },
  pawLabel: { color: palette.n200, fontSize: 12, fontWeight: '700' },
  claws: { flexDirection: 'row', gap: 8 },
  claw: {
    minWidth: MIN_TOUCH,
    minHeight: MIN_TOUCH,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: radius.button,
    backgroundColor: alpha(palette.white, 0.12),
  },
  clawDone: { backgroundColor: alpha(palette.mint, 0.25) },
  clawMark: { color: palette.white, fontSize: 18, fontWeight: '800' },
  clawMarkDone: { color: palette.mint },
  holdButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    minHeight: MIN_TOUCH,
    paddingHorizontal: 18,
    borderRadius: radius.card,
    backgroundColor: palette.mint,
  },
  holdText: { color: palette.graphite, fontSize: 15, fontWeight: '800' },
  pressed: { opacity: 0.75 },
});
