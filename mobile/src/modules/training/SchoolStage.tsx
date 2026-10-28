/**
 * The school STAGE player (smooth playback, David 2026-10-28 — contract
 * docs/engineering/SCHOOL_SMOOTH_PLAYBACK.md §C). Used only for a schedule with the server's
 * `perform_at_ms` / `release_at_ms` (`hasSmoothTimes`); `PetMediaView` and its crossfades stay
 * as they are for everything else.
 *
 * - Every clip of the command (attention loop, performing, success loop, release — whichever
 *   exist, `schoolStageClips`; max 4 players) is mounted ONCE when the stage mounts, each in its
 *   own persistent player from the phone's copy (`localMediaUri`), paused at frame 0, hidden.
 *   Nothing is mounted or loaded later in the session.
 * - `onReady` fires once when every player rendered its first frame (or was `readyToPlay` for
 *   `STAGE_READY_FALLBACK_MS`) or failed — the session clock starts only then (the 12 s bound
 *   is `useTrainingGame`'s, which then runs the session in picture mode).
 * - A segment change (`schoolSegmentAt`) = an INSTANT switch (no fade) to that role's player:
 *   `currentTime = 0` + play; the previous player pauses after the switch. Loops loop natively;
 *   once-clips hold their last frame.
 * - The still under the players is the current clip's START frame (`schoolStageStill`); a role
 *   without a usable clip, and picture mode, show the role's picture instead — never a ghost.
 * - Players pause while the app is in the background; all are released when the stage unmounts
 *   (session over / overlay closed) or switches to picture mode.
 */

import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Image, Platform, StyleSheet, View } from 'react-native';
import { useVideoPlayer, VideoView, type StatusChangeEventPayload, type VideoPlayer, type VideoPlayerStatus } from 'expo-video';

import { useAppActive, useLocalMediaUri } from '@/modules/petMedia/hooks';
import { forgetLocalMedia, isLocalMediaUri, localMediaUri } from '@/modules/petMedia/mediaFileCache';
import type { PetMediaInfo } from '@/modules/petMedia/petMedia';
import { schoolStageClips, schoolStageStill, type SchoolRole, type SchoolSegment, type SchoolStageClip } from '@/modules/petMedia/phasePlayer';
import type { MediaMode } from '@/modules/petMedia/statePlayer';
import type { TrainingCommand } from '@/modules/training/training';

/** A hidden player may never report its first frame: `readyToPlay` this long counts as ready. */
export const STAGE_READY_FALLBACK_MS = 1_000;

export interface SchoolStageProps {
  media: PetMediaInfo;
  command: TrainingCommand;
  /** The current stretch of the timeline; null = the clock hasn't started (nothing plays). */
  segment: SchoolSegment | null;
  /** `picture` (»Prikaz: Slika«, clips still on their way, or the 12 s timeout): stills only, no player. */
  mode: MediaMode;
  /** Every clip is ready (or failed) — once. Picture mode: at once. */
  onReady?: () => void;
  /** A player failed (likely an expired signed URL) — refetch the state for the next session. */
  onMediaExpired?: () => void;
  testID?: string;
}

interface StagePlayerProps {
  clip: SchoolStageClip;
  shown: boolean;
  onPlayer: (role: SchoolRole, player: VideoPlayer | null) => void;
  onSettled: (role: SchoolRole, ok: boolean) => void;
  testID: string;
}

/** One persistent player + view for a role. Its source is fixed for the stage's life. */
const StagePlayer = memo(function StagePlayer({ clip, shown, onPlayer, onSettled, testID }: StagePlayerProps) {
  const { role, url, loop } = clip;
  const [source] = useState(() => {
    const uri = localMediaUri(url, 'video') ?? url;
    return isLocalMediaUri(uri) ? { uri } : { uri, useCaching: true };
  });
  const localSource = isLocalMediaUri(source.uri);
  const player = useVideoPlayer(source, (p) => {
    p.loop = loop;
    p.muted = true;
    p.audioMixingMode = 'mixWithOthers';
    p.keepScreenOnWhilePlaying = false;
    p.currentTime = 0;
    p.pause();
  });

  useEffect(() => {
    onPlayer(role, player);
    return () => onPlayer(role, null);
  }, [player, role, onPlayer]);

  useEffect(() => {
    let fallback: ReturnType<typeof setTimeout> | null = null;
    const handle = (status: VideoPlayerStatus) => {
      if (status === 'error') {
        if (localSource) forgetLocalMedia(url, 'video');
        onSettled(role, false);
      } else if (status === 'readyToPlay' && fallback === null) {
        fallback = setTimeout(() => onSettled(role, true), STAGE_READY_FALLBACK_MS);
      }
    };
    const sub = player.addListener('statusChange', (payload: StatusChangeEventPayload) => handle(payload.status));
    handle(player.status);
    return () => {
      sub.remove();
      if (fallback !== null) clearTimeout(fallback);
    };
  }, [player, role, url, localSource, onSettled]);

  const onFirstFrameRender = useCallback(() => onSettled(role, true), [onSettled, role]);

  return (
    <View pointerEvents="none" style={[StyleSheet.absoluteFill, shown ? styles.shown : styles.hidden]} testID={`${testID}-${shown ? 'shown' : 'hidden'}`}>
      <VideoView
        player={player}
        contentFit="cover"
        nativeControls={false}
        allowsPictureInPicture={false}
        // Hidden views sit under the shown one: TextureView respects opacity on Android.
        surfaceType={Platform.OS === 'android' ? 'textureView' : undefined}
        onFirstFrameRender={onFirstFrameRender}
        style={StyleSheet.absoluteFill}
        testID={testID}
      />
    </View>
  );
});

