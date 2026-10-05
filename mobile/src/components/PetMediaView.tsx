/**
 * The AI dog on screen (M4-03 app side): plays the stored state video for the pet's
 * current state — looping, muted, `cover`, no controls — and falls back to the idle
 * video, then the reference image, then a breed placeholder.
 *
 * - **Crossfade:** a state change mounts the new video in a hidden layer; the previous
 *   one keeps playing until the new one renders its first frame, then fades out. One
 *   player in steady state, two only during the ~0.3 s crossfade; every player is
 *   released when its layer unmounts (`useVideoPlayer` cleans up).
 * - **Stable URLs:** layers are keyed by the media identity (`mediaKey`: path + file
 *   hash `v`), not the signed URL — a poll that re-signs the URL doesn't restart
 *   playback. A layer keeps the URL it started with.
 * - **Expired URL:** a player error removes that layer (the image underneath shows),
 *   asks for a fresh state once (`onMediaExpired`) and retries with the re-signed URL;
 *   a second error falls back to the image for good (until the file changes).
 * - **Battery:** players pause while the app is in the background or `active` is
 *   false (screen covered, lock overlay, walk); they don't keep the screen on.
 * - The child never sees a media error: pending shows a gentle "getting ready" hint,
 *   failed / disabled just show the image or the placeholder.
 */

import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import {
  Animated,
  Image,
  Platform,
  StyleSheet,
  Text,
  View,
  type StyleProp,
  type ViewStyle,
} from 'react-native';
import { PawPrint } from 'lucide-react-native';
import { useVideoPlayer, VideoView, type StatusChangeEventPayload } from 'expo-video';

import type { LockReason } from '@/modules/childPet/childPetView';
import { useAppActive, useStableUrl } from '@/modules/petMedia/hooks';
import {
  NO_VIDEO_ERRORS,
  canPlayUrl,
  isMediaPending,
  recordVideoError,
  recordVideoReady,
  selectMediaSource,
  videoStateFor,
  type PetMediaInfo,
  type VideoErrorState,
} from '@/modules/petMedia/petMedia';
import type { BreedType, PetState } from '@/types';

export const PET_MEDIA_STRINGS = {
  pending: 'Kuža se pripravlja…',
  breeds: { mutt: 'Mešanček', border_collie: 'Border collie' } satisfies Record<BreedType, string>,
  a11y: (breed: string) => `Tvoj kuža (${breed})`,
} as const;

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
}

export interface PetMediaViewProps {
  media: PetMediaInfo;
  petState: PetState;
  /** Server lock of the pet (child HUD); null / omitted = not locked. */
  lockReason?: LockReason | null;
  breed: BreedType;
  /** false = screen not focused / covered → pause every player. */
  active?: boolean;
  /** Called once per media key when a player fails (likely an expired URL) — refetch the state. */
  onMediaExpired?: () => void;
  /** `hud`: full-bleed dark (child); `card`: rounded box (parent). */
  variant?: 'hud' | 'card';
  /** Custom placeholder (the HUD's animated avatar); default: paw + breed. */
  placeholder?: ReactNode;
  style?: StyleProp<ViewStyle>;
  testID?: string;
}

interface VideoLayerProps {
  layer: Layer;
  playing: boolean;
  onReady: (id: string) => void;
  onError: (id: string) => void;
  testID: string;
}

/** One player + view. The source is fixed for the layer's life (no restart on re-sign). */
function VideoLayer({ layer, playing, onReady, onError, testID }: VideoLayerProps) {
  const [source] = useState(layer.url);
  const player = useVideoPlayer(source, (p) => {
    p.loop = true;
    p.muted = true;
    p.audioMixingMode = 'mixWithOthers';
    p.keepScreenOnWhilePlaying = false;
  });

  useEffect(() => {
    if (playing) player.play();
    else player.pause();
  }, [player, playing]);

  const { id } = layer;
  useEffect(() => {
    let fallback: ReturnType<typeof setTimeout> | null = null;
    const sub = player.addListener('statusChange', (payload: StatusChangeEventPayload) => {
      if (payload.status === 'error') {
        onError(id);
      } else if (payload.status === 'readyToPlay' && fallback === null) {
        fallback = setTimeout(() => onReady(id), READY_FALLBACK_MS);
      }
    });
    return () => {
      sub.remove();
      if (fallback !== null) clearTimeout(fallback);
    };
  }, [player, id, onReady, onError]);

  return (
    <Animated.View pointerEvents="none" style={[StyleSheet.absoluteFill, { opacity: layer.opacity }]}>
      <VideoView
        player={player}
        contentFit="cover"
        nativeControls={false}
        allowsPictureInPicture={false}
        // Two views overlap during the crossfade: TextureView respects opacity on Android.
        surfaceType={Platform.OS === 'android' ? 'textureView' : undefined}
        onFirstFrameRender={() => onReady(id)}
        style={StyleSheet.absoluteFill}
        testID={testID}
      />
    </Animated.View>
  );
}

