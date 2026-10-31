/**
 * PlayField — the game's physical objects over the pet video (GAMES_SPEC §7.1): one
 * `@shopify/react-native-skia` canvas, drawn on the UI thread from reanimated shared values
 * (60 fps, no React render per frame). The game puts its sprites in (`BallSprite`, later the
 * feather, treat, hearts, paw); the field gives them its size (`size` shared value, pt) and
 * wraps the canvas in the game's gesture (`useGameGestures`).
 *
 * The canvas itself is never an accessibility element — every action also exists as a
 * labelled button (»Lažje kretnje« / screen reader, `useGameGestures` → `inputMode`).
 */

import { type ReactNode } from 'react';
import { StyleSheet, View, type LayoutChangeEvent } from 'react-native';
import { Canvas } from '@shopify/react-native-skia';
import { GestureDetector, type GestureType, type ComposedGesture } from 'react-native-gesture-handler';
import { useSharedValue, type SharedValue } from 'react-native-reanimated';

export interface FieldSizeValue {
  width: number;
  height: number;
}

/** The field's size as a shared value (0 × 0 until laid out). */
export function useFieldSize(): SharedValue<FieldSizeValue> {
  return useSharedValue<FieldSizeValue>({ width: 0, height: 0 });
}

export interface PlayFieldProps {
  size: SharedValue<FieldSizeValue>;
  gesture: GestureType | ComposedGesture;
  children: ReactNode;
  testID?: string;
}

export default function PlayField({ size, gesture, children, testID = 'play-field' }: PlayFieldProps) {
  const onLayout = (e: LayoutChangeEvent) => {
    const { width, height } = e.nativeEvent.layout;
    size.value = { width, height };
  };
  return (
    <GestureDetector gesture={gesture}>
      <View style={StyleSheet.absoluteFill} onLayout={onLayout} testID={testID} importantForAccessibility="no-hide-descendants" accessibilityElementsHidden>
        <Canvas style={StyleSheet.absoluteFill} pointerEvents="none">
          {children}
        </Canvas>
      </View>
    </GestureDetector>
  );
}