export default function SchoolStage({ media, command, segment, mode, onReady, onMediaExpired, testID = 'school-stage' }: SchoolStageProps) {
  const appActive = useAppActive();
  // Decided once per stage (session): the clips never change under a running session.
  const [clips] = useState(() => (mode === 'video' ? schoolStageClips(media, command) : []));
  const video = mode === 'video';
  const [settled, setSettled] = useState<Partial<Record<SchoolRole, boolean>>>({});
  const players = useRef<Partial<Record<SchoolRole, VideoPlayer>>>({});
  const onPlayer = useCallback((role: SchoolRole, player: VideoPlayer | null) => {
    if (player === null) delete players.current[role];
    else players.current[role] = player;
  }, []);
  const expiredRef = useRef(onMediaExpired);
  expiredRef.current = onMediaExpired;
  const onSettled = useCallback((role: SchoolRole, ok: boolean) => {
    setSettled((s) => {
      // A failure after a success still fails the role (keeps the still, no frozen error frame).
      if (s[role] === ok || s[role] === false) return s;
      return { ...s, [role]: ok };
    });
    if (!ok) expiredRef.current?.();
  }, []);

  const allSettled = !video || clips.every((c) => settled[c.role] !== undefined);
  const readyRef = useRef(onReady);
  readyRef.current = onReady;
  const reported = useRef(false);
  useEffect(() => {
    if (!allSettled || reported.current) return;
    reported.current = true;
    readyRef.current?.();
  }, [allSettled]);

  const role: SchoolRole = segment?.role ?? 'attention';
  const usable = (r: SchoolRole) => video && settled[r] === true && clips.some((c) => c.role === r);
  // Only once the clock runs does a player show (before: the still of the attention start).
  const shownRole: SchoolRole | null = segment !== null && usable(role) ? role : null;
  const instance = segment?.instance ?? null;

  // The switch: restart the role's player from frame 0 and play it (it is already rendered).
  const playing = appActive;
  useEffect(() => {
    if (shownRole === null || instance === null) return;
    const player = players.current[shownRole];
    if (player === undefined) return;
    player.currentTime = 0;
    if (playing) player.play();
    // `playing` is handled below; a new instance alone restarts the clip.
  }, [shownRole, instance]); // eslint-disable-line react-hooks/exhaustive-deps

  // After the switch: every other player pauses (hidden, holds its frame); background pauses all.
  useEffect(() => {
    for (const r of Object.keys(players.current) as SchoolRole[]) {
      const player = players.current[r];
      if (player === undefined) continue;
      if (r === shownRole && playing) {
        if (!player.playing) player.play();
      } else if (player.playing) {
        player.pause();
      }
    }
  }, [shownRole, playing, instance]);

  const stillUrl = schoolStageStill(media, command, role, shownRole !== null || (segment === null && usable('attention')));
  const still = useLocalMediaUri(stillUrl, 'image');
  const onImageError = useCallback(() => {
    still.onLocalError();
  }, [still]);

  const shownClip = useMemo(() => (shownRole === null ? null : (clips.find((c) => c.role === shownRole) ?? null)), [clips, shownRole]);

  return (
    <View style={StyleSheet.absoluteFill} testID={testID} accessibilityRole="image">
      {still.uri !== null && (
        <Image source={{ uri: still.uri }} resizeMode="cover" onError={onImageError} style={StyleSheet.absoluteFill} testID={`${testID}-still`} />
      )}
      {video &&
        clips.map((clip) => (
          <StagePlayer
            key={clip.key}
            clip={clip}
            shown={shownClip?.role === clip.role}
            onPlayer={onPlayer}
            onSettled={onSettled}
            testID={`${testID}-${clip.role}`}
          />
        ))}
    </View>
  );
}

const styles = StyleSheet.create({
  // Instant switch: no animation, the shown player on top.
  shown: { opacity: 1, zIndex: 1 },
  hidden: { opacity: 0, zIndex: 0 },
});
