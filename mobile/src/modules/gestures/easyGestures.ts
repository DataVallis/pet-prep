/**
 * M5-T17 »Lažje kretnje« / "Easier gestures" (APP_REFINEMENT_SPEC §6 / §11, David 2026-10-23).
 *
 * Which input the child's swipe games offer:
 * - `swipe` (default): swipe only — no hold on the stage, no hold-only buttons.
 * - `hold`: press-and-hold / a button instead of swiping, in every swipe game (brushing,
 *   cat wand play, litter change, dog ball play).
 *
 * `hold` when the parent turned the switch on for this child (server
 * `settings.easy_gestures`, per child profile, default false) OR a screen reader
 * (VoiceOver / TalkBack) is running — then always, whatever the switch says. The games keep
 * their accessibility actions whenever hold mode is active.
 *
 * Hold only changes HOW the child triggers the same events: the moves / strokes / throws,
 * their timing rules and what is sent to the server stay exactly the same.
 */

import { useEffect, useState } from 'react';
import { AccessibilityInfo } from 'react-native';

export type GestureMode = 'swipe' | 'hold';

/** Any payload value → the switch; missing / unknown (older server) → off. */
export function readEasyGestures(value: unknown): boolean {
  return value === true;
}

/** The pure rule: the parent's switch, or a running screen reader → hold. */
export function gestureModeFor(easyGestures: boolean, screenReader: boolean): GestureMode {
  return easyGestures || screenReader ? 'hold' : 'swipe';
}

/** The child view's switch (null / missing → off). */
export function easyGesturesOf(view: { settings?: { easy_gestures?: boolean } } | null | undefined): boolean {
  return view?.settings?.easy_gestures === true;
}

/**
 * VoiceOver / TalkBack on, live: `AccessibilityInfo.isScreenReaderEnabled()` + the
 * `screenReaderChanged` event. Starts false and never throws.
 */
export function useScreenReader(): boolean {
  const [enabled, setEnabled] = useState(false);
  useEffect(() => {
    let alive = true;
    Promise.resolve(AccessibilityInfo.isScreenReaderEnabled?.())
      .then((value) => {
        // Only a change re-renders (the default is off).
        if (alive && value === true) setEnabled(true);
      })
      .catch(() => undefined);
    const subscription = AccessibilityInfo.addEventListener?.('screenReaderChanged', (value: boolean) => {
      if (alive) setEnabled(value === true);
    });
    return () => {
      alive = false;
      subscription?.remove();
    };
  }, []);
  return enabled;
}

/** The gesture mode of the games for this child view (switch + live screen reader). */
export function useGestureMode(view: { settings?: { easy_gestures?: boolean } } | null | undefined): GestureMode {
  const screenReader = useScreenReader();
  return gestureModeFor(easyGesturesOf(view), screenReader);
}
