/**
 * The stroke mini-games (M5-R06-08a): "Počeši muco" (brushed-cat grooming, CAT_SPEC Q8) and
 * the weekly full litter change (CAT_SPEC Q3) — one component, `kind` picks texts and art.
 * Server M5-R06-05 (`CatChoreService`): 30 s, ≥ `min_strokes` strokes spread over all
 * three thirds, not machine-regular; while the coat is matted the grooming lasts 60 s and
 * needs 20 strokes (the session says so — `matted`, `duration_ms`, `min_strokes`).
 *
 * The child strokes (lift after ≥ 40 pt, or every turn while rubbing back and forth —
 * `moveRub`); each stroke is `{t}` ms since the local start. Thirds are shown as three steps
 * ("Glava in vrat → Hrbet → Boki in rep" / "Izprazni → Umij → Napolni"). "Počeši enkrat" /
 * "Podrgni enkrat" records a stroke for VoiceOver / TalkBack. "Ustavi" stops without
 * consequence. The server's verdict decides; a "didn't count" is never shaming.
 *
 * M5-R13 (David 2026-10-12, "kot pri mački"): a brushed dog plays the same grooming game —
 * same session, steps and verdict; only the words ("Počeši kužka", `child:grooming`) and the
 * drawing (the dog emoji instead of the cat) differ.
 *
 * M5-T14 (APP_REFINEMENT_SPEC §11 + §6): grooming plays as a scratch card (`BrushArea`) — the
 * brush follows the finger, sparkles, dirty patches fade per counted stroke, a bar fills; a
 * held finger / held "Počeši" button brushes too (no swiping needed). Same strokes, same
 * server verdict; a clean coat finishes up to 2 s early (`brushFinishAt`).
 *
 * M5-T17 »Lažje kretnje« (David 2026-10-23): the hold / button alternative is offered only in
 * hold mode (`useGestureMode`: the parent's switch or a running screen reader); otherwise
 * swipe only. In hold mode the weekly litter change plays on the same `BrushArea` (hold on the
 * box scrubs it gradually, patches fade, a bar fills) — same strokes, same server verdict, it
 * still finishes at the session's end. In swipe mode the litter change keeps its plain stage.
 */

import { useMemo, useRef } from 'react';
import { PanResponder, ScrollView, StyleSheet, View } from 'react-native';
import { Brush, Sparkles } from 'lucide-react-native';
import { useQueryClient } from '@tanstack/react-query';

import { CatFigure, TrayGraphic } from '@/components/CatGraphics';
import { Text } from '@/components/ui/Text';
import { childPetKey } from '@/hooks/queries/useChildPet';
import {
  CAT_COMMON_STRINGS,
  CHORE_STRINGS,
  catRefusalMessage,
  catWhen,
  onlyScratchingOpen,
  type CatFinishStatus,
  type ChoreKind,
  type ChoreResult,
  type ChoreSession,
} from '@/modules/catCare/catCare';
import { addStroke, beginRub, choreProgress, choreStepAt, endRub, moveRub, sessionTime, type RubTracker } from '@/modules/catCare/catGames';
import { Busy, CatOverlayFrame, CatStage, FailedView, PrimaryButton, SecondaryButton, SegmentDots, styles, useAnnounce } from '@/modules/catCare/CatGameParts';
import { BrushArea, type BrushTexts } from '@/modules/catCare/BrushArea';
import { DOG_GROOMING_STRINGS } from '@/modules/catCare/dogGrooming';
import { useChoreGame } from '@/modules/catCare/useCatGames';
import { useGestureMode, type GestureMode } from '@/modules/gestures/easyGestures';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import type { ClockSources } from '@/modules/training/game';
import { palette } from '@/theme';

const S = CHORE_STRINGS;
const C = CAT_COMMON_STRINGS;
const D = DOG_GROOMING_STRINGS;

