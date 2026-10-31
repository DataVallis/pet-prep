/**
 * ClipDeck — the full-screen pet video of a game (GAMES_SPEC §3.2, §7.1): the school stage's
 * persistent-player approach (`training/SchoolStage.tsx`, SCHOOL_SMOOTH_PLAYBACK §C) made
 * generic. WHAT plays is `useClipQueue` / the game's `DeckPlan`; this file only renders it.
 *
 * - Every clip of the game is mounted ONCE when the deck mounts — one persistent `expo-video`
 *   player per distinct URL (roles sharing a stand-in loop share it; ≤ `MAX_DECK_PLAYERS`),
 *   from the phone's copy (`localMediaUri`), paused at frame 0, hidden. Nothing is loaded later.
 * - `onReady('video')` once every player rendered its first frame (or was `readyToPlay` for
 *   `DECK_READY_FALLBACK_MS`, or failed). Not ready within `DECK_READY_TIMEOUT_MS` (12 s) →
 *   `onReady('picture')`: the players are released and this game runs in »Slika« (stills).
 *   Picture mode from the start → `onReady('picture')` at once, no player, no download.
 * - A switch (`current` / `instance` change): the role's player restarts from frame 0 and plays
 *   (a shared loop just keeps playing); `crossfade` = the new layer fades in over the old one
 *   (≤ 200 ms, the old keeps playing underneath); `instant` / `cut` = opacity 0 → 1 at once.
 * - `playToEnd` of the shown player → `onEnded(role, instance)` (ClipQueue's end event).
 * - Under the player: the role's START frame (`startStill`, §3.2 rule 7); without a clip /
 *   with a failed one: the role's `picture` — never another role's clip.
 * - Players pause in the background and are released on unmount.
 */

import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Animated, Image, Platform, StyleSheet, View } from 'react-native';
import { useVideoPlayer, VideoView, type StatusChangeEventPayload, type VideoPlayer, type VideoPlayerStatus } from 'expo-video';

import { DECK_READY_FALLBACK_MS, DECK_READY_TIMEOUT_MS, MAX_DECK_PLAYERS, deckPlayerUrls, type DeckClip } from '@/modules/gameLayer/deck';
import type { ClipSwitch } from '@/modules/gameLayer/clipQueue';
import { useAppActive, useLocalMediaUri } from '@/modules/petMedia/hooks';
import { forgetLocalMedia, isLocalMediaUri, localMediaUri } from '@/modules/petMedia/mediaFileCache';
import type { MediaMode } from '@/modules/petMedia/statePlayer';

export interface ClipDeckProps {
  clips: readonly DeckClip[];
  /** The role on screen and its instance (ClipQueue); null = the game hasn't started (start still). */
  current: { role: string; instance: number; lastSwitch: { from: string; kind: ClipSwitch; ms: number } | null } | null;
  mode: MediaMode;
  /** Once: the deck can play (`video`) or fell back to stills (`picture`). */
  onReady: (mode: MediaMode) => void;
  onEnded?: (role: string, instance: number) => void;
  /** A player failed (likely an expired signed URL) — the HUD refetches the state. */
  onMediaExpired?: () => void;
  testID?: string;
}

type Layer = 'front' | 'back' | 'hidden';

interface DeckPlayerProps {
  url: string;
  loop: boolean;
  layer: Layer;
  fadeInMs: number;
  onPlayer: (url: string, player: VideoPlayer | null) => void;
  onSettled: (url: string, ok: boolean) => void;
  onEnd: (url: string) => void;
  testID: string;
}

