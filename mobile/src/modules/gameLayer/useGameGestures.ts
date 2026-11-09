/**
 * `useGameGestures` — the touch input of a game (GAMES_SPEC §7.1): `react-native-gesture-handler`
 * Pan + Tap running on the UI thread (worklet callbacks, no JS round trip while dragging), and the
 * input mode the game's buttons follow:
 *
 * - `inputMode` = `useGestureMode(view)` (M5-T17 `easyGestures.ts`): `hold` when the parent's
 *   »Lažje kretnje« is on OR a screen reader runs → the game ALSO shows labelled buttons (hold to
 *   charge, tap to call / catch); `swipe` → gestures only. The gestures stay on in both modes.
 * - `screenReader` separately (VoiceOver / TalkBack can't pan the canvas — buttons only).
 *
 * Callbacks must be worklets (`'worklet'`); hand results to JS with `scheduleOnRN`.
 */

import { useMemo } from 'react';
import { Gesture, type ComposedGesture, type GestureStateChangeEvent, type GestureUpdateEvent, type PanGestureHandlerEventPayload, type TapGestureHandlerEventPayload } from 'react-native-gesture-handler';

import { useGestureMode, useScreenReader, type GestureMode } from '@/modules/gestures/easyGestures';

export interface GameGestureHandlers {
  onPanBegin?: (e: GestureStateChangeEvent<PanGestureHandlerEventPayload>) => void;
  onPanUpdate?: (e: GestureUpdateEvent<PanGestureHandlerEventPayload>) => void;
  onPanEnd?: (e: GestureStateChangeEvent<PanGestureHandlerEventPayload>) => void;
  onTap?: (e: GestureStateChangeEvent<TapGestureHandlerEventPayload>) => void;
  /**
   * The finger lifted (or the touch was cancelled) after ANY touch on the field — a drag, a tap,
   * or a finger that rested without moving (the pan never activated, so `onPanEnd` / `onTap` do
   * not fire for it). The wand hunt's »let go« (David 2026-11-08).
   */
  onPanFinalize?: (e: GestureStateChangeEvent<PanGestureHandlerEventPayload>) => void;
}

export interface GameGestures {
  gesture: ComposedGesture;
  inputMode: GestureMode;
  screenReader: boolean;
}

export function useGameGestures(view: { settings?: { easy_gestures?: boolean } } | null | undefined, handlers: GameGestureHandlers): GameGestures {
  const inputMode = useGestureMode(view);
  const screenReader = useScreenReader();
  const { onPanBegin, onPanUpdate, onPanEnd, onTap, onPanFinalize } = handlers;
  const gesture = useMemo(() => {
    const pan = Gesture.Pan()
      .minDistance(6)
      .onBegin((e) => {
        'worklet';
        onPanBegin?.(e);
      })
      .onUpdate((e) => {
        'worklet';
        onPanUpdate?.(e);
      })
      .onEnd((e) => {
        'worklet';
        onPanEnd?.(e);
      })
      .onFinalize((e) => {
        'worklet';
        onPanFinalize?.(e);
      });
    const tap = Gesture.Tap()
      .maxDuration(600)
      .onEnd((e) => {
        'worklet';
        onTap?.(e);
      });
    return Gesture.Exclusive(pan, tap);
  }, [onPanBegin, onPanUpdate, onPanEnd, onTap, onPanFinalize]);
  return { gesture, inputMode, screenReader };
}