function DefaultPlaceholder({ breed, variant }: { breed: BreedType; variant: 'hud' | 'card' }) {
  const dark = variant === 'hud';
  return (
    <View style={[styles.placeholder, dark ? styles.placeholderDark : styles.placeholderLight]}>
      <PawPrint color={dark ? '#a5b4fc' : '#6366f1'} size={variant === 'hud' ? 56 : 28} />
      <Text style={[styles.placeholderText, dark ? styles.placeholderTextDark : styles.placeholderTextLight]}>
        {PET_MEDIA_STRINGS.breeds[breed]}
      </Text>
    </View>
  );
}

let layerSeq = 0;

export default function PetMediaView({
  media,
  petState,
  lockReason = null,
  breed,
  active = true,
  onMediaExpired,
  variant = 'hud',
  placeholder,
  style,
  testID = 'pet-media',
}: PetMediaViewProps) {
  const appActive = useAppActive();
  const [errors, setErrors] = useState<VideoErrorState>(NO_VIDEO_ERRORS);
  const errorsRef = useRef(errors);
  errorsRef.current = errors;

  const wanted = videoStateFor(petState, lockReason);
  const target = useMemo(
    () => selectMediaSource(media, wanted, { canPlayVideo: (url) => canPlayUrl(errors, url) }),
    [media, wanted, errors],
  );
  const image = useStableUrl(media.referenceImageUrl);

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

  const fadeOut = useCallback(
    (layer: Layer) => {
      Animated.timing(layer.opacity, { toValue: 0, duration: CROSSFADE_MS, useNativeDriver: true }).start(
        ({ finished }) => {
          if (!finished || !mounted.current) return;
          const current = layersRef.current.find((l) => l.id === layer.id);
          if (current?.phase === 'leaving') commit(layersRef.current.filter((l) => l.id !== layer.id));
        },
      );
    },
    [commit],
  );

  const fadeIn = useCallback(
    (layer: Layer) => {
      // Layers shown before this one go away once it is fully visible.
      const older = layersRef.current.filter((l) => l.id !== layer.id && l.phase !== 'loading').map((l) => l.id);
      Animated.timing(layer.opacity, { toValue: 1, duration: CROSSFADE_MS, useNativeDriver: true }).start(
        ({ finished }) => {
          if (!finished || !mounted.current) return;
          commit(layersRef.current.filter((l) => !older.includes(l.id)));
        },
      );
    },
    [commit],
  );

  const targetVideoKey = target.kind === 'video' ? target.key : null;
  const targetVideoUrl = target.kind === 'video' ? target.url : null;
  useEffect(() => {
    const prev = layersRef.current;
    if (targetVideoKey === null || targetVideoUrl === null) {
      // No video wanted: drop what never showed, fade out what is visible.
      const next = prev
        .filter((l) => l.phase !== 'loading')
        .map((l) => (l.phase === 'visible' ? { ...l, phase: 'leaving' as const } : l));
      commit(next);
      next.filter((l) => l.phase === 'leaving').forEach(fadeOut);
      return;
    }
    const same = prev.find((l) => l.key === targetVideoKey);
    if (same && same.phase !== 'leaving') {
      // Already showing / loading this media (maybe a re-signed URL — keep the player).
      const next = prev.filter((l) => l.phase !== 'loading' || l.key === targetVideoKey);
      if (next.length !== prev.length) commit(next);
      return;
    }
    if (same) {
      // Back to a media that is fading out: fade it in again.
      same.opacity.stopAnimation();
      const revived: Layer = { ...same, phase: 'visible' };
      commit(prev.filter((l) => l.phase !== 'loading').map((l) => (l.id === same.id ? revived : l)));
      fadeIn(revived);
      return;
    }
    layerSeq += 1;
    const layer: Layer = {
      id: `${targetVideoKey}#${layerSeq}`,
      key: targetVideoKey,
      url: targetVideoUrl,
      opacity: new Animated.Value(0),
      phase: 'loading',
    };
    commit([...prev.filter((l) => l.phase !== 'loading'), layer]);
  }, [targetVideoKey, targetVideoUrl, commit, fadeIn, fadeOut]);

  const onReady = useCallback(
    (id: string) => {
      const layer = layersRef.current.find((l) => l.id === id);
      if (!layer || layer.phase !== 'loading') return;
      const visible: Layer = { ...layer, phase: 'visible' };
      commit(layersRef.current.map((l) => (l.id === id ? visible : l)));
      fadeIn(visible);
      const next = recordVideoReady(errorsRef.current, layer.url);
      if (next !== errorsRef.current) setErrors(next);
    },
    [commit, fadeIn],
  );

  const onError = useCallback(
    (id: string) => {
      const layer = layersRef.current.find((l) => l.id === id);
      if (!layer) return;
      layer.opacity.stopAnimation();
      commit(layersRef.current.filter((l) => l.id !== id));
      const result = recordVideoError(errorsRef.current, layer.url, onMediaExpired !== undefined);
      setErrors(result.state);
      if (result.refetch) onMediaExpired?.();
    },
    [commit, onMediaExpired],
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
      accessibilityLabel={PET_MEDIA_STRINGS.a11y(PET_MEDIA_STRINGS.breeds[breed])}
    >
      {showImage ? (
        <Image
          source={{ uri: image.uri ?? undefined }}
          resizeMode="cover"
          onError={image.onError}
          style={StyleSheet.absoluteFill}
          testID={`${testID}-image`}
        />
      ) : (
        <View style={StyleSheet.absoluteFill} testID={`${testID}-placeholder`}>
          {placeholder ?? <DefaultPlaceholder breed={breed} variant={variant} />}
        </View>
      )}

      {layers.map((layer) => (
        <VideoLayer
          key={layer.id}
          layer={layer}
          playing={playing}
          onReady={onReady}
          onError={onError}
          testID={`${testID}-video-${layer.phase}`}
        />
      ))}

      {/* Readability of the HUD on top of real media. */}
      {hud && (showImage || layers.length > 0) && <View pointerEvents="none" style={styles.hudShade} />}

      {pending && (
        <View pointerEvents="none" style={[styles.pendingPill, hud ? styles.pendingHud : styles.pendingCard]} testID={`${testID}-pending`}>
          <Text style={[styles.pendingText, !hud && styles.pendingTextCard]}>{PET_MEDIA_STRINGS.pending}</Text>
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    overflow: 'hidden',
    borderRadius: 16,
    backgroundColor: '#eef2ff',
  },
  hudShade: {
    ...StyleSheet.absoluteFill,
    backgroundColor: 'rgba(0, 0, 0, 0.25)',
  },
  placeholder: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
  },
  placeholderDark: { backgroundColor: '#020617' },
  placeholderLight: { backgroundColor: '#eef2ff' },
  placeholderText: { fontSize: 13, fontWeight: '700' },
  placeholderTextDark: { color: 'rgba(255, 255, 255, 0.7)' },
  placeholderTextLight: { color: '#4f46e5' },
  pendingPill: {
    position: 'absolute',
    alignSelf: 'center',
    paddingHorizontal: 14,
    paddingVertical: 7,
    borderRadius: 16,
  },
  pendingHud: {
    bottom: Platform.OS === 'ios' ? 140 : 128,
    backgroundColor: 'rgba(15, 23, 42, 0.8)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.15)',
  },
  pendingCard: {
    bottom: 8,
    backgroundColor: 'rgba(255, 255, 255, 0.9)',
  },
  pendingText: { color: '#e0e7ff', fontSize: 12, fontWeight: '700' },
  pendingTextCard: { color: '#4f46e5' },
});