/** One persistent player + view. Its source is fixed for the deck's life. */
const DeckPlayer = memo(function DeckPlayer({ url, loop, layer, fadeInMs, onPlayer, onSettled, onEnd, testID }: DeckPlayerProps) {
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
    onPlayer(url, player);
    return () => onPlayer(url, null);
  }, [player, url, onPlayer]);

  useEffect(() => {
    let fallback: ReturnType<typeof setTimeout> | null = null;
    const handle = (status: VideoPlayerStatus) => {
      if (status === 'error') {
        if (localSource) forgetLocalMedia(url, 'video');
        onSettled(url, false);
      } else if (status === 'readyToPlay' && fallback === null) {
        fallback = setTimeout(() => onSettled(url, true), DECK_READY_FALLBACK_MS);
      }
    };
    const statusSub = player.addListener('statusChange', (payload: StatusChangeEventPayload) => handle(payload.status));
    const endSub = player.addListener('playToEnd', () => onEnd(url));
    handle(player.status);
    return () => {
      statusSub.remove();
      endSub.remove();
      if (fallback !== null) clearTimeout(fallback);
    };
  }, [player, url, localSource, onSettled, onEnd]);

  // Opacity: front fades in (or appears at once); back stays under it during a crossfade.
  const opacity = useRef(new Animated.Value(layer === 'hidden' ? 0 : 1)).current;
  useEffect(() => {
    if (layer === 'front' && fadeInMs > 0) {
      opacity.setValue(0);
      Animated.timing(opacity, { toValue: 1, duration: fadeInMs, useNativeDriver: true }).start();
    } else {
      opacity.stopAnimation();
      opacity.setValue(layer === 'hidden' ? 0 : 1);
    }
  }, [layer, fadeInMs, opacity]);

  const onFirstFrameRender = useCallback(() => onSettled(url, true), [onSettled, url]);

  return (
    <Animated.View
      pointerEvents="none"
      style={[StyleSheet.absoluteFill, { opacity, zIndex: layer === 'front' ? 2 : layer === 'back' ? 1 : 0 }]}
      testID={`${testID}-${layer}`}
    >
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
    </Animated.View>
  );
});

