/**
 * 2026-11-27: game sounds never deactivate the shared iOS audio session at their end
 * (`keepAudioSessionActive`) — that stopped the pet's video players (home loop frozen after play).
 */
import { renderHook } from '@testing-library/react-native';
import { createAudioPlayer } from 'expo-audio';

import { useGameFx } from '@/modules/gameLayer/useGameFx';

const createMock = createAudioPlayer as jest.MockedFunction<typeof createAudioPlayer>;

beforeEach(() => createMock.mockClear());

it('every sound player keeps the audio session active', () => {
  renderHook(() => useGameFx(true, ['chime', 'pop']));
  expect(createMock).toHaveBeenCalledTimes(2);
  for (const call of createMock.mock.calls) expect(call[1]).toEqual({ keepAudioSessionActive: true });
});

it('sounds off → no player', () => {
  renderHook(() => useGameFx(false, ['chime']));
  expect(createMock).not.toHaveBeenCalled();
});
