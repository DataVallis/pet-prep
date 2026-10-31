/**
 * The chewed slipper on the native game layer (GAMES_SPEC §6.2, phase 1; David 2026-10-30).
 *
 * Reached ONLY through `loadMessGames()` (imports gesture-handler, reanimated, expo-audio,
 * expo-haptics); an older binary keeps the »Pospravi in daj igračo« button, which resolves at once.
 *
 * 1. Drag the slipper and its 3 flakes into the box (tap = into the box) → the box closes.
 * 2. A chew toy appears: drag it to the dog (tap = give) → ♥, ✓ »Bravo!«.
 * ONE `onResolved()` at the end → the HUD sends `resolve_chewing`, exactly as the button did
 * (server unchanged). Hold mode: »Pospravi kos« / »Daj igračo« buttons. Reduce motion: the pieces
 * and the toy just disappear.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { useDerivedValue, useSharedValue, withSpring } from 'react-native-reanimated';
import { scheduleOnRN } from 'react-native-worklets';
import { Heart, Package, PackageOpen, ToyBrick } from 'lucide-react-native';

import { ChewedSlipperGraphic } from '@/components/BehaviourGraphics';
import { ChewToyGraphic, FlakeGraphic } from '@/components/MessGraphics';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { MESS_GAME_STRINGS } from '@/modules/cleaning/cleaningStrings';
import { allDone, countBits, hasBit, pieceUnder, within } from '@/modules/cleaning/hits';
import { MessButton, MessField, MessStage, MintSparkles, Sprite, Target, TopArea, sendAway, usePieceAnim, type FieldLayout, type PieceAnim } from '@/modules/cleaning/MessParts';
import FeedbackLayer, { type FeedbackHandle } from '@/modules/gameLayer/FeedbackLayer';
import { gameFeedbackOf } from '@/modules/gameLayer/gameFeedback';
import { useGameLine } from '@/modules/gameLayer/GameLine';
import GameScreen from '@/modules/gameLayer/GameScreen';
import GameTopBar from '@/modules/gameLayer/GameTopBar';
import { useGameFx } from '@/modules/gameLayer/useGameFx';
import { useGameGestures, type GameGestureHandlers } from '@/modules/gameLayer/useGameGestures';
import { palette } from '@/theme';

export interface SlipperGameProps {
  view: ChildPetView;
  reduceMotion: boolean;
  /** Box closed and toy given (called once) → the HUD sends `resolve_chewing`. */
  onResolved: () => void;
  onClose: () => void;
  onMediaExpired?: () => void;
  testID?: string;
}

/** The slipper and its 3 flakes on the floor (fractions of the field) and their width (of W). */
export const SLIPPER_ITEMS: readonly { x: number; y: number; w: number }[] = [
  { x: 0.48, y: 0.64, w: 0.34 },
  { x: 0.2, y: 0.7, w: 0.12 },
  { x: 0.74, y: 0.72, w: 0.11 },
  { x: 0.82, y: 0.6, w: 0.1 },
];
export const SLIPPER_BOX = { x: 0.2, y: 0.86 } as const;
export const SLIPPER_TOY = { x: 0.8, y: 0.86 } as const;
/** Where the dog is (upper middle of the video) — the toy lands within this radius (of W). */
export const DOG_ZONE = { x: 0.5, y: 0.4, r: 0.28 } as const;

const TIDY = 0;
const TOY = 1;
const DONE = 2;
const TOY_INDEX = SLIPPER_ITEMS.length;
const FEEDBACK_MS = 1_000;

function Item({ index, x, y, w, anim, testID }: { index: number; x: number; y: number; w: number; anim: PieceAnim; testID: string }) {
  const px = useDerivedValue(() => x + anim.tx.value);
  const py = useDerivedValue(() => y + anim.ty.value);
  const h = index === 0 ? (w * 80) / 120 : index === TOY_INDEX ? w : (w * 30) / 40;
  return (
    <Sprite x={px} y={py} width={w} height={h} scale={anim.scale} opacity={anim.opacity} testID={testID}>
      {index === 0 ? <ChewedSlipperGraphic size={w} testID={`${testID}-art`} /> : index === TOY_INDEX ? <ChewToyGraphic size={w} /> : <FlakeGraphic size={w} />}
    </Sprite>
  );
}

