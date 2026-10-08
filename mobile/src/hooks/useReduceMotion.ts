/**
 * The system "reduce motion" setting (iOS Reduce Motion / Android "Remove animations"),
 * live: `AccessibilityInfo.isReduceMotionEnabled()` + the `reduceMotionChanged` event.
 * Starts as false and never throws (a runtime without the API just keeps false).
 */

import { useEffect, useState } from 'react';
import { AccessibilityInfo } from 'react-native';

export function useReduceMotion(): boolean {
  const [reduced, setReduced] = useState(false);
  useEffect(() => {
    let alive = true;
    AccessibilityInfo.isReduceMotionEnabled?.()
      .then((value) => {
        if (alive) setReduced(value === true);
      })
      .catch(() => undefined);
    const subscription = AccessibilityInfo.addEventListener?.('reduceMotionChanged', (value: boolean) => {
      if (alive) setReduced(value === true);
    });
    return () => {
      alive = false;
      subscription?.remove();
    };
  }, []);
  return reduced;
}