/** The words of one stroke game (cat grooming, litter change, or — M5-R13 — dog grooming). */
interface ChoreTexts {
  title: string;
  intro: string;
  start: string;
  stroke: string;
  strokeA11y: string;
  stageA11y: string;
  steps: Readonly<Record<'1' | '2' | '3', string>>;
  result: { successTitle: string; success: string; failTitle: string };
}

/** A dog brushing (M5-R13): the grooming game of a pet that is not a cat. */
function isDogChore(kind: ChoreKind, view: ChildPetView): boolean {
  return kind === 'grooming' && view.pet.species !== 'cat';
}

/** The grooming week / matted lines (cat or dog words). */
function groomingLines(dog: boolean): { weekDone: string; week: (done: number, goal: number) => string; matted: (seconds: number) => string } {
  return dog ? D : S.grooming;
}

function choreTexts(kind: ChoreKind, view: ChildPetView): ChoreTexts {
  return isDogChore(kind, view) ? D : S[kind];
}

export interface ChoreOverlayProps {
  kind: ChoreKind;
  view: ChildPetView;
  onClose: () => void;
  reduceMotion: boolean;
  clock?: ClockSources;
  testID?: string;
}

/** Intro lines for the kind (week progress, matted note, overdue note) and whether "Začni" is possible. */
function introFor(kind: ChoreKind, view: ChildPetView): { lines: { text: string; strong?: boolean; done?: boolean }[]; canStart: boolean; blocked: string | null; blockedAt: string | null } {
  const ctx = { nowIso: view.server_time, timezone: view.timezone, scratchingOnly: onlyScratchingOpen(view.cat) };
  if (kind === 'grooming') {
    const g = view.cat.grooming;
    if (g === null) return { lines: [], canStart: false, blocked: 'grooming_not_available', blockedAt: null };
    const words = groomingLines(isDogChore(kind, view));
    const weekDone = g.goal_per_week > 0 && g.done_this_week >= g.goal_per_week;
    const lines = [{ text: weekDone ? words.weekDone : words.week(g.done_this_week, g.goal_per_week), strong: true, done: weekDone }];
    if (g.matted) lines.push({ text: words.matted(g.session_seconds), strong: false, done: false });
    return { lines, canStart: g.can_start, blocked: g.can_start ? null : g.blocked_reason, blockedAt: g.next_allowed_at };
  }
  const change = view.cat.litter?.change ?? null;
  if (change === null) return { lines: [], canStart: false, blocked: 'litter_not_available', blockedAt: null };
  const when = catWhen(change.due_at, ctx.nowIso, ctx.timezone);
  const lines = [{ text: change.done ? S.litter_change.weekDone : when !== null ? S.litter_change.weekOpen(when) : '', strong: true, done: change.done }];
  if (change.overdue && !change.done) lines.push({ text: S.litter_change.overdue, strong: false, done: false });
  // QA m1: only `litter_change_done` names a time — the next week (= the end of this one);
  // the payload has no time for the other reasons (→ their `_no_time` text).
  const blocked = change.can_start ? null : change.blocked_reason;
  return { lines: lines.filter((l) => l.text !== ''), canStart: change.can_start, blocked, blockedAt: blocked === 'litter_change_done' ? change.due_at : null };
}

