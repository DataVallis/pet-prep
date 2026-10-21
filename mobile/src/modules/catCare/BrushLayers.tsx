/**
 * The brushing layers on top of the grooming stage (M5-T14, APP_REFINEMENT_SPEC §11):
 * "dirty" patches that fade as they are brushed (scratch card), a brush that follows the
 * finger and a few sparkles where the coat is brushed.
 *
 * Performance (mid-range Android): the finger never causes a React render — the brush and
 * the sparkles are `Animated` values set imperatively through `BrushFx` (refs), sparkles come
 * from a fixed pool of `PARTICLE_POOL` views, spawn at most every `PARTICLE_MIN_MS` /
 * `PARTICLE_MIN_PT` and animate on the native driver. Patches re-render only when a stroke
 * counts (≥ 150 ms apart). Reduce motion: no sparkles, patches change without a fade.
 */

import { useEffect, useMemo, useRef } from 'react';
import { Animated, StyleSheet, View } from 'react-native';
import { Brush, Sparkles } from 'lucide-react-native';

import { PARTICLE_POOL, shouldSpawnParticle, type BrushPatch } from '@/modules/catCare/brushing';
import type { Point } from '@/modules/catCare/catGames';
import { alpha, palette } from '@/theme';

const BRUSH_SIZE = 44;
const SPARK_SIZE = 16;
const SPARK_MS = 560;
const PATCH_FADE_MS = 260;
/** Opacity of an untouched patch. */
const PATCH_MAX_OPACITY = 0.9;

interface Spark {
  x: Animated.Value;
  y: Animated.Value;
  o: Animated.Value;
}

/** Imperative handle: what the touch handlers call (never a setState). */
export interface BrushFx {
  /** Finger down / moved (stage pt): move the brush, maybe spawn a sparkle. */
  move: (p: Point, t: number) => void;
  /** Finger up: hide the brush. */
  lift: () => void;
}

export function useBrushFx(reduceMotion: boolean): { fx: BrushFx; brush: { pos: Animated.ValueXY; visible: Animated.Value }; sparks: Spark[] } {
  const pos = useRef(new Animated.ValueXY({ x: 0, y: 0 })).current;
  const visible = useRef(new Animated.Value(0)).current;
  const sparks = useRef<Spark[]>(
    Array.from({ length: PARTICLE_POOL }, () => ({ x: new Animated.Value(0), y: new Animated.Value(0), o: new Animated.Value(0) })),
  ).current;
  const next = useRef(0);
  const last = useRef<{ t: number; p: Point } | null>(null);
  const reduceRef = useRef(reduceMotion);
  reduceRef.current = reduceMotion;

  const fx = useMemo<BrushFx>(
    () => ({
      move: (p, t) => {
        pos.setValue({ x: p.x - BRUSH_SIZE / 2, y: p.y - BRUSH_SIZE / 2 });
        visible.setValue(1);
        if (reduceRef.current || !shouldSpawnParticle(last.current, t, p)) return;
        last.current = { t, p };
        const spark = sparks[next.current % sparks.length];
        next.current += 1;
        const dx = ((next.current * 37) % 21) - 10;
        spark.x.setValue(p.x - SPARK_SIZE / 2 + dx);
        spark.y.setValue(p.y - SPARK_SIZE / 2);
        spark.o.setValue(1);
        Animated.parallel([
          Animated.timing(spark.o, { toValue: 0, duration: SPARK_MS, useNativeDriver: true }),
          Animated.timing(spark.y, { toValue: p.y - SPARK_SIZE / 2 - 28, duration: SPARK_MS, useNativeDriver: true }),
        ]).start();
      },
      lift: () => {
        visible.setValue(0);
        last.current = null;
      },
    }),
    [pos, visible, sparks],
  );
  return { fx, brush: { pos, visible }, sparks };
}

export interface BrushLayersProps {
  patches: readonly BrushPatch[];
  /** Dirt left per patch (0 clean … 1 untouched), index = patch id. */
  dirt: readonly number[];
  size: { width: number; height: number };
  brush: { pos: Animated.ValueXY; visible: Animated.Value };
  sparks: readonly Spark[];
  reduceMotion: boolean;
  testID: string;
}

/** Patches, sparkles and the brush — absolutely positioned, never touchable, hidden from a11y. */
export function BrushLayers({ patches, dirt, size, brush, sparks, reduceMotion, testID }: BrushLayersProps) {
  const opacities = useRef<Animated.Value[]>([]);
  if (opacities.current.length !== patches.length) {
    opacities.current = patches.map((p) => new Animated.Value(PATCH_MAX_OPACITY * (dirt[p.id] ?? 1)));
  }
  const dirtKey = dirt.join(',');
  useEffect(() => {
    patches.forEach((p, i) => {
      const value = opacities.current[i];
      const toValue = PATCH_MAX_OPACITY * (dirt[p.id] ?? 1);
      if (reduceMotion) value.setValue(toValue);
      else Animated.timing(value, { toValue, duration: PATCH_FADE_MS, useNativeDriver: true }).start();
    });
    // `dirtKey` carries `dirt` (a new array every render).
  }, [dirtKey, patches, reduceMotion]);

  const side = Math.min(size.width, size.height);
  const ready = size.width > 0 && size.height > 0;

  return (
    <View style={StyleSheet.absoluteFill} pointerEvents="none" accessible={false} importantForAccessibility="no-hide-descendants" accessibilityElementsHidden>
      {ready &&
        patches.map((p, i) => {
          const d = Math.round(p.r * side * 2);
          return (
            <Animated.View
              key={p.id}
              testID={`${testID}-patch-${p.id}`}
              style={[layerStyles.patch, { width: d, height: d, borderRadius: d / 2, left: p.x * size.width - d / 2, top: p.y * size.height - d / 2, opacity: opacities.current[i] }]}
            >
              <View style={[layerStyles.speck, { width: d * 0.32, height: d * 0.32, borderRadius: d * 0.16, left: d * 0.18, top: d * 0.22 }]} />
              <View style={[layerStyles.speck, { width: d * 0.22, height: d * 0.22, borderRadius: d * 0.11, left: d * 0.58, top: d * 0.52 }]} />
            </Animated.View>
          );
        })}
      {!reduceMotion &&
        sparks.map((s, i) => (
          <Animated.View key={i} style={[layerStyles.spark, { opacity: s.o, transform: [{ translateX: s.x }, { translateY: s.y }] }]}>
            <Sparkles color={palette.mint} size={SPARK_SIZE} />
          </Animated.View>
        ))}
      <Animated.View style={[layerStyles.brush, { opacity: brush.visible, transform: brush.pos.getTranslateTransform() }]} testID={`${testID}-brush`}>
        <Brush color={palette.graphite} size={24} />
      </Animated.View>
    </View>
  );
}

const layerStyles = StyleSheet.create({
  patch: {
    position: 'absolute',
    backgroundColor: alpha(palette.n500, 0.78),
    borderWidth: 1,
    borderColor: alpha(palette.n300, 0.35),
  },
  speck: { position: 'absolute', backgroundColor: alpha(palette.n700, 0.7) },
  spark: { position: 'absolute', left: 0, top: 0 },
  brush: {
    position: 'absolute',
    left: 0,
    top: 0,
    width: BRUSH_SIZE,
    height: BRUSH_SIZE,
    borderRadius: BRUSH_SIZE / 2,
    backgroundColor: alpha(palette.mint, 0.92),
    alignItems: 'center',
    justifyContent: 'center',
  },
});
