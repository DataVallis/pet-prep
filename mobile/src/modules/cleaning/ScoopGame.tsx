/**
 * »Počisti pesek« on the native game layer (GAMES_SPEC §6.2, phase 1; CAT_SPEC Q3).
 *
 * Reached ONLY through `loadMessGames()` (imports gesture-handler, reanimated, expo-audio,
 * expo-haptics); an older binary keeps the tap game `catCare/ScoopOverlay`. Opened only when there
 * is something to scoop (`CatCareOverlay`) — a clean tray keeps the old calm »nothing to scoop«.
 *
 * - The tray with 2–6 light clumps (`scoop.ts`, two per open litter use).
 * - Drag the scoop through the sand: the clumps it passes over STICK to it (segment hit-test on the
 *   UI thread, `hits.ts#scoopPicks`); shake it upward (`isShakeUp`) → they fly into the bag. Tap a
 *   clump = straight into the bag. Hold mode: »Poberi grudico«.
 * - All in the bag → the sand smooths (a wave), sparkles → ONE `POST /api/child/pet/litter/scoop`
 *   (`useScoopLitter`, optimistic, the server decides in time / not) — exactly as before.
 * - Reduce motion: no flying and no wave — clumps just disappear.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withSpring, withTiming, type SharedValue } from 'react-native-reanimated';
import { scheduleOnRN } from 'react-native-worklets';
import { Shovel } from 'lucide-react-native';

import { TrayGraphic } from '@/components/CatGraphics';
import { BagGraphic, ClumpGraphic, ScoopGraphic } from '@/components/MessGraphics';
import { Text } from '@/components/ui/Text';
import { useScoopLitter } from '@/hooks/queries/useCatCare';
import { SCOOP_STRINGS, onlyScratchingOpen } from '@/modules/catCare/catCare';
import { PrimaryButton } from '@/modules/catCare/CatGameParts';
import { catFailureText } from '@/modules/catCare/useCatSessionGame';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { MESS_GAME_STRINGS, SCOOP_GAME_STRINGS } from '@/modules/cleaning/cleaningStrings';
import { allDone, countBits, hasBit, isShakeUp, pieceUnder, scoopPicks } from '@/modules/cleaning/hits';
import { LIFT, MessButton, MessField, MessStage, MintSparkles, Sprite, Target, TopArea, usePieceAnim, type FieldLayout, type PieceAnim } from '@/modules/cleaning/MessParts';
import { clumpCount, clumpInTray } from '@/modules/cleaning/scoop';
import FeedbackLayer, { type FeedbackHandle } from '@/modules/gameLayer/FeedbackLayer';
import { gameFeedbackOf } from '@/modules/gameLayer/gameFeedback';
import { useGameLine } from '@/modules/gameLayer/GameLine';
import GameScreen from '@/modules/gameLayer/GameScreen';
import GameTopBar from '@/modules/gameLayer/GameTopBar';
import { useGameFx } from '@/modules/gameLayer/useGameFx';
import { useGameGestures, type GameGestureHandlers } from '@/modules/gameLayer/useGameGestures';
import { alpha, fonts, palette, tightTracking } from '@/theme';

export interface ScoopGameProps {
  view: ChildPetView;
  reduceMotion: boolean;
  onClose: () => void;
  onMediaExpired?: () => void;
  testID?: string;
}

/** Where a stuck clump sits on the scoop's head (pt from the scoop's centre). */
const ON_SCOOP: readonly { x: number; y: number }[] = [
  { x: -12, y: -8 },
  { x: 8, y: -10 },
  { x: -4, y: 4 },
  { x: 14, y: 4 },
  { x: -16, y: 8 },
  { x: 4, y: 14 },
];
const MAX_CLUMPS = 6;
const TRAY_W = 0.9;
const TRAY_CY = 0.62;

type Phase = { kind: 'play' } | { kind: 'saving' } | { kind: 'done'; text: string };

/** Tray box (field points). */
export function trayGeometry(W: number, H: number) {
  const w = W * TRAY_W;
  const h = (w * 90) / 160;
  return { x: W / 2 - w / 2, y: H * TRAY_CY - h / 2, w, h };
}

function Clump({
  index,
  x,
  y,
  size,
  anim,
  stuck,
  scoopX,
  scoopY,
  testID,
}: {
  index: number;
  x: number;
  y: number;
  size: number;
  anim: PieceAnim;
  stuck: SharedValue<number>;
  scoopX: SharedValue<number>;
  scoopY: SharedValue<number>;
  testID: string;
}) {
  const off = ON_SCOOP[index % ON_SCOOP.length];
  const style = useAnimatedStyle(() => {
    const onScoop = (stuck.value & (1 << index)) !== 0;
    const cx = onScoop ? scoopX.value + off.x : x + anim.tx.value;
    const cy = onScoop ? scoopY.value + off.y - 6 : y + anim.ty.value;
    return {
      opacity: anim.opacity.value,
      transform: [{ translateX: cx - size / 2 }, { translateY: cy - (size * 0.75) / 2 }, { scale: anim.scale.value }],
    };
  });
  return (
    <Animated.View pointerEvents="none" style={[styles.clump, { width: size, height: size * 0.75 }, style]} testID={testID}>
      <ClumpGraphic size={size} />
    </Animated.View>
  );
}