export default function ChoreOverlay({ kind, view, onClose, reduceMotion, clock, testID = `cat-${kind}` }: ChoreOverlayProps) {
  const queryClient = useQueryClient();
  const game = useChoreGame(kind, view, { clock });
  const gestureMode = useGestureMode(view);
  const { phase } = game;
  const dog = isDogChore(kind, view);
  const texts = choreTexts(kind, view);
  const busy = phase.kind === 'starting' || phase.kind === 'finishing';
  const intro = introFor(kind, view);
  const onMediaExpired = () => void queryClient.invalidateQueries({ queryKey: childPetKey });
  const icon = kind === 'grooming' ? <Brush color={palette.mint} size={22} /> : <Sparkles color={palette.mint} size={22} />;

  return (
    <CatOverlayFrame
      title={texts.title}
      icon={icon}
      onClose={busy ? null : phase.kind === 'running' ? game.stop : onClose}
      closeMode={phase.kind === 'running' ? 'stop' : 'close'}
      testID={testID}
    >
      {phase.kind === 'intro' && (
        <ScrollView contentContainerStyle={styles.content} testID={`${testID}-intro`}>
          <Text style={styles.body}>{texts.intro}</Text>
          {intro.lines.map((line, i) => (
            <Text key={i} style={[line.strong ? styles.bodyStrong : styles.muted, line.done && styles.doneText]} testID={`${testID}-line-${i}`}>
              {line.text}
            </Text>
          ))}
          {intro.blocked !== null && (
            <Text style={styles.note} testID={`${testID}-blocked`}>
              {catRefusalMessage(intro.blocked, intro.blockedAt, { nowIso: view.server_time, timezone: view.timezone, scratchingOnly: onlyScratchingOpen(view.cat), dog })}
            </Text>
          )}
          <PrimaryButton label={texts.start} onPress={game.start} disabled={!intro.canStart} testID={`${testID}-start`} />
        </ScrollView>
      )}

      {phase.kind === 'starting' && <Busy text={dog ? D.starting : C.starting} testID={`${testID}-starting`} />}

      {(phase.kind === 'running' || phase.kind === 'finishing') && (
        <ChoreRunning
          kind={kind}
          texts={texts}
          dog={dog}
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
          <ChoreResultView kind={kind} texts={texts} result={phase.result} status={phase.status} onAgain={game.reset} onDone={onClose} testID={testID} />
        </ScrollView>
      )}

      {phase.kind === 'failed' && (
        <FailedView message={phase.message} canRetry={phase.retry !== null} onRetry={game.retryFinish} onBack={game.reset} testID={`${testID}-failed`} />
      )}
    </CatOverlayFrame>
  );
}

function ChoreResultView({
  kind,
  texts: words,
  result,
  status,
  onAgain,
  onDone,
  testID,
}: {
  kind: ChoreKind;
  texts: ChoreTexts;
  result: ChoreResult;
  status: CatFinishStatus;
  onAgain: () => void;
  onDone: () => void;
  testID: string;
}) {
  const texts = words.result;
  return (
    <View style={styles.resultBox} testID={`${testID}-result`}>
      <Text style={styles.resultTitle} accessibilityRole="header" testID={`${testID}-result-title`}>
        {result.success ? texts.successTitle : texts.failTitle}
      </Text>
      {status === 'unchanged' && <Text style={styles.muted}>{C.stored}</Text>}
      <Text style={styles.body} testID={`${testID}-result-text`}>
        {result.success ? texts.success : S.reasons[result.reason ?? 'unknown']}
      </Text>
      {result.success && kind === 'grooming' && result.matted && (
        <Text style={[styles.bodyStrong, styles.doneText]} testID={`${testID}-result-matted`}>
          {S.mattedFixed}
        </Text>
      )}
      <Text style={styles.muted}>{S.count(result.strokes)}</Text>
      {!result.success && <Text style={styles.muted}>{C.noPenalty}</Text>}
      <View style={styles.row}>
        {!result.success && <SecondaryButton label={S.again} onPress={onAgain} testID={`${testID}-again`} />}
        <View style={styles.flexButton}>
          <PrimaryButton label={S.done} onPress={onDone} testID={`${testID}-done`} />
        </View>
      </View>
    </View>
  );
}

