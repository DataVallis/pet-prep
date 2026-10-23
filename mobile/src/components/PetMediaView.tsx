/**
 * The AI dog on screen (M4-03 app side): plays the stored state video for the pet's
 * current state — looping, muted, `cover`, no controls — and falls back to the idle
 * video, then the reference image, then a breed placeholder.
 *
 * - **Crossfade:** a state change mounts the new video in a hidden layer; the previous
 *   one keeps playing until the new one renders its first frame, then the new one fades
 *   in and the old one is released. Going back to the previous media while the newer
 *   one is still fading in stops that fade, fades the newer one out and brings the
 *   previous one back to full opacity. One player in steady state, two only during a
 *   crossfade; every player is released when its layer unmounts.
 * - **Stable URLs:** layers are keyed by the media identity (`mediaKey`: path + `v`),
 *   not the signed URL — a poll that re-signs the URL doesn't restart playback. A layer
 *   keeps the URL it started with. Sources use expo-video's disk cache (`useCaching`).
 * - **Errors:** a layer that never showed is dropped; a visible one keeps its last frame
 *   until a replacement is ready. The first error asks for a fresh state once
 *   (`onMediaExpired`, expired signature) and retries the re-signed URL; a second error
 *   fails the media for 60 s, then one more try, then for good (until the file changes).
 * - **Battery:** players pause while the app is in the background or `active` is
 *   false (screen covered); they don't keep the screen on.
 * - The child never sees a media error: pending shows a gentle "getting ready" hint,
 *   failed / disabled just show the image or the placeholder.
 * - **State media (M5-T08, `modules/petMedia/statePlayer.ts`):** a state loop that starts and
 *   ends on its key image plays with the native loop (no visible restart); a state change with
 *   a stored transition clip plays it ONCE (`playToEnd`), the target loop preloaded hidden
 *   underneath, then switches to that loop; a missing clip shows the state's key image —
 *   never a clip of another state (§0 rule 1). `mode="picture"` (M5-T11, the child's »Prikaz: Slika«)
 *   shows the key image of the current state only (idle / older set / v1 pet = the reference image):
 *   no player is mounted, so no video is downloaded, and transitions are skipped;
 *   `onVideoStateChange` then reports the state of the key image shown.
 */

