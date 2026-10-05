/**
 * Registry of the fake expo-video players created by `__mocks__/expo-video.tsx`
 * (tests only). Every `useVideoPlayer()` call creates one `MockVideoPlayer`; tests
 * inspect them (source, play / pause, released) and emit player events.
 */

export type MockPlayerEvent = 'statusChange' | 'playingChange' | 'sourceLoad';

export interface MockVideoPlayer {
  source: unknown;
  loop: boolean;
  muted: boolean;
  playing: boolean;
  released: boolean;
  play: jest.Mock<void, []>;
  pause: jest.Mock<void, []>;
  replace: jest.Mock<void, [unknown]>;
  replaceAsync: jest.Mock<Promise<void>, [unknown]>;
  release: jest.Mock<void, []>;
  addListener: (event: MockPlayerEvent, listener: (payload: unknown) => void) => { remove: () => void };
  /** Test helper: deliver an event to the listeners. */
  emit: (event: MockPlayerEvent, payload: unknown) => void;
}

export const mockVideoPlayers: MockVideoPlayer[] = [];

export function createMockVideoPlayer(source: unknown): MockVideoPlayer {
  const listeners = new Map<MockPlayerEvent, Set<(payload: unknown) => void>>();
  const player: MockVideoPlayer = {
    source,
    loop: false,
    muted: false,
    playing: false,
    released: false,
    play: jest.fn(() => {
      player.playing = true;
    }),
    pause: jest.fn(() => {
      player.playing = false;
    }),
    replace: jest.fn((next: unknown) => {
      player.source = next;
    }),
    replaceAsync: jest.fn(async (next: unknown) => {
      player.source = next;
    }),
    release: jest.fn(() => {
      player.released = true;
      player.playing = false;
    }),
    addListener: (event, listener) => {
      const set = listeners.get(event) ?? new Set();
      set.add(listener);
      listeners.set(event, set);
      return { remove: () => set.delete(listener) };
    },
    emit: (event, payload) => {
      listeners.get(event)?.forEach((l) => l(payload));
    },
  };
  mockVideoPlayers.push(player);
  return player;
}

export function resetMockVideoPlayers(): void {
  mockVideoPlayers.length = 0;
}

/** Players not yet released (= mounted). */
export function liveVideoPlayers(): MockVideoPlayer[] {
  return mockVideoPlayers.filter((p) => !p.released);
}