function ChoreRunning({
  kind,
  texts,
  dog,
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
  kind: ChoreKind;
  texts: ChoreTexts;
  dog: boolean;
  view: ChildPetView;
  session: ChoreSession;
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
  const stepText = texts.steps[String(step) as '1' | '2' | '3'];
  useAnnounce(stepText);
  const disabled = finishing || time.over;
  const disabledRef = useRef(disabled);
  disabledRef.current = disabled;
  const onStrokeRef = useRef(onStroke);
  onStrokeRef.current = onStroke;
  const msAtRef = useRef(msAt);
  msAtRef.current = msAt;
  const rub = useRef<RubTracker | null>(null);
  const origin = useRef({ x: 0, y: 0 });
  // Grooming always brushes on the scratch card; the litter change only in hold mode (M5-T17).
  const brushTexts: BrushTexts | null =
    kind === 'grooming' ? (dog ? D.brush : S.grooming.brush) : gestureMode === 'hold' ? S.litter_change.scrub : null;

  const responder = useMemo(
    () =>
      PanResponder.create({
        onStartShouldSetPanResponder: () => !disabledRef.current,
        onMoveShouldSetPanResponder: () => !disabledRef.current,
        onPanResponderGrant: (e) => {
          origin.current = { x: e.nativeEvent.locationX, y: e.nativeEvent.locationY };
          rub.current = beginRub(origin.current, msAtRef.current(e.nativeEvent.timestamp));
        },
        onPanResponderMove: (e, g) => {
          if (rub.current === null) return;
          const step = moveRub(rub.current, { x: origin.current.x + g.dx, y: origin.current.y + g.dy }, msAtRef.current(e.nativeEvent.timestamp));
          rub.current = step.tracker;
          if (step.stroke !== null) onStrokeRef.current(step.stroke);
        },
        onPanResponderRelease: (e) => {
          if (rub.current === null) return;
          const stroke = endRub(rub.current, msAtRef.current(e.nativeEvent.timestamp));
          rub.current = null;
          if (stroke !== null) onStrokeRef.current(stroke);
        },
        onPanResponderTerminate: () => {
          rub.current = null;
        },
      }),
    [],
  );

  const illustration = dog ? (
    // M5-R13: the dog's drawing — happy once enough strokes are in (a change, not an animation).
    <Text style={dogStyles.figure} accessible={false}>
      {progress.strokes >= session.min_strokes ? '🐶' : '🐕'}
    </Text>
  ) :
    kind === 'grooming' ? (
      // Content once enough strokes are in (a pose change, not an animation — fine with "reduce motion").
      <CatFigure pose={progress.strokes >= session.min_strokes ? 'happy' : 'sit'} size={140} />
    ) : (
      <TrayGraphic size={200} step={step} />
    );

  return (
    <View style={styles.running} testID={`${testID}-running`}>
      <View style={styles.rowBetween}>
        <SegmentDots total={Math.max(1, session.segments)} hit={progress.segmentsHit} current={time.over ? null : step - 1} label={S.stepA11y} testID={`${testID}-steps`} />
        <Text style={styles.muted} testID={`${testID}-seconds`}>
          {C.secondsLeft(time.secondsLeft)}
        </Text>
      </View>
      {resumed && (
        <Text style={styles.muted} testID={`${testID}-resumed`}>
          {C.resumed}
        </Text>
      )}
      {brushTexts !== null ? (
        <BrushArea
          rules={session}
          texts={brushTexts}
          stageA11y={texts.stageA11y}
          strokeLabel={texts.stroke}
          strokeA11y={texts.strokeA11y}
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
        <View style={styles.running} {...responder.panHandlers} testID={`${testID}-stage-touch`}>
          <View style={styles.running} pointerEvents="none">
            <CatStage
              view={view}
              petState="idle"
              illustration={illustration}
              onMediaExpired={onMediaExpired}
              accessibilityLabel={texts.stageA11y}
              testID={`${testID}-stage`}
            />
          </View>
        </View>
      )}
      <Text style={styles.statusLine} testID={`${testID}-step`} accessibilityLiveRegion="polite">
        {stepText}
      </Text>
      <Text style={[styles.bodyCenter, progress.strokes >= session.min_strokes && styles.doneText]} testID={`${testID}-progress`}>
        {S.progress(Math.min(progress.strokes, session.min_strokes), session.min_strokes)}
      </Text>
      {finishing ? (
        <Busy text={C.finishing} testID={`${testID}-finishing`} />
      ) : null}
    </View>
  );
}

const dogStyles = StyleSheet.create({
  figure: {
    fontSize: 96,
    textAlign: 'center',
  },
});