import { memo, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Animated, Image, Platform, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { Text } from '@/components/ui/Text';
import { PawPrint } from 'lucide-react-native';
import { useVideoPlayer, VideoView, type StatusChangeEventPayload, type VideoPlayerStatus } from 'expo-video';

import type { LockReason } from '@/modules/childPet/childPetView';
import { useAppActive, useLocalMediaUri, useStableUrl } from '@/modules/petMedia/hooks';
import { forgetLocalMedia, isLocalMediaUri, localMediaUri } from '@/modules/petMedia/mediaFileCache';
import {
  NO_VIDEO_ERRORS,
  canPlayUrl,
  isMediaPending,
  isWaitingForUrl,
  nextFailedExpiry,
  recordVideoError,
  recordVideoReady,
  videoStateFor,
  type PetMediaInfo,
  type VideoErrorState,
  type VideoState,
} from '@/modules/petMedia/petMedia';
import {
  NO_TRANSITION,
  TRANSITION_MAX_MS,
  canStartTransition,
  nextPlayback,
  onTransitionEnd,
  onWantedChange,
  poseImageUrl,
  settleTransition,
  steadyItem,
  type MediaMode,
  type PlayItem,
  type TransitionState,
} from '@/modules/petMedia/statePlayer';
import type { PhasePlayback } from '@/modules/petMedia/phasePlayer';
import type { BehaviourScene } from '@/modules/behaviour/behaviour';
import type { PetState, ShownBreed, Species } from '@/types';
import { breedName } from '@/modules/species/species';
import { alpha, palette } from '@/theme';
import { t, tSpecies } from '@/i18n';
import { strings } from '@/i18n/strings';

/** User-visible strings (`pet:media`, M1-18). */
export const PET_MEDIA_STRINGS = strings('pet', 'media', {
  a11y: (breed: string) => t('pet:media.a11y', { breed }),
});

/**
 * M5-R06-08c: a cat's texts also on a parent screen (the parent never sets the child's text
 * species) — `species` is the shown pet's (a known species never reads the child's switch);
 * only without a species the child's text species applies.
 */
function mediaText(key: 'pending' | 'a11y', species: Species | null, breed: string): string {
  if (species !== null) return tSpecies(`pet:media.${key}`, species, key === 'a11y' ? { breed } : undefined);
  return key === 'a11y' ? PET_MEDIA_STRINGS.a11y(breed) : PET_MEDIA_STRINGS.pending;
}

/** Crossfade duration between two state videos (ms). */
export const CROSSFADE_MS = 300;

/**
 * Some devices are late with `onFirstFrameRender` (or skip it for a hidden view):
 * after `readyToPlay` the new layer is shown at the latest after this delay.
 */
export const READY_FALLBACK_MS = 1_000;

type LayerPhase = 'loading' | 'visible' | 'leaving';

interface Layer {
  /** Unique per mount (a retried media gets a new layer). */
  id: string;
  /** Media identity (`mediaKey`). */
  key: string;
  url: string;
  opacity: Animated.Value;
  phase: LayerPhase;
  /** The player failed; the view keeps its last frame until a replacement is ready. */
  errored: boolean;
  /** M5-T08: false = a transition clip — plays once, reports `playToEnd`. */
  loop: boolean;
  /** M5-T08: mounted hidden ahead of time (the loop after a transition) — never shown before its turn. */
  preload: boolean;
  /** M5-T08: a preloaded layer already rendered its first frame (shows at once when promoted). */
  readyEarly: boolean;
  /** David 2026-10-23: start this many ms into the clip (school performing clip, short window). */
  startAtMs?: number;
}

export interface PetMediaViewProps {
  media: PetMediaInfo;
  petState: PetState;
  /** Server lock of the pet (child HUD); null / omitted = not locked. */
  lockReason?: LockReason | null;
  /**
   * M5-R02 behaviour scene (`behaviour.scene`): its video wins while stored (premium);
   * otherwise the `petState` chain plays and the HUD draws the scene graphic.
   */
  scene?: BehaviourScene | null;
  /**
   * M5-R05 happy mood (`moodSceneAt`): `playing` while the dog is happy and nothing more
   * important shows — between the behaviour scene and `petState`; free tier falls back to idle.
   */
  mood?: 'playing' | null;
  /** M5-R06-02: `unknown` = a breed newer than this build (neutral label, never "mutt"). */
  breed: ShownBreed;
  /** Species for the neutral label of an unknown breed ("Pes" / "Mačka"). */
  species?: Species | null;
  /** false = screen not focused / covered → pause every player. */
  active?: boolean;
  /** false = never play a video, show the still image (parent view of a locked pet). */
  videoEnabled?: boolean;
  /** Called once per media key when a player fails (likely an expired URL) — refetch the state. */
  onMediaExpired?: () => void;
  /**
   * The state whose video the view picked (after the fallback chain and failed players);
   * null = no state video (image, placeholder or the server's legacy URL). The child lock
   * overlay uses it to darken a vet visit that shows a substitute instead of a real sick video.
   */
  onVideoStateChange?: (state: VideoState | null) => void;
  /** `hud`: full-bleed dark (child); `card`: rounded box (parent). */
  variant?: 'hud' | 'card';
  /**
   * M5-T08 / M5-T11: `video` (default) plays the state loops and transitions; `picture` shows
   * the key image of the current state only (the child's »Prikaz: Slika« setting).
   */
  mode?: MediaMode;
  /** Custom placeholder (the HUD's animated avatar); default: paw + breed. */
  placeholder?: ReactNode;
  /**
   * `overlay` (default): the "getting ready" pill sits on the media. `none`: the host shows
   * {@link MediaPendingNotice} itself — the child HUD stacks it above the dock (M5-F06: the
   * fixed `bottom` put it under the "Meals today" row and behind the dock buttons).
   */
  pendingNotice?: 'overlay' | 'none';
  /**
   * M5-T09 / T10: a school / game PHASE item (`modules/petMedia/phasePlayer.ts`) — when set it
   * replaces the state media: its clip plays once and holds its last frame, else its pose
   * image (picture mode: always the pose). No transitions while it is set.
   */
  phase?: PhasePlayback | null;
  style?: StyleProp<ViewStyle>;
  testID?: string;
}

interface VideoLayerProps {
  id: string;
  url: string;
  opacity: Animated.Value;
  playing: boolean;
  /** M5-T08: false = play once (transition), report the end. */
  loop: boolean;
  /** David 2026-10-23: start this many ms into the clip (set once, before it plays). */
  startAtMs?: number;
  onReady: (id: string) => void;
  onError: (id: string) => void;
  onEnd?: (id: string) => void;
  testID: string;
}

/** One player + view. The source is fixed for the layer's life (no restart on re-sign). */
const VideoLayer = memo(function VideoLayer({ id, url, opacity, playing, loop, startAtMs, onReady, onError, onEnd, testID }: VideoLayerProps) {
  // David 2026-10-20: the phone's copy of the file when there is one (survives re-signed
  // URLs); otherwise the remote URL with expo-video's cache, and a copy is made for next time.
  const [source] = useState(() => {
    const uri = localMediaUri(url, 'video') ?? url;
    return isLocalMediaUri(uri) ? { uri } : { uri, useCaching: true };
  });
  const localSource = isLocalMediaUri(source.uri);
  const player = useVideoPlayer(source, (p) => {
    // M5-T08: a state loop starts on its key image and ends close to it → native loop.
    p.loop = loop;
    // David 2026-10-23: a 5 s school performing clip in a shorter window starts later so its end lands on time.
    if (startAtMs !== undefined && startAtMs > 0) p.currentTime = startAtMs / 1000;
    p.muted = true;
    p.audioMixingMode = 'mixWithOthers';
    p.keepScreenOnWhilePlaying = false;
  });

  useEffect(() => {
    if (playing) player.play();
    else player.pause();
  }, [player, playing]);

  useEffect(() => {
    let fallback: ReturnType<typeof setTimeout> | null = null;
    const handle = (status: VideoPlayerStatus) => {
      if (status === 'error') {
        // A broken local copy is deleted; the normal retry then plays the remote URL.
        if (localSource) forgetLocalMedia(url, 'video');
        onError(id);
      }
      else if (status === 'readyToPlay' && fallback === null) {
        fallback = setTimeout(() => onReady(id), READY_FALLBACK_MS);
      }
    };
    const sub = player.addListener('statusChange', (payload: StatusChangeEventPayload) => handle(payload.status));
    // The status may have changed before we subscribed (fast cache hit / instant error).
    handle(player.status);
    return () => {
      sub.remove();
      if (fallback !== null) clearTimeout(fallback);
    };
  }, [player, id, onReady, onError, localSource, url]);

  useEffect(() => {
    if (loop || onEnd === undefined) return;
    const sub = player.addListener('playToEnd', () => onEnd(id));
    return () => sub.remove();
  }, [player, loop, onEnd, id]);

  const onFirstFrameRender = useCallback(() => onReady(id), [onReady, id]);

  return (
    <Animated.View pointerEvents="none" style={[StyleSheet.absoluteFill, { opacity }]}>
      <VideoView
        player={player}
        contentFit="cover"
        nativeControls={false}
        allowsPictureInPicture={false}
        // Two views overlap during the crossfade: TextureView respects opacity on Android.
        surfaceType={Platform.OS === 'android' ? 'textureView' : undefined}
        onFirstFrameRender={onFirstFrameRender}
        style={StyleSheet.absoluteFill}
        testID={testID}
      />
    </Animated.View>
  );
});

function DefaultPlaceholder({ label, variant }: { label: string; variant: 'hud' | 'card' }) {
  const dark = variant === 'hud';
  return (
    <View style={[styles.placeholder, dark ? styles.placeholderDark : styles.placeholderLight]}>
      <PawPrint color={dark ? palette.mintBorder : palette.graphite} size={variant === 'hud' ? 56 : 28} />
      <Text style={[styles.placeholderText, dark ? styles.placeholderTextDark : styles.placeholderTextLight]}>
        {label}
      </Text>
    </View>
  );
}

let layerSeq = 0;

function sameLayers(a: readonly Layer[], b: readonly Layer[]): boolean {
  return a.length === b.length && a.every((l, i) => l === b[i]);
}

function newLayer(key: string, url: string, loop: boolean, preload: boolean, startAtMs?: number): Layer {
  layerSeq += 1;
  return { id: `${key}#${layerSeq}`, key, url, opacity: new Animated.Value(0), phase: 'loading', errored: false, loop, preload, readyEarly: false, ...(startAtMs !== undefined ? { startAtMs } : {}) };
}

function animateTo(value: Animated.Value, toValue: number, done?: () => void): void {
  Animated.timing(value, { toValue, duration: CROSSFADE_MS, useNativeDriver: true }).start(({ finished }) => {
    if (finished) done?.();
  });
}

export default function PetMediaView({
  media,
  petState,
  lockReason = null,
  scene = null,
  mood = null,
  breed,
  species = null,
  active = true,
  videoEnabled = true,
  onMediaExpired,
  onVideoStateChange,
  variant = 'hud',
  mode = 'video',
  placeholder,
  pendingNotice = 'overlay',
  phase = null,
  style,
  testID = 'pet-media',
}: PetMediaViewProps) {
  const appActive = useAppActive();
  const [errors, setErrors] = useState<VideoErrorState>(NO_VIDEO_ERRORS);
  const errorsRef = useRef(errors);
  errorsRef.current = errors;
  const expiredRef = useRef(onMediaExpired);
  expiredRef.current = onMediaExpired;

  // Re-evaluate when a cool-down / wait ends.
  const [clock, setClock] = useState(() => Date.now());
  useEffect(() => {
    const now = Date.now();
    const next = nextFailedExpiry(errors, now);
    if (next === null) return;
    const timer = setTimeout(() => setClock(Date.now()), next - now + 10);
    return () => clearTimeout(timer);
  }, [errors, clock]);

  const wanted = videoEnabled ? videoStateFor(petState, lockReason, scene, mood) : null;

  // M5-T08: transition bookkeeping (derived while rendering — the React derived-state pattern).
  const [transition, setTransition] = useState<TransitionState>(NO_TRANSITION);
  const canPlayNow = useCallback((url: string) => canPlayUrl(errors, url, Math.max(clock, Date.now())), [errors, clock]);
  // M5-T08b: a transition is marked pending only when it can play NOW into the target's own loop.
  const wantedTransition = onWantedChange(transition, mode === 'picture' || phase !== null ? null : wanted, (from, to) =>
    canStartTransition({ media, wanted: to, petState, mode, canPlayVideo: canPlayNow }, from),
  );

  const playback = useMemo(() => {
    const input = { media, wanted, petState, mode, canPlayVideo: canPlayNow };
    if (wantedTransition.pending === null) return { current: steadyItem(input), preload: null };
    return nextPlayback(input, wantedTransition.pending.from);
  }, [media, wanted, petState, mode, canPlayNow, wantedTransition]);
  // …and a pending one the player no longer picks (clip failed / loop gone) is settled, never played later.
  const nextTransition = settleTransition(wantedTransition, playback);
  if (nextTransition !== transition) setTransition(nextTransition);
  const target: PlayItem = phase?.current ?? playback.current;
  const preloadTarget: PlayItem | null = phase !== null ? null : playback.preload;

  const endTransition = useCallback(() => setTransition((t) => onTransitionEnd(t)), []);
  // Safety net: a transition that never reports its end is given up (the loop takes over).
  const transitionKey = target.kind === 'clip' ? target.transition : null;
  useEffect(() => {
    if (transitionKey === null) return;
    const timer = setTimeout(endTransition, TRANSITION_MAX_MS);
    return () => clearTimeout(timer);
  }, [transitionKey, endTransition]);

  // The still under the clips: the current state's key image (state media) or the reference image.
  // M5-T08b (QA minor 4, §0 rule 1): under a loading clip of a state-media pet the still is the
  // wanted state's own pose (its key image), never the standing reference image.
  const stillUrl =
    phase !== null
      ? phase.still
      : target.kind === 'image'
      ? target.url
      : media.stateMedia
        ? (poseImageUrl(media, target.kind === 'clip' ? target.state : wanted) ?? media.referenceImageUrl)
        : media.referenceImageUrl;
  const image = useStableUrl(stillUrl);
  const localImage = useLocalMediaUri(image.uri, 'image');
  const onImageError = useCallback(() => {
    if (!localImage.onLocalError()) image.onError();
  }, [localImage, image]);

  // M5-T11: in picture mode the state's OWN key image is its media (the vet veil / scene card read
  // it); the reference image standing in for a missing key image reports null, like a substitute.
  const pictureState =
    mode === 'picture' && target.kind === 'image' && target.state !== null && (target.state === 'idle' || media.keyframes[target.state] === target.url)
      ? target.state
      : null;
  const targetVideoState = target.kind === 'clip' ? target.state : pictureState;
  const videoStateRef = useRef(onVideoStateChange);
  videoStateRef.current = onVideoStateChange;
  useEffect(() => {
    videoStateRef.current?.(targetVideoState);
  }, [targetVideoState]);

  // ── Video layers ─────────────────────────────────────────────
  const [layers, setLayers] = useState<Layer[]>([]);
  const layersRef = useRef<Layer[]>([]);
  const commit = useCallback((next: Layer[]) => {
    layersRef.current = next;
    setLayers(next);
  }, []);
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      layersRef.current.forEach((l) => l.opacity.stopAnimation());
    };
  }, []);

  const removeIfLeaving = useCallback(
    (id: string) => {
      if (!mounted.current) return;
      if (layersRef.current.find((l) => l.id === id)?.phase === 'leaving') {
        commit(layersRef.current.filter((l) => l.id !== id));
      }
    },
    [commit],
  );

  /** Mark layers leaving (stopping any fade-in, whose completion then removes nothing) and fade them out. */
  const retire = useCallback(
    (ids: readonly string[], base: Layer[]): Layer[] => {
      for (const l of base) {
        if (!ids.includes(l.id) || l.phase === 'leaving') continue;
        l.opacity.stopAnimation();
        animateTo(l.opacity, 0, () => removeIfLeaving(l.id));
      }
      return base.map((l) => (ids.includes(l.id) ? { ...l, phase: 'leaving' as const } : l));
    },
    [removeIfLeaving],
  );

  const targetVideoKey = target.kind === 'clip' ? target.key : null;
  const targetVideoUrl = target.kind === 'clip' ? target.url : null;
  const targetLoop = target.kind === 'clip' ? target.loop : true;
  const targetStartAt = target.kind === 'clip' ? target.startAtMs : undefined;
  const preloadKey = preloadTarget?.kind === 'clip' ? preloadTarget.key : null;
  const preloadUrl = preloadTarget?.kind === 'clip' ? preloadTarget.url : null;
  useEffect(() => {
    const current = layersRef.current;
    // M5-T08: a preloaded loop nobody needs any more is dropped (it never showed).
    const prev = current.filter((l) => !(l.preload && l.key !== preloadKey && l.key !== targetVideoKey));
    // The preloaded loop becomes the target (its transition ended): already mounted at its
    // first frame — it goes on top and plays from there (first frame = the transition's last).
    const promotedLayer = prev.find((l) => l.preload && l.key === targetVideoKey);
    const promoted = promotedLayer ? [...prev.filter((l) => l !== promotedLayer), { ...promotedLayer, preload: false }] : prev;
    // Mount the loop that follows a transition (hidden, paused at frame 0) so the switch is instant.
    const withPreload =
      preloadKey !== null && preloadUrl !== null && !promoted.some((l) => l.key === preloadKey && !l.errored)
        ? [...promoted, newLayer(preloadKey, preloadUrl, true, true)]
        : promoted;
    if (targetVideoKey === null || targetVideoUrl === null) {
      // No video wanted: drop what never showed, fade out what is visible — except a
      // frozen frame whose media waits for a re-signed URL.
      const kept = withPreload.filter((l) => l.phase !== 'loading');
      const retiring = kept
        .filter((l) => l.phase === 'visible' && !(l.errored && isWaitingForUrl(errors, l.key)))
        .map((l) => l.id);
      if (!sameLayers(kept, current) || retiring.length > 0) commit(retire(retiring, kept));
      return;
    }
    const same = withPreload.find((l) => l.key === targetVideoKey && !l.errored);
    const othersLoading = withPreload.filter((l) => l.phase === 'loading' && l.id !== same?.id && !(l.preload && l.key === preloadKey));
    const base = withPreload.filter((l) => !othersLoading.includes(l));
    const changed = !sameLayers(base, current);

    if (same?.phase === 'loading') {
      // A promoted preload that already rendered its first frame shows now.
      if (same.readyEarly) {
        commit(base);
        onReadyRef.current(same.id);
        return;
      }
      if (changed) commit(base);
      return;
    }
    if (same) {
      const shown = base.filter((l) => l.phase !== 'loading');
      if (same.phase === 'visible' && shown[shown.length - 1]?.id === same.id) {
        // Already the newest visible media (maybe a re-signed URL) — keep the player.
        if (changed) commit(base);
        return;
      }
      // Back to a media that is under a newer one or fading out: stop the newer one's
      // fade-in, fade the others out, bring this one back to full opacity.
      const others = shown.filter((l) => l.id !== same.id).map((l) => l.id);
      same.opacity.stopAnimation();
      commit(retire(others, base).map((l) => (l.id === same.id ? { ...l, phase: 'visible' as const } : l)));
      animateTo(same.opacity, 1);
      return;
    }
    // New media on top (order = paint order); a transition covers its preloaded loop.
    commit([...base, newLayer(targetVideoKey, targetVideoUrl, targetLoop, false, targetStartAt)]);
    // targetStartAt belongs to the clip key (a new phase instance = a new key): read when the layer is created.
  }, [targetVideoKey, targetVideoUrl, targetLoop, preloadKey, preloadUrl, errors, commit, retire]); // eslint-disable-line react-hooks/exhaustive-deps

  const onReady = useCallback(
    (id: string) => {
      const layer = layersRef.current.find((l) => l.id === id);
      if (!layer || layer.phase !== 'loading') return;
      if (layer.preload) {
        // Hidden until its turn; remember that it can show at once.
        commit(layersRef.current.map((l) => (l.id === id ? { ...l, readyEarly: true } : l)));
        return;
      }
      // Layers shown before this one go away once it is fully visible.
      const older = layersRef.current.filter((l) => l.id !== id && l.phase !== 'loading').map((l) => l.id);
      commit(layersRef.current.map((l) => (l.id === id ? { ...l, phase: 'visible' as const } : l)));
      animateTo(layer.opacity, 1, () => {
        if (mounted.current) commit(layersRef.current.filter((l) => !older.includes(l.id)));
      });
      const next = recordVideoReady(errorsRef.current, layer.url);
      if (next !== errorsRef.current) {
        errorsRef.current = next;
        setErrors(next);
      }
    },
    [commit],
  );
  const onReadyRef = useRef(onReady);
  onReadyRef.current = onReady;

  const onEnd = useCallback(
    (id: string) => {
      const layer = layersRef.current.find((l) => l.id === id);
      if (layer && !layer.loop) endTransition();
    },
    [endTransition],
  );

  const onError = useCallback(
    (id: string) => {
      const layer = layersRef.current.find((l) => l.id === id);
      if (!layer || layer.errored) return;
      if (layer.phase === 'visible') {
        // Keep the last frame on screen until something replaces it.
        commit(layersRef.current.map((l) => (l.id === id ? { ...l, errored: true } : l)));
      } else {
        layer.opacity.stopAnimation();
        commit(layersRef.current.filter((l) => l.id !== id));
      }
      const result = recordVideoError(errorsRef.current, layer.url, expiredRef.current !== undefined, Date.now());
      errorsRef.current = result.state;
      setErrors(result.state);
      if (result.refetch) expiredRef.current?.();
      // A transition that fails is skipped (the loop is already preloaded underneath).
      if (!layer.loop) endTransition();
    },
    [commit, endTransition],
  );

  const playing = active && appActive;
  const showImage = image.uri !== null;
  const pending = isMediaPending(media);
  const hud = variant === 'hud';

  return (
    <View
      style={[hud ? StyleSheet.absoluteFill : styles.card, style]}
      testID={testID}
      accessible
      accessibilityRole="image"
      accessibilityLabel={mediaText('a11y', species, breedName(breed, species))}
    >
      {showImage ? (
        <Image
          source={{ uri: localImage.uri ?? undefined }}
          resizeMode="cover"
          onError={onImageError}
          style={StyleSheet.absoluteFill}
          testID={`${testID}-image`}
        />
      ) : (
        <View style={StyleSheet.absoluteFill} testID={`${testID}-placeholder`}>
          {placeholder ?? <DefaultPlaceholder label={breedName(breed, species)} variant={variant} />}
        </View>
      )}

      {layers.map((layer) => (
        <VideoLayer
          key={layer.id}
          id={layer.id}
          url={layer.url}
          opacity={layer.opacity}
          // A preloaded loop waits paused at its first frame (= the transition's last frame).
          playing={playing && !layer.errored && !layer.preload}
          loop={layer.loop}
          startAtMs={layer.startAtMs}
          onReady={onReady}
          onError={onError}
          onEnd={onEnd}
          testID={`${testID}-video-${layer.errored ? 'errored' : layer.preload ? 'preload' : layer.loop ? layer.phase : `transition-${layer.phase}`}`}
        />
      ))}

      {/* Readability of the HUD on top of real media. */}
      {hud && (showImage || layers.length > 0) && <View pointerEvents="none" style={styles.hudShade} />}

      {pending && pendingNotice === 'overlay' && (
        <View pointerEvents="none" style={[styles.pendingPill, hud ? styles.pendingHud : styles.pendingCard]} testID={`${testID}-pending`}>
          <Text style={[styles.pendingText, !hud && styles.pendingTextCard]}>{mediaText('pending', species, '')}</Text>
        </View>
      )}
    </View>
  );
}

