/** Jest mock of expo-audio: one fake player per `createAudioPlayer` call (see `audioPlayers`). */
export interface FakeAudioPlayer {
  source: unknown;
  volume: number;
  play: jest.Mock;
  pause: jest.Mock;
  seekTo: jest.Mock;
  remove: jest.Mock;
}

export const audioPlayers: FakeAudioPlayer[] = [];

export const createAudioPlayer = jest.fn((source: unknown): FakeAudioPlayer => {
  const player: FakeAudioPlayer = {
    source,
    volume: 1,
    play: jest.fn(),
    pause: jest.fn(),
    seekTo: jest.fn(() => Promise.resolve()),
    remove: jest.fn(),
  };
  audioPlayers.push(player);
  return player;
});

export const setAudioModeAsync = jest.fn(() => Promise.resolve());