export default function ClipDeck({ clips, current, mode, onReady, onEnded, onMediaExpired, testID = 'clip-deck' }: ClipDeckProps) {
  const appActive = useAppActive();
  // Decided once per deck (game): the players never change under a running game.
  const [urls] = useState(() => (mode === 'video' ? deckPlayerUrls(clips).slice(0, MAX_DECK_PLAYERS) : []));
  const [timedOut, setTimedOut] = useState(false);
  const video = mode === 'video' && !timedOut && urls.length > 0;
  const [settled, setSettled] = useState<Record<string, boolean>>({});
  const players = useRef<Record<string, VideoPlayer>>({});
  const onPlayer = useCallback((url: string, player: VideoPlayer | null) => {
    if (player === null) delete players.current[url];
    else players.current[url] = player;
  }, []);
  const expiredRef = useRef(onMediaExpired);
  expiredRef.current = onMediaExpired;
  const onSettled = useCallback((url: string, ok: boolean) => {
    setSettled((s) => (s[url] === ok || s[url] === false ? s : { ...s, [url]: ok }));
    if (!ok) expiredRef.current?.();
  }, []);

  // Ready once: every player settled — or picture mode — or the 12 s timeout (→ picture).
  const readyRef = useRef(onReady);
  readyRef.current = onReady;
  const reported = useRef(false);
  const report = useCallback((m: MediaMode) => {
    if (reported.current) return;
    reported.current = true;
    readyRef.current(m);
  }, []);
  const allSettled = urls.every((u) => settled[u] !== undefined);
  useEffect(() => {
    if (!video) report('picture');
    else if (allSettled) report('video');
  }, [video, allSettled, report]);
  useEffect(() => {
    if (!video) return;
    const timer = setTimeout(() => {
      if (reported.current) return;
      setTimedOut(true);
    }, DECK_READY_TIMEOUT_MS);
    return () => clearTimeout(timer);
  }, [video]);

  const clipOf = useCallback((role: string | undefined) => clips.find((c) => c.role === role) ?? null, [clips]);
  const role = current?.role ?? clips[0]?.role;
  const clip = clipOf(role);
  const usable = (url: string | null): url is string => video && url !== null && urls.includes(url) && settled[url] === true;
  const frontUrl = current !== null && clip !== null && usable(clip.url) ? clip.url : null;

  // Crossfade: the previous role's player stays under the new one for the fade.
  const switchInfo = current?.lastSwitch ?? null;
  const [backUrl, setBackUrl] = useState<string | null>(null);
  const prevFront = useRef<string | null>(null);
  useEffect(() => {
    const previous = prevFront.current;
    prevFront.current = frontUrl;
    if (switchInfo?.kind !== 'crossfade' || previous === null || previous === frontUrl) {
      setBackUrl(null);
      return;
    }
    setBackUrl(previous);
    const timer = setTimeout(() => setBackUrl(null), switchInfo.ms);
    return () => clearTimeout(timer);
  }, [frontUrl, current?.instance]); // eslint-disable-line react-hooks/exhaustive-deps

  // The switch: restart the role's player from frame 0 (a shared loop just keeps going).
  const instance = current?.instance ?? null;
  const lastStarted = useRef<{ url: string; instance: number } | null>(null);
  useEffect(() => {
    if (frontUrl === null || instance === null || clip === null) return;
    const player = players.current[frontUrl];
    if (player === undefined) return;
    const sameLoop = lastStarted.current?.url === frontUrl && clip.loop;
    lastStarted.current = { url: frontUrl, instance };
    if (!sameLoop) player.currentTime = 0;
    if (appActive) player.play();
  }, [frontUrl, instance]); // eslint-disable-line react-hooks/exhaustive-deps

  // End of the shown player → the queue's end event (a once clip then holds its last frame).
  const endedKey = useRef<string | null>(null);
  const currentRef = useRef(current);
  currentRef.current = current;
  const endedCb = useRef(onEnded);
  endedCb.current = onEnded;
  const onEnd = useCallback((url: string) => {
    const now = currentRef.current;
    if (now === null) return;
    const shown = clips.find((c) => c.role === now.role);
    if (shown?.url !== url || shown.loop) return;
    endedKey.current = `${url}#${now.instance}`;
    endedCb.current?.(now.role, now.instance);
  }, [clips]);

  // Every other player pauses (holds its frame); the back layer plays out its fade; the
  // background pauses all. A once clip that reached its end is never started again.
  useEffect(() => {
    for (const url of Object.keys(players.current)) {
      const player = players.current[url];
      const finished = url === frontUrl && endedKey.current === `${url}#${instance}`;
      const keep = appActive && !finished && (url === frontUrl || url === backUrl);
      if (keep) {
        if (!player.playing) player.play();
      } else if (player.playing) {
        player.pause();
      }
    }
  }, [frontUrl, backUrl, appActive, instance]);

  // The still: the role's start frame under its clip, else the role's picture.
  const stillUrl = clip === null ? null : frontUrl !== null || (current === null && clip.url !== null && video) ? clip.startStill : clip.picture;
  const still = useLocalMediaUri(stillUrl, 'image');
  const onImageError = useCallback(() => still.onLocalError(), [still]);

  const layerOf = useCallback((url: string): Layer => (url === frontUrl ? 'front' : url === backUrl ? 'back' : 'hidden'), [frontUrl, backUrl]);
  const fadeMs = switchInfo?.kind === 'crossfade' ? switchInfo.ms : 0;
  const loopOf = useMemo(() => {
    const map: Record<string, boolean> = {};
    for (const c of clips) if (c.url !== null && map[c.url] === undefined) map[c.url] = c.loop;
    return map;
  }, [clips]);

  return (
    <View style={StyleSheet.absoluteFill} testID={testID} accessibilityRole="image">
      {still.uri !== null && (
        <Image source={{ uri: still.uri }} resizeMode="cover" onError={onImageError} style={StyleSheet.absoluteFill} testID={`${testID}-still`} />
      )}
      {video &&
        urls.map((url, i) => (
          <DeckPlayer
            key={url}
            url={url}
            loop={loopOf[url] === true}
            layer={layerOf(url)}
            fadeInMs={url === frontUrl ? fadeMs : 0}
            onPlayer={onPlayer}
            onSettled={onSettled}
            onEnd={onEnd}
            testID={`${testID}-player-${i}`}
          />
        ))}
    </View>
  );
}
