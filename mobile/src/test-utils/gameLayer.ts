/**
 * Test helpers for the GAMES_SPEC game layer: typed access to the manual mocks in
 * `mobile/__mocks__/` (expo-audio's fake players, the gesture-handler mock's recorded worklets).
 */
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
