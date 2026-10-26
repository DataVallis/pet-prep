/**
 * Drives a PanResponder's handlers (as the RN responder system would) for a vertical drag of
 * `dy` pt on `element` — used for the HUD top stack's swipe up / down (David 2026-10-26).
 */
import { act } from '@testing-library/react-native';

interface ResponderProps {
  onStartShouldSetResponderCapture?: (e: unknown) => boolean;
  onMoveShouldSetResponderCapture?: (e: unknown) => boolean;
  onResponderGrant?: (e: unknown) => void;
  onResponderMove?: (e: unknown) => void;
  onResponderRelease?: (e: unknown) => void;
}

function touchEvent(previousY: number, currentY: number, timestamp: number) {
  return {
    nativeEvent: { touches: [{}], changedTouches: [{}], pageX: 100, pageY: 100 + currentY, timestamp },
    touchHistory: {
      numberActiveTouches: 1,
      indexOfSingleActiveTouch: 0,
      mostRecentTimeStamp: timestamp,
      touchBank: [
        {
          touchActive: true,
          startPageX: 100,
          startPageY: 100,
          startTimeStamp: 0,
          currentPageX: 100,
          currentPageY: 100 + currentY,
          currentTimeStamp: timestamp,
          previousPageX: 100,
          previousPageY: 100 + previousY,
          previousTimeStamp: timestamp - 16,
        },
      ],
    },
  };
}

/** Every gesture gets later timestamps (PanResponder ignores a move it already accounted for). */
let clock = 0;

/** Returns whether the element claimed the drag. */
export function verticalSwipe(element: { props: ResponderProps }, dy: number): boolean {
  const props = element.props;
  const half = dy / 2;
  clock += 1_000;
  const t0 = clock;
  // A new touch resets the PanResponder's gesture state (as on a device).
  props.onStartShouldSetResponderCapture?.(touchEvent(0, 0, t0 - 10));
  const claimed = props.onMoveShouldSetResponderCapture?.(touchEvent(0, half, t0)) ?? false;
  if (!claimed) return false;
  act(() => {
    props.onResponderGrant?.(touchEvent(0, half, t0));
    props.onResponderMove?.(touchEvent(half, dy, t0 + 50));
    props.onResponderRelease?.(touchEvent(dy, dy, t0 + 70));
  });
  return true;
}