export default function ScoopGame({ view, reduceMotion, onClose, onMediaExpired, testID = 'scoop-game' }: ScoopGameProps) {
  const S = SCOOP_GAME_STRINGS;
  const litter = view.cat.litter;
  // Fixed when the game opens: the optimistic scoop empties `open_uses` at once.
  const [initialUses] = useState(() => litter?.open_uses ?? []);
  const total = Math.min(MAX_CLUMPS, clumpCount(initialUses.length));
  const messNearby = initialUses.some((u) => u.expired);
  const [layout, setLayout] = useState<FieldLayout | null>(null);
  const W = layout?.width ?? 0;
  const H = layout?.height ?? 0;
  const tray = useMemo(() => trayGeometry(W, H), [W, H]);
  const xs = useMemo(() => Array.from({ length: total }, (_, i) => tray.x + clumpInTray(i).x * tray.w), [total, tray]);
  const ys = useMemo(() => Array.from({ length: total }, (_, i) => tray.y + clumpInTray(i).y * tray.h), [total, tray]);
  const clumpSize = Math.max(32, W * 0.1);
  const home = { x: W * 0.2, y: H * 0.88 };
  const bag = { x: W * 0.8, y: H * 0.88 };
  const reach = Math.max(30, W * 0.08);

  const fx = useGameFx(gameFeedbackOf(view));
  const line = useGameLine();
  const feedback = useRef<FeedbackHandle>(null);
  const { mutate } = useScoopLitter();
  const ctx = useMemo(
    () => ({ nowIso: view.server_time, timezone: view.timezone, scratchingOnly: onlyScratchingOpen(view.cat) }),
    [view.server_time, view.timezone, view.cat],
  );

  const c0 = usePieceAnim();
  const c1 = usePieceAnim();
  const c2 = usePieceAnim();
  const c3 = usePieceAnim();
  const c4 = usePieceAnim();
  const c5 = usePieceAnim();
  const anims = useMemo(() => [c0, c1, c2, c3, c4, c5], [c0, c1, c2, c3, c4, c5]);
  const scoopX = useSharedValue(0);
  const scoopY = useSharedValue(0);
  const lastX = useSharedValue(0);
  const lastY = useSharedValue(0);
  const dragging = useSharedValue(false);
  const open = useSharedValue(false);
  const stuck = useSharedValue(0);
  const taken = useSharedValue(0);
  const wave = useSharedValue(0);
  const xsSV = useSharedValue<number[]>([]);
  const ysSV = useSharedValue<number[]>([]);
  const geo = useSharedValue({ homeX: 0, homeY: 0, bagX: 0, bagY: 0, reach: 30, reduce: false });
  const [bagged, setBagged] = useState(0);
  const [stuckJs, setStuckJs] = useState(0);
  const [phase, setPhase] = useState<Phase>({ kind: 'play' });
  const [sparkle, setSparkle] = useState(0);
  const baggedRef = useRef(0);
  const finished = useRef(false);
  const timers = useRef<ReturnType<typeof setTimeout>[]>([]);
  useEffect(() => () => timers.current.forEach(clearTimeout), []);

  useEffect(() => {
    xsSV.value = xs;
    ysSV.value = ys;
    geo.value = { homeX: home.x, homeY: home.y, bagX: bag.x, bagY: bag.y, reach, reduce: reduceMotion };
    if (!dragging.value) {
      scoopX.value = home.x;
      scoopY.value = home.y;
    }
  }, [xs, ys, home.x, home.y, bag.x, bag.y, reach, reduceMotion, xsSV, ysSV, geo, dragging, scoopX, scoopY]);
  useEffect(() => {
    open.value = W > 0 && phase.kind === 'play' && !finished.current;
  }, [W, phase.kind, open]);

  const finish = useCallback(() => {
    finished.current = true;
    open.value = false;
    fx.sound('chime');
    fx.haptic('success');
    setSparkle((n) => n + 1);
    void feedback.current?.show('ok', { label: S.fb, totalMs: 1_000 });
    if (!reduceMotion) {
      wave.value = 0;
      wave.value = withTiming(1, { duration: 800 });
    }
    timers.current.push(
      setTimeout(() => {
        setPhase({ kind: 'saving' });
        mutate(undefined, {
          onSuccess: (response) => setPhase({ kind: 'done', text: response.status === 'accepted' ? SCOOP_STRINGS.success : SCOOP_STRINGS.unchanged }),
          onError: (error) => setPhase({ kind: 'done', text: catFailureText(error, ctx) }),
        });
      }, 1_000),
    );
  }, [open, fx, S, reduceMotion, wave, mutate, ctx]);

  /** JS side of clumps reaching the bag (sounds, progress, the end). */
  const intoBag = useCallback(
    (mask: number) => {
      const fresh = mask & ~baggedRef.current;
      if (fresh === 0 || finished.current) return;
      baggedRef.current |= fresh;
      setBagged(baggedRef.current);
      setStuckJs(0);
      fx.sound('whoosh');
      fx.haptic('tap');
      if (allDone(baggedRef.current, total)) finish();
    },
    [fx, total, finish],
  );
  const onStick = useCallback(
    (mask: number) => {
      setStuckJs(mask);
      fx.sound('pop');
      fx.haptic('tap');
    },
    [fx],
  );

  const handlers = useMemo<GameGestureHandlers>(
    () => ({
      onPanBegin: (e) => {
        'worklet';
        if (!open.value) return;
        dragging.value = true;
        scoopX.value = e.x;
        scoopY.value = e.y - LIFT;
        lastX.value = e.x;
        lastY.value = e.y - LIFT;
      },
      onPanUpdate: (e) => {
        'worklet';
        if (!dragging.value || !open.value) return;
        const x = e.x;
        const y = e.y - LIFT;
        scoopX.value = x;
        scoopY.value = y;
        const picks = scoopPicks(xsSV.value, ysSV.value, taken.value, lastX.value, lastY.value, x, y, geo.value.reach);
        lastX.value = x;
        lastY.value = y;
        if (picks !== 0) {
          taken.value = taken.value | picks;
          stuck.value = stuck.value | picks;
          scheduleOnRN(onStick, stuck.value);
        }
        if (stuck.value !== 0 && isShakeUp(e.velocityY, e.translationY)) {
          // Shake: every clump on the scoop leaves it where it is and flies into the bag.
          const mask = stuck.value;
          const g = geo.value;
          for (let i = 0; i < xsSV.value.length; i++) {
            if ((mask & (1 << i)) === 0) continue;
            const a = anims[i];
            a.tx.value = x + (i % 2 === 0 ? -8 : 8) - xsSV.value[i];
            a.ty.value = y - 6 - ysSV.value[i];
            if (g.reduce) {
              a.opacity.value = 0;
            } else {
              a.tx.value = withTiming(g.bagX - xsSV.value[i], { duration: 420 });
              a.ty.value = withTiming(g.bagY - ysSV.value[i], { duration: 420 });
              a.scale.value = withTiming(0.4, { duration: 420 });
              a.opacity.value = withTiming(0, { duration: 480 });
            }
          }
          stuck.value = 0;
          scheduleOnRN(intoBag, mask);
        }
      },
      onPanEnd: () => {
        'worklet';
        dragging.value = false;
        if (open.value) {
          scoopX.value = withSpring(geo.value.homeX);
          scoopY.value = withSpring(geo.value.homeY);
        }
      },
      onTap: (e) => {
        'worklet';
        if (!open.value) return;
        const i = pieceUnder(xsSV.value, ysSV.value, taken.value, e.x, e.y, geo.value.reach * 1.4);
        if (i < 0) return;
        taken.value = taken.value | (1 << i);
        const g = geo.value;
        const a = anims[i];
        if (g.reduce) {
          a.opacity.value = 0;
        } else {
          a.tx.value = withTiming(g.bagX - xsSV.value[i], { duration: 420 });
          a.ty.value = withTiming(g.bagY - ysSV.value[i], { duration: 420 });
          a.scale.value = withTiming(0.4, { duration: 420 });
          a.opacity.value = withTiming(0, { duration: 480 });
        }
        scheduleOnRN(intoBag, 1 << i);
      },
    }),
    [open, dragging, scoopX, scoopY, lastX, lastY, xsSV, ysSV, taken, stuck, geo, anims, onStick, intoBag],
  );
  const gestures = useGameGestures(view, handlers);
  const easy = gestures.inputMode === 'hold';

  /** Hold mode: the next clump straight into the bag. */
  const scoopNext = () => {
    for (let i = 0; i < total; i++) {
      if (hasBit(taken.value, i)) continue;
      taken.value = taken.value | (1 << i);
      const a = anims[i];
      if (reduceMotion) {
        a.opacity.value = 0;
      } else {
        a.tx.value = withTiming(bag.x - xs[i], { duration: 420 });
        a.ty.value = withTiming(bag.y - ys[i], { duration: 420 });
        a.scale.value = withTiming(0.4, { duration: 420 });
        a.opacity.value = withTiming(0, { duration: 480 });
      }
      intoBag(1 << i);
      return;
    }
  };

  const say = line.say;
  useEffect(() => {
    if (phase.kind !== 'play' || finished.current) say(null);
    else if (stuckJs !== 0) say(S.shakeLine);
    else say(easy ? S.holdLine : S.line);
  }, [phase.kind, stuckJs, easy, say, S]);

  const waveStyle = useAnimatedStyle(() => ({
    opacity: wave.value > 0 && wave.value < 1 ? 0.55 : 0,
    transform: [{ translateX: (wave.value - 0.5) * tray.w }],
  }));

  const scoopW = Math.max(60, W * 0.17);
  const bagW = Math.max(60, W * 0.17);
  const field =
    layout === null ? null : (
      <>
        <View pointerEvents="none" style={[styles.tray, { left: tray.x, top: tray.y, width: tray.w, height: tray.h }]} testID={`${testID}-tray`}>
          <TrayGraphic size={tray.w} clumps={0} step={3} />
          <Animated.View style={[styles.wave, { width: tray.w * 0.4, left: tray.w * 0.3 }, waveStyle]} />
        </View>
        <Target x={bag.x} y={bag.y} size={80} testID={`${testID}-bag`}>
          <BagGraphic size={52} tied={allDone(bagged, total)} />
        </Target>
        {xs.map((x, i) => (
          <Clump key={i} index={i} x={x} y={ys[i]} size={clumpSize} anim={anims[i]} stuck={stuck} scoopX={scoopX} scoopY={scoopY} testID={`${testID}-clump-${i}`} />
        ))}
        <Sprite x={scoopX} y={scoopY} width={scoopW} height={scoopW} testID={`${testID}-scoop`}>
          <ScoopGraphic size={scoopW} />
        </Sprite>
        <MintSparkles at={sparkle > 0 ? { x: tray.x + tray.w / 2, y: tray.y + tray.h / 2 } : null} burst={sparkle} reduceMotion={reduceMotion} />
      </>
    );

  const dock =
    easy && phase.kind === 'play' && !allDone(bagged, total) ? (
      <MessButton label={S.button} a11y={S.button} icon={<Shovel color={palette.graphite} size={22} />} onPress={scoopNext} testID={`${testID}-button`} />
    ) : null;

  const result =
    phase.kind === 'play' ? null : (
      <View style={styles.resultBackdrop} testID={`${testID}-${phase.kind}`}>
        <View style={styles.resultCard}>
          <Text style={styles.resultText} accessibilityLiveRegion="polite" testID={`${testID}-result`}>
            {phase.kind === 'saving' ? SCOOP_STRINGS.saving : phase.text}
          </Text>
          {phase.kind === 'done' && messNearby && <Text style={styles.note}>{SCOOP_STRINGS.messNearby}</Text>}
          {phase.kind === 'done' && <PrimaryButton label={SCOOP_STRINGS.done} onPress={onClose} testID={`${testID}-done-button`} />}
        </View>
      </View>
    );

  const dots = Array.from({ length: total }, (_, i) => hasBit(bagged, i));
  return (
    <GameScreen
      testID={testID}
      onExit={onClose}
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
              closeLabel={MESS_GAME_STRINGS.close}
              progressLabel={MESS_GAME_STRINGS.a11y.progress(countBits(bagged), total)}
              onClose={onClose}
              closeLocked={phase.kind === 'saving'}
              testID={`${testID}-top`}
            />
          }
        />
      }
      dock={dock}
      overlay={result}
    />
  );
}

const styles = StyleSheet.create({
  tray: { position: 'absolute', overflow: 'hidden' },
  wave: { position: 'absolute', top: '40%', height: '36%', borderRadius: 999, backgroundColor: alpha(palette.white, 0.6) },
  clump: { position: 'absolute', left: 0, top: 0 },
  resultBackdrop: { ...StyleSheet.absoluteFill, backgroundColor: alpha(palette.graphite, 0.72), alignItems: 'center', justifyContent: 'center', padding: 24 },
  resultCard: {
    alignSelf: 'stretch',
    alignItems: 'stretch',
    gap: 16,
    paddingVertical: 28,
    paddingHorizontal: 20,
    borderRadius: 28,
    backgroundColor: alpha(palette.graphite, 0.9),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.12),
  },
  resultText: { color: palette.white, fontSize: 22, letterSpacing: tightTracking(22), fontFamily: fonts.displayBold, textAlign: 'center' },
  note: { color: palette.n200, fontSize: 15, textAlign: 'center' },
});
