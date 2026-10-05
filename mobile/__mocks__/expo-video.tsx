/**
 * Manual Jest mock for expo-video (native module, not available in Jest).
 * `useVideoPlayer` creates one fake player per hook instance (released on unmount,
 * source changes go through `replace` like the real hook); `VideoView` renders a View
 * that keeps its props, so tests can `fireEvent(view, 'firstFrameRender')`.
 * Inspect players via `@/test-utils/videoPlayers`.
 */

import { useEffect, useRef, type ReactElement } from 'react';
import { View, type ViewProps } from 'react-native';

import { createMockVideoPlayer, type MockVideoPlayer } from '@/test-utils/videoPlayers';

export function useVideoPlayer(source: unknown, setup?: (player: MockVideoPlayer) => void): MockVideoPlayer {
  const ref = useRef<MockVideoPlayer | null>(null);
  if (ref.current === null) {
    ref.current = createMockVideoPlayer(source);
    setup?.(ref.current);
  }
  const player = ref.current;
  const lastSource = useRef<unknown>(source);
  useEffect(() => {
    if (lastSource.current !== source) {
      lastSource.current = source;
      player.replace(source);
    }
  }, [player, source]);
  useEffect(() => () => player.release(), [player]);
  return player;
}

export function createVideoPlayer(source: unknown): MockVideoPlayer {
  return createMockVideoPlayer(source);
}

type MockVideoViewProps = ViewProps & { player: MockVideoPlayer; onFirstFrameRender?: () => void };

export function VideoView(props: MockVideoViewProps): ReactElement {
  return <View {...props} />;
}

export const VideoContent = 'VideoContent';
export const VideoAirPlayButton = 'VideoAirPlayButton';
export const isPictureInPictureSupported = jest.fn(() => false);
export const clearVideoCacheAsync = jest.fn(async () => undefined);
export const setVideoCacheSizeAsync = jest.fn(async () => undefined);
export const getCurrentVideoCacheSize = jest.fn(() => 0);
