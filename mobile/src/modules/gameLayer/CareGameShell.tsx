/**
 * CareGameShell — the care games' running screen on the game layer (GAMES_SPEC §5.5 / §7.7):
 * grooming / brushing, the weekly litter change, the breed care tasks and the scratching post.
 * NO rule changes — the game inside (`BrushArea`, the scrub responder, `ClawsStage`, the praise
 * button) records exactly the strokes / taps / praise it always did; the shell only changes the
 * presentation:
 *
 * - full screen (`GameScreen`): the animal / object edge to edge (`stage`), the game's touch
 *   area over it (`children`), the big button in the dock;
 * - text only on the ONE top line (`GameLine`: ≤ 32 chars, ≥ 2 s); step dots in the top bar;
 * - one soft haptic per counted stroke, a ✓ (+ chime + success haptic) at the end of each step
 *   (`careFrame.ts`); `okSignal` / `missSignal` counters for a one-shot verdict (scratching:
 *   ✓ for a praise in time, a soft amber ✗ for one too early — never a sound or haptic for it).
 *
 * Reached ONLY through `loadCareShell()` behind `gameLayerAvailable()` (this file imports the
 * native layer); an older binary keeps the old card screens.
 */

import { useContext, useEffect, useRef, type ReactNode } from 'react';
import { StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';

import { careCues, type CareFrame } from '@/modules/gameLayer/careFrame';
import FeedbackLayer, { type FeedbackHandle } from '@/modules/gameLayer/FeedbackLayer';
import type { GameSound } from '@/modules/gameLayer/gameFxCore';
import { useGameLine } from '@/modules/gameLayer/GameLine';
import GameScreen from '@/modules/gameLayer/GameScreen';
import GameTopBar from '@/modules/gameLayer/GameTopBar';
import { useGameFx } from '@/modules/gameLayer/useGameFx';

export interface CareGameShellProps {
  /** The full-screen animal / object (under everything). */
  stage: ReactNode;
  /** The game's touch area (fills the screen between the top bar and the dock). */
  children?: ReactNode;
  dock?: ReactNode;
  /** The one line (step text, hint); null = keep / clear. */
  line: string | null;
  frame: CareFrame;
  /** Bump to show a ✓ (one-shot verdicts). */
  okSignal?: number;
  /** Bump to show a soft ✗ (amber, silent). */
  missSignal?: number;
  gameFeedback: boolean;
  reduceMotion: boolean;
  closeLabel: string;
  progressLabel: string;
  /** The ×'s action (stop); omitted → the × is locked (e.g. the short scratching carry, finishing) and comes back after GAME_ESCAPE_MS with the host's exit (`GameExitContext`). */
  onClose?: () => void;
  testID: string;
}

const SOUNDS: readonly GameSound[] = ['chime'];

export default function CareGameShell({
  stage,
  children,
  dock,
  line,
  frame,
  okSignal = 0,
  missSignal = 0,
  gameFeedback,
  reduceMotion,
  closeLabel,
  progressLabel,
  onClose,
  testID,
}: CareGameShellProps) {
  const insets = useContext(SafeAreaInsetsContext) ?? { top: 0, bottom: 0, left: 0, right: 0 };
  const fx = useGameFx(gameFeedback, SOUNDS);
  const gameLine = useGameLine();
  const feedback = useRef<FeedbackHandle>(null);
  const say = gameLine.say;

  useEffect(() => {
    if (line !== null) say(line);
  }, [line, say]);

  const prev = useRef<CareFrame | null>(null);
  useEffect(() => {
    const cues = careCues(prev.current, frame);
    prev.current = frame;
    for (const cue of cues) {
      if (cue.kind === 'stroke') fx.haptic('tap');
      else {
        void feedback.current?.show('ok', { totalMs: 900 });
        fx.sound('chime');
        fx.haptic('success');
      }
    }
  }, [frame, fx]);

  const okSeen = useRef(okSignal);
  useEffect(() => {
    if (okSignal === okSeen.current) return;
    okSeen.current = okSignal;
    void feedback.current?.show('ok', { totalMs: 1_000 });
    fx.sound('chime');
    fx.haptic('success');
  }, [okSignal, fx]);
  const missSeen = useRef(missSignal);
  useEffect(() => {
    if (missSignal === missSeen.current) return;
    missSeen.current = missSignal;
    void feedback.current?.show('miss', { totalMs: 900 });
  }, [missSignal]);

  return (
    <GameScreen
      testID={testID}
      stage={stage}
      field={
        <View
          style={[styles.field, { top: insets.top + 76, bottom: insets.bottom + (dock ? 112 : 24), left: insets.left + 12, right: insets.right + 12 }]}
          testID={`${testID}-area`}
        >
          {children}
        </View>
      }
      feedback={<FeedbackLayer ref={feedback} reduceMotion={reduceMotion} testID={`${testID}-feedback`} />}
      topBar={
        <GameTopBar dots={frame.stepsDone} stars={0} line={gameLine.text} closeLabel={closeLabel} progressLabel={progressLabel} onClose={onClose} closeLocked={onClose === undefined} testID={`${testID}-top`} />
      }
      dock={dock}
    />
  );
}

const styles = StyleSheet.create({
  field: { position: 'absolute' },
});
