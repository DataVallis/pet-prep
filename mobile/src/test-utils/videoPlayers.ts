/**
 * Registry of the fake expo-video players created by `__mocks__/expo-video.tsx`
 * (tests only). Every `useVideoPlayer()` call creates one `MockVideoPlayer`; tests
 * inspect them (source, play / pause, released) and emit player events.
 */

export type MockPlayerEvent = 'statusChange' | 'playingChange' | 'sourceLoad';

export type MockPlayerStatus = 'idle' | 'loading' | 'readyToPlay' | 'error';

export interface MockVideoPlayer {
  source: unknown;
  /** Like the real player: the last status (statusChange payloads update it). */
  status: MockPlayerStatus;
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
    status: 'idle',
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
      if (event === 'statusChange' && typeof payload === 'object' && payload !== null && 'status' in payload) {
        player.status = (payload as { status: MockPlayerStatus }).status;
      }
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

/** URI a player was created with (string sources or `{ uri }` objects). */
export function sourceUri(player: MockVideoPlayer): string | null {
  const s = player.source;
  if (typeof s === 'string') return s;
  if (typeof s === 'object' && s !== null && 'uri' in s) {
    const uri = (s as { uri: unknown }).uri;
    return typeof uri === 'string' ? uri : null;
  }
  return null;
}

/** URIs of all players created so far, in order. */
export function playerUris(): Array<string | null> {
  return mockVideoPlayers.map(sourceUri);
}
