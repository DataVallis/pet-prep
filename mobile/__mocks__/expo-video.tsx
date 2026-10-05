/**
 * Manual Jest mock for expo-video (native module, not available in Jest).
 * `useVideoPlayer` behaves like the real hook (SDK 57 `useReleasingSharedObject`): one
 * fake player per hook instance and source — a different source (by JSON value)
 * creates a new player and releases the previous one; unmount releases it. `VideoView`
 * renders a View that keeps its props, so tests can `fireEvent(view, 'firstFrameRender')`.
 * Inspect players via `@/test-utils/videoPlayers`.
 */

import { useEffect, useMemo, type ReactElement } from 'react';
import { View, type ViewProps } from 'react-native';

import { createMockVideoPlayer, type MockVideoPlayer } from '@/test-utils/videoPlayers';

export function useVideoPlayer(source: unknown, setup?: (player: MockVideoPlayer) => void): MockVideoPlayer {
  const sourceKey = JSON.stringify(source ?? null);
  const player = useMemo(() => {
    const created = createMockVideoPlayer(source);
    setup?.(created);
    return created;
    // Recreated only when the source value changes, like the real hook.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sourceKey]);
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