export default function SlipperGame({ view, reduceMotion, onResolved, onClose, onMediaExpired, testID = 'slipper-game' }: SlipperGameProps) {
  const S = MESS_GAME_STRINGS;
  const [layout, setLayout] = useState<FieldLayout | null>(null);
  const W = layout?.width ?? 0;
  const H = layout?.height ?? 0;
  const xs = useMemo(() => [...SLIPPER_ITEMS.map((i) => i.x * W), SLIPPER_TOY.x * W], [W]);
  const ys = useMemo(() => [...SLIPPER_ITEMS.map((i) => i.y * H), SLIPPER_TOY.y * H], [H]);
  const box = { x: SLIPPER_BOX.x * W, y: SLIPPER_BOX.y * H };
  const dog = { x: DOG_ZONE.x * W, y: DOG_ZONE.y * H, r: DOG_ZONE.r * W };
  const grab = Math.max(48, W * 0.14);

  const fx = useGameFx(gameFeedbackOf(view));
  const line = useGameLine();
  const feedback = useRef<FeedbackHandle>(null);
  const resolvedRef = useRef(onResolved);
  resolvedRef.current = onResolved;
  const a0 = usePieceAnim();
  const a1 = usePieceAnim();
  const a2 = usePieceAnim();
  const a3 = usePieceAnim();
  const a4 = usePieceAnim();
  const anims = useMemo(() => [a0, a1, a2, a3, a4], [a0, a1, a2, a3, a4]);

  const [phase, setPhase] = useState(TIDY);
  const [doneMaskJs, setDoneMaskJs] = useState(0);
  const [hearts, setHearts] = useState(0);
  const phaseSV = useSharedValue(TIDY);
  const doneMask = useSharedValue(0);
  const dragIdx = useSharedValue(-1);
  const xsSV = useSharedValue<number[]>([]);
  const ysSV = useSharedValue<number[]>([]);
  const geo = useSharedValue({ boxX: 0, boxY: 0, boxR: 0, dogX: 0, dogY: 0, dogR: 0, grab: 48 });
  const doneRef = useRef(0);
  const phaseRef = useRef(TIDY);
  const timers = useRef<ReturnType<typeof setTimeout>[]>([]);
  useEffect(() => () => timers.current.forEach(clearTimeout), []);
  const later = (fn: () => void, ms: number) => timers.current.push(setTimeout(fn, ms));

  useEffect(() => {
    xsSV.value = xs;
    ysSV.value = ys;
    geo.value = { boxX: box.x, boxY: box.y, boxR: Math.max(64, W * 0.18), dogX: dog.x, dogY: dog.y, dogR: dog.r, grab };
  }, [xs, ys, box.x, box.y, dog.x, dog.y, dog.r, grab, W, xsSV, ysSV, geo]);

  const toPhase = (p: number) => {
    phaseRef.current = p;
    phaseSV.value = p;
    setPhase(p);
  };

  const boxed = useCallback(
    (i: number) => {
      if (phaseRef.current !== TIDY || i < 0 || i >= TOY_INDEX || hasBit(doneRef.current, i)) return;
      doneRef.current |= 1 << i;
      doneMask.value = doneRef.current;
      setDoneMaskJs(doneRef.current);
      fx.sound('pop');
      fx.haptic('tap');
      // From wherever it was dropped (its offset) into the box.
      sendAway(anims[i], box.x - xs[i], box.y - ys[i], reduceMotion, 260, 0.3);
      if (allDone(doneRef.current, TOY_INDEX)) {
        phaseRef.current = TOY;
        phaseSV.value = -1; // nothing grabbable while the box closes
        fx.sound('chime');
        fx.haptic('success');
        void feedback.current?.show('ok', { label: S.fb.boxed, totalMs: FEEDBACK_MS });
        later(() => toPhase(TOY), FEEDBACK_MS);
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [anims, box.x, box.y, xs, ys, reduceMotion, fx, doneMask, phaseSV, S],
  );

  const toyGiven = useCallback(() => {
    if (phaseRef.current !== TOY) return;
    toPhase(DONE);
    fx.sound('whoosh');
    sendAway(anims[TOY_INDEX], dog.x - xs[TOY_INDEX], dog.y - ys[TOY_INDEX], reduceMotion, 450, 0.7);
    later(() => {
      setHearts((n) => n + 1);
      fx.sound('chime');
      fx.haptic('success');
      void feedback.current?.show('ok', { label: S.fb.toy, totalMs: FEEDBACK_MS });
    }, reduceMotion ? 50 : 450);
    later(() => resolvedRef.current(), (reduceMotion ? 50 : 450) + FEEDBACK_MS);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [anims, dog.x, dog.y, xs, ys, reduceMotion, fx, S]);

  const handlers = useMemo<GameGestureHandlers>(
    () => ({
      onPanBegin: (e) => {
        'worklet';
        const g = geo.value;
        if (phaseSV.value === TIDY) {
          dragIdx.value = pieceUnder(xsSV.value.slice(0, TOY_INDEX), ysSV.value.slice(0, TOY_INDEX), doneMask.value, e.x, e.y, g.grab);
        } else if (phaseSV.value === TOY && within(e.x, e.y, xsSV.value[TOY_INDEX], ysSV.value[TOY_INDEX], g.grab)) {
          dragIdx.value = TOY_INDEX;
        } else {
          dragIdx.value = -1;
        }
      },
      onPanUpdate: (e) => {
        'worklet';
        const i = dragIdx.value;
        if (i < 0) return;
        anims[i].tx.value = e.translationX;
        anims[i].ty.value = e.translationY;
      },
      onPanEnd: (e) => {
        'worklet';
        const i = dragIdx.value;
        dragIdx.value = -1;
        if (i < 0) return;
        const g = geo.value;
        const dropX = xsSV.value[i] + e.translationX;
        const dropY = ysSV.value[i] + e.translationY;
        if (i < TOY_INDEX && phaseSV.value === TIDY && within(dropX, dropY, g.boxX, g.boxY, g.boxR)) {
          doneMask.value = doneMask.value | (1 << i);
          scheduleOnRN(boxed, i);
        } else if (i === TOY_INDEX && phaseSV.value === TOY && within(dropX, dropY, g.dogX, g.dogY, g.dogR)) {
          phaseSV.value = DONE;
          scheduleOnRN(toyGiven);
        } else {
          anims[i].tx.value = withSpring(0);
          anims[i].ty.value = withSpring(0);
        }
      },
      onTap: (e) => {
        'worklet';
        const g = geo.value;
        if (phaseSV.value === TIDY) {
          const i = pieceUnder(xsSV.value.slice(0, TOY_INDEX), ysSV.value.slice(0, TOY_INDEX), doneMask.value, e.x, e.y, g.grab);
          if (i >= 0) {
            doneMask.value = doneMask.value | (1 << i);
            scheduleOnRN(boxed, i);
          }
        } else if (phaseSV.value === TOY && within(e.x, e.y, xsSV.value[TOY_INDEX], ysSV.value[TOY_INDEX], g.grab)) {
          phaseSV.value = DONE;
          scheduleOnRN(toyGiven);
        }
      },
    }),
    [geo, phaseSV, dragIdx, xsSV, ysSV, doneMask, anims, boxed, toyGiven],
  );
  const gestures = useGameGestures(view, handlers);
  const easy = gestures.inputMode === 'hold';

  const boxNext = () => {
    for (let i = 0; i < TOY_INDEX; i++) {
      if (!hasBit(doneRef.current, i)) {
        boxed(i);
        return;
      }
    }
  };

  const say = line.say;
  useEffect(() => {
    if (phase === TIDY) say(easy ? S.slipper.holdLine : S.slipper.line);
    else if (phase === TOY) say(S.slipper.toyLine);
  }, [phase, easy, say, S]);

  const dots = [...SLIPPER_ITEMS.map((_, i) => hasBit(doneMaskJs, i)), phase === DONE];
  const itemW = (i: number) => (i === TOY_INDEX ? Math.max(64, W * 0.2) : SLIPPER_ITEMS[i].w * W);
  const boxClosed = phase !== TIDY || allDone(doneMaskJs, TOY_INDEX);

  const field =
    layout === null ? null : (
      <>
        <Target x={box.x} y={box.y} size={80} testID={boxClosed ? `${testID}-box-closed` : `${testID}-box`}>
          {boxClosed ? <Package color={palette.mint} size={34} /> : <PackageOpen color={palette.n200} size={34} />}
        </Target>
        {SLIPPER_ITEMS.map((_, i) => (
          <Item key={i} index={i} x={xs[i]} y={ys[i]} w={itemW(i)} anim={anims[i]} testID={`${testID}-item-${i}`} />
        ))}
        {phase !== TIDY && <Item index={TOY_INDEX} x={xs[TOY_INDEX]} y={ys[TOY_INDEX]} w={itemW(TOY_INDEX)} anim={anims[TOY_INDEX]} testID={`${testID}-toy`} />}
        {hearts > 0 && (
          <View pointerEvents="none" style={[styles.heart, { left: dog.x - 28, top: dog.y - 28 }]} testID={`${testID}-heart`}>
            <Heart color={palette.mint} fill={palette.mint} size={56} />
          </View>
        )}
        <MintSparkles at={hearts > 0 ? { x: dog.x, y: dog.y } : null} burst={hearts} reduceMotion={reduceMotion} />
      </>
    );

  const dock = !easy ? null : phase === TIDY ? (
    <MessButton label={S.slipper.button} a11y={S.slipper.a11y} icon={<PackageOpen color={palette.graphite} size={22} />} onPress={boxNext} testID={`${testID}-box-button`} />
  ) : phase === TOY ? (
    <MessButton label={S.slipper.toyButton} a11y={S.slipper.toyA11y} icon={<ToyBrick color={palette.graphite} size={22} />} onPress={toyGiven} testID={`${testID}-toy-button`} />
  ) : null;

  return (
    <GameScreen
      testID={testID}
      stage={<MessStage view={view} onMediaExpired={onMediaExpired} />}
      field={
        <MessField gesture={gestures.gesture} onLayout={setLayout} testID={`${testID}-field`}>
          {field}
        </MessField>
      }
      feedback={<FeedbackLayer ref={feedback} reduceMotion={reduceMotion} testID={`${testID}-feedback`} />}
      topBar={
        <TopArea
          tip={null}
          bar={
            <GameTopBar
              dots={dots}
              stars={0}
              line={line.text}
              closeLabel={S.close}
              progressLabel={S.a11y.progress(countBits(doneMaskJs) + (phase === DONE ? 1 : 0), dots.length)}
              onClose={onClose}
              testID={`${testID}-top`}
            />
          }
        />
      }
      dock={dock}
    />
  );
}

const styles = StyleSheet.create({
  heart: { position: 'absolute', width: 56, height: 56 },
});