/**
 * The child HUD's "Your pup is getting ready…" notice as a normal flow element (M5-F06):
 * wraps at large font sizes, never covered by the dock. Show it while `isMediaPending(media)`.
 */
export function MediaPendingNotice({ testID = 'hud-media-pending' }: { testID?: string }) {
  return (
    <View
      style={[styles.pendingPill, styles.pendingInline]}
      testID={testID}
      accessible
      accessibilityRole="text"
      accessibilityLiveRegion="polite"
    >
      {/* Capped growth (M5-F01 QA): at fontScale 2 the column above the dock can't reach the header. */}
      <Text style={[styles.pendingText, styles.pendingTextInline]} numberOfLines={2} maxFontSizeMultiplier={1.5}>
        {PET_MEDIA_STRINGS.pending}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    overflow: 'hidden',
    borderRadius: 16,
    backgroundColor: palette.mintSoft,
  },
  hudShade: {
    ...StyleSheet.absoluteFill,
    backgroundColor: alpha(palette.black, 0.25),
  },
  placeholder: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
  },
  placeholderDark: { backgroundColor: palette.graphite },
  placeholderLight: { backgroundColor: palette.mintSoft },
  placeholderText: { fontSize: 13, fontWeight: '700' },
  placeholderTextDark: { color: alpha(palette.white, 0.7) },
  placeholderTextLight: { color: palette.mintDeep },
  pendingPill: {
    position: 'absolute',
    alignSelf: 'center',
    paddingHorizontal: 14,
    paddingVertical: 7,
    borderRadius: 16,
  },
  pendingHud: {
    bottom: Platform.OS === 'ios' ? 140 : 128,
    backgroundColor: alpha(palette.graphite, 0.8),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
  },
  pendingCard: {
    bottom: 8,
    backgroundColor: alpha(palette.white, 0.9),
  },
  /** In the HUD's above-dock column: no absolute offset. */
  pendingInline: {
    position: 'relative',
    alignSelf: 'center',
    maxWidth: '100%',
    backgroundColor: alpha(palette.graphite, 0.8),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
  },
  pendingText: { color: palette.mintBorder, fontSize: 12, fontWeight: '700' },
  pendingTextInline: { textAlign: 'center' },
  pendingTextCard: { color: palette.mintDeep },
});
