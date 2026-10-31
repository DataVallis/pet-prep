/**
 * School v2 token: tap OR drag (David's device test 2026-10-31 — he tried to drag the treat to the
 * dog). Wraps the token's `Pressable`, which keeps counting the praise at its press-in (touch-down
 * time = the scored time, first tap per cue decides) and keeps the screen-reader `onPress`.
 *
 * This wrapper only adds the drag: once the finger moved `TOKEN_DRAG_START_PT` it takes the touch
 * over (responder capture — the Pressable's press is cancelled, its press-in already counted), the
 * token follows the finger, and on release it snaps home while `onDragEnd(dx, dy)` lets the game
 * fly the treat from there to the dog. A drag never reports a tap (no `game.praise` here), so it
 * can neither count twice nor shift the counted time.
 *
 * Imports reanimated → reached only through `SchoolGameV2` (lazy, behind `gameLayerAvailable()`).
 */

import { useCallback, useRef, type ReactNode } from 'react';
import { View, type GestureResponderEvent } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withTiming } from 'react-native-reanimated';

import { isTokenDrag } from '@/modules/training/schoolV2/schoolV2';

const SNAP_BACK_MS = 160;

export interface TokenDragProps {
  /** A drag took the touch over (the Pressable's press-out that follows is not a tap release). */
  onDragStart: () => void;
  /** The drag ended (release, or the touch was taken away) at this offset from the token's home. */
  onDragEnd: (dx: number, dy: number) => void;
  reduceMotion: boolean;
  children: ReactNode;
  testID?: string;
}

export default function TokenDrag({ onDragStart, onDragEnd, reduceMotion, children, testID }: TokenDragProps) {
  const start = useRef<{ x: number; y: number } | null>(null);
  const dragging = useRef(false);
  const tx = useSharedValue(0);
  const ty = useSharedValue(0);
  const style = useAnimatedStyle(() => ({ transform: [{ translateX: tx.value }, { translateY: ty.value }] }));

  const offset = (e: GestureResponderEvent): { dx: number; dy: number } => {
    const from = start.current;
    if (from === null) return { dx: 0, dy: 0 };
    return { dx: e.nativeEvent.pageX - from.x, dy: e.nativeEvent.pageY - from.y };
  };

  const end = useCallback(
    (dx: number, dy: number) => {
      if (!dragging.current) return;
      dragging.current = false;
      start.current = null;
      // The flying treat takes over from where the finger let go; the token itself goes home.
      tx.value = reduceMotion ? 0 : withTiming(0, { duration: SNAP_BACK_MS });
      ty.value = reduceMotion ? 0 : withTiming(0, { duration: SNAP_BACK_MS });
      onDragEnd(dx, dy);
    },
    [onDragEnd, reduceMotion, tx, ty],
  );

  return (
    <Animated.View style={style} testID={testID}>
      <View
        collapsable={false}
        testID={testID === undefined ? undefined : `${testID}-surface`}
        onTouchStart={(e) => {
          start.current = { x: e.nativeEvent.pageX, y: e.nativeEvent.pageY };
          dragging.current = false;
        }}
        onMoveShouldSetResponderCapture={(e) => {
          if (dragging.current) return true;
          const { dx, dy } = offset(e);
          if (start.current === null || !isTokenDrag(dx, dy)) return false;
          dragging.current = true;
          onDragStart();
          return true;
        }}
        onResponderTerminationRequest={() => false}
        onResponderMove={(e) => {
          if (!dragging.current) return;
          const { dx, dy } = offset(e);
          tx.value = dx;
          ty.value = dy;
        }}
        onResponderRelease={(e) => {
          const { dx, dy } = offset(e);
          end(dx, dy);
        }}
        onResponderTerminate={(e) => {
          const { dx, dy } = offset(e);
          end(dx, dy);
        }}
      >
        {children}
      </View>
    </Animated.View>
  );
}
