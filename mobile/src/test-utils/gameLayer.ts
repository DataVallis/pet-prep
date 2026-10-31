/**
 * Test helpers for the GAMES_SPEC game layer: typed access to the manual mocks in
 * `mobile/__mocks__/` (expo-audio's fake players, the gesture-handler mock's recorded worklets).
 */
import { BackHandler } from 'react-native';
import { act, screen } from '@testing-library/react-native';

import { expectPaintedAbove } from '@/test-utils/fabricPaintOrder';
import type * as AudioMock from '../../__mocks__/expo-audio';
import type * as GestureMock from '../../__mocks__/react-native-gesture-handler';

/** Every fake `expo-audio` player created since the last reset. */
export function fakeAudioPlayers(): AudioMock.FakeAudioPlayer[] {
  return jest.requireMock<typeof AudioMock>('expo-audio').audioPlayers;
}

export function resetFakeAudioPlayers(): void {
  fakeAudioPlayers().length = 0;
}

/** Fire the recorded pan / tap worklets of the GestureDetector whose child has `testID`. */
export function fireGameGesture(testID: string, kind: 'pan' | 'tap', event: Record<string, number>): void {
  jest.requireMock<typeof GestureMock>('react-native-gesture-handler').fireGameGesture(testID, kind, event);
}

/** Fire the recorded pan of `testID` as a drag through `samples` (begin → update each → end). */
export function fireGamePan(testID: string, samples: Record<string, number>[]): void {
  jest.requireMock<typeof GestureMock>('react-native-gesture-handler').fireGamePan(testID, samples);
}

// ── Exit + z-order (ball v2 device bug, 2026-10-31) ─────────────────────────────

type BackPressHandler = Parameters<typeof BackHandler.addEventListener>[1];

/**
 * Capture the Android back handlers (RN's Jest BackHandler never fires). Call in a test before
 * rendering; `pressBack()` runs them newest first until one returns true, like the native module.
 */
export function captureAndroidBack(): { pressBack: () => boolean } {
  const handlers: BackPressHandler[] = [];
  jest.spyOn(BackHandler, 'addEventListener').mockImplementation((_event, handler) => {
    handlers.push(handler);
    return {
      remove: () => {
        const at = handlers.indexOf(handler);
        if (at !== -1) handlers.splice(at, 1);
      },
    };
  });
  return {
    pressBack: () => {
      let consumed = false;
      act(() => {
        for (const h of [...handlers].reverse()) {
          if (h({} as Parameters<BackPressHandler>[0]) === true) {
            consumed = true;
            return;
          }
        }
      });
      return consumed;
    },
  };
}

/**
 * The game's HUD is painted ABOVE its video stage by Fabric's rules (`fabricPaintOrder`): the
 * top bar and its ×, plus every extra testID given (field, dock buttons, feedback, result card).
 */
export function expectHudAboveStage(gameTestID: string, extra: readonly string[] = []): void {
  const opts = { includeHiddenElements: true };
  const root = screen.getByTestId(gameTestID, opts);
  const stage = screen.getByTestId(`${gameTestID}-stage-layer`, opts);
  const above = [`${gameTestID}-top-layer`, ...extra].map((id) => screen.getByTestId(id, opts));
  expectPaintedAbove(root, stage, above);
}
