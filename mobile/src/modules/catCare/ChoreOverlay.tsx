/**
 * The stroke mini-games (M5-R06-08a): "Počeši muco" (Maine Coon grooming, CAT_SPEC Q8) and
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
 */

import { useMemo, useRef } from 'react';
import { PanResponder, ScrollView, View } from 'react-native';
import { Brush, Hand, Sparkles } from 'lucide-react-native';
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
import { useChoreGame } from '@/modules/catCare/useCatGames';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import type { ClockSources } from '@/modules/training/game';
import { palette } from '@/theme';

const S = CHORE_STRINGS;
const C = CAT_COMMON_STRINGS;

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
    const weekDone = g.goal_per_week > 0 && g.done_this_week >= g.goal_per_week;
    const lines = [{ text: weekDone ? S.grooming.weekDone : S.grooming.week(g.done_this_week, g.goal_per_week), strong: true, done: weekDone }];
    if (g.matted) lines.push({ text: S.grooming.matted(g.session_seconds), strong: false, done: false });
    return { lines, canStart: g.can_start, blocked: g.can_start ? null : g.blocked_reason, blockedAt: g.next_allowed_at };
  }
  const change = view.cat.litter?.change ?? null;
  if (change === null) return { lines: [], canStart: false, blocked: 'litter_not_available', blockedAt: null };
  const when = catWhen(change.due_at, ctx.nowIso, ctx.timezone);
  const lines = [{ text: change.done ? S.litter_change.weekDone : when !== null ? S.litter_change.weekOpen(when) : '', strong: true, done: change.done }];
  if (change.overdue && !change.done) lines.push({ text: S.litter_change.overdue, strong: false, done: false });
  // `litter_change_done` names the next week: the end of this one.
  return { lines: lines.filter((l) => l.text !== ''), canStart: change.can_start, blocked: change.can_start ? null : change.blocked_reason, blockedAt: change.due_at };
}

export default function ChoreOverlay({ kind, view, onClose, reduceMotion, clock, testID = `cat-${kind}` }: ChoreOverlayProps) {
  const queryClient = useQueryClient();
  const game = useChoreGame(kind, view, { clock });
  const { phase } = game;
  const texts = S[kind];
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
              {catRefusalMessage(intro.blocked, intro.blockedAt, { nowIso: view.server_time, timezone: view.timezone, scratchingOnly: onlyScratchingOpen(view.cat) })}
            </Text>
          )}
          <PrimaryButton label={texts.start} onPress={game.start} disabled={!intro.canStart} testID={`${testID}-start`} />
        </ScrollView>
      )}

      {phase.kind === 'starting' && <Busy text={C.starting} testID={`${testID}-starting`} />}

      {(phase.kind === 'running' || phase.kind === 'finishing') && (
        <ChoreRunning
          kind={kind}
          view={view}
          session={phase.session}
          strokes={phase.input}
          elapsedMs={game.elapsedMs}
          finishing={phase.kind === 'finishing'}
          resumed={phase.resumedAtMs !== null}
          reduceMotion={reduceMotion}
          msAt={game.msAt}
          onStroke={(ms) => game.setInput((strokes) => addStroke(strokes, ms, phase.session.duration_ms))}
          onMediaExpired={onMediaExpired}
          testID={testID}
        />
      )}

      {phase.kind === 'result' && (
        <ScrollView contentContainerStyle={styles.content}>
          <ChoreResultView kind={kind} result={phase.result} status={phase.status} onAgain={game.reset} onDone={onClose} testID={testID} />
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
  result,
  status,
  onAgain,
  onDone,
  testID,
}: {
  kind: ChoreKind;
  result: ChoreResult;
  status: CatFinishStatus;
  onAgain: () => void;
  onDone: () => void;
  testID: string;
}) {
  const texts = S[kind].result;
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
  view,
  session,
  strokes,
  elapsedMs,
  finishing,
  resumed,
  reduceMotion,
  msAt,
  onStroke,
  onMediaExpired,
  testID,
}: {
  kind: ChoreKind;
  view: ChildPetView;
  session: ChoreSession;
  strokes: readonly number[];
  elapsedMs: number;
  finishing: boolean;
  resumed: boolean;
  reduceMotion: boolean;
  msAt: (touchTimestamp?: number | null) => number;
  onStroke: (ms: number) => void;
  onMediaExpired: () => void;
  testID: string;
}) {
  const texts = S[kind];
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

  const illustration =
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
      <Text style={styles.statusLine} testID={`${testID}-step`} accessibilityLiveRegion="polite">
        {stepText}
      </Text>
      <Text style={[styles.bodyCenter, progress.strokes >= session.min_strokes && styles.doneText]} testID={`${testID}-progress`}>
        {S.progress(Math.min(progress.strokes, session.min_strokes), session.min_strokes)}
      </Text>
      {finishing ? (
        <Busy text={C.finishing} testID={`${testID}-finishing`} />
      ) : (
        <SecondaryButton
          label={texts.stroke}
          accessibilityLabel={texts.strokeA11y}
          disabled={disabled}
          icon={<Hand color={palette.mint} size={18} />}
          onPress={() => onStroke(msAt(null))}
          testID={`${testID}-stroke`}
        />
      )}
    </View>
  );
}
