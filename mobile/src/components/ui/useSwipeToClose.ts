/**
 * Swipe-down-to-close for a bottom sheet (the same thresholds as the »Ali si vedel?« sheet,
 * FactSheet). Attach `panHandlers` to the sheet's header (handle + title), not to a scrolling
 * list, so a scroll never fights the drag; translate the sheet by `dragY`.
 */

import { useRef } from 'react';
import { Animated, PanResponder, type GestureResponderHandlers } from 'react-native';

/** Drag distance (pt) or downward speed that closes the sheet. */
export const SWIPE_CLOSE_DY = 80;
export const SWIPE_CLOSE_VY = 0.8;

/** Does a released downward drag close the sheet? (pure) */
export function shouldCloseOnSwipe(dy: number, vy: number): boolean {
  return dy > SWIPE_CLOSE_DY || vy > SWIPE_CLOSE_VY;
}

/** Does a move start the drag? Mostly vertical and downward. (pure) */
export function startsSwipe(dx: number, dy: number): boolean {
  return dy > 8 && Math.abs(dy) > Math.abs(dx);
}

export function useSwipeToClose(onClose: () => void): { dragY: Animated.Value; panHandlers: GestureResponderHandlers } {
  const dragY = useRef(new Animated.Value(0)).current;
  const closeRef = useRef(onClose);
  closeRef.current = onClose;
  const pan = useRef(
    PanResponder.create({
      onMoveShouldSetPanResponder: (_e, g) => startsSwipe(g.dx, g.dy),
      onPanResponderMove: (_e, g) => dragY.setValue(Math.max(0, g.dy)),
      onPanResponderRelease: (_e, g) => {
        if (shouldCloseOnSwipe(g.dy, g.vy)) {
          dragY.setValue(0);
          closeRef.current();
          return;
        }
        Animated.spring(dragY, { toValue: 0, useNativeDriver: true }).start();
      },
      onPanResponderTerminate: () => dragY.setValue(0),
    }),
  ).current;
  return { dragY, panHandlers: pan.panHandlers };
}
