/**
 * "Moj kuža" album (2026-10-05): every photo / state video of the dog, full screen.
 *
 * - Grid: the reference photo + one tile per entitled state (`modules/petMedia/album.ts`);
 *   not-yet-stored states are greyed "Še ni posnetka", non-entitled ones are hidden.
 *   Video tiles have no thumbnails (no player in the grid — only ONE player at a time).
 * - Viewer: tap a tile → full-screen photo or looping video (expo-video, muted by
 *   default with a sound toggle, disk cache). Arrows and horizontal swipes move between
 *   the playable items (wrapping); back returns to the grid, X closes the album.
 * - Expired signed URLs: `useStableUrl` keeps the first URL while the server re-signs
 *   it; a load error switches to the newest URL or, when that is the one that failed,
 *   asks the caller once per media for fresh URLs (`onMediaExpired` → refetch) and
 *   shows "Posnetka trenutno ni mogoče predvajati." until they arrive.
 * - The caller pauses its own player (HUD / parent card) while the album is open.
 *
 * Shared by the child HUD and (read-only, same component) the parent's child detail.
 */

import { useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import {
  Image,
  PanResponder,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
  type GestureResponderEvent,
  type PanResponderGestureState,
} from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { ChevronLeft, ChevronRight, Clock, Image as ImageIcon, Play, Volume2, VolumeX, X } from 'lucide-react-native';
import { useVideoPlayer, VideoView, type StatusChangeEventPayload } from 'expo-video';

import {
  ALBUM_STRINGS,
  buildAlbumItems,
  isPlayable,
  stepIndex,
  swipeDirection,
  type AlbumItem,
  type PlayableAlbumItem,
} from '@/modules/petMedia/album';
import { useAppActive, useStableUrl } from '@/modules/petMedia/hooks';
import { mediaKey, type PetMediaInfo } from '@/modules/petMedia/petMedia';

export interface PetAlbumProps {
  media: PetMediaInfo;
  onClose: () => void;
  /** A signed URL failed (likely expired) — refetch the state for fresh URLs. */
  onMediaExpired?: () => void;
  testID?: string;
}

const ZERO_INSETS = { top: 0, bottom: 0 } as const;

/** Asks for fresh URLs at most once per media while the album is open (no refetch loop). */
function useExpiryReporter(onMediaExpired: (() => void) | undefined): (url: string) => void {
  const reported = useRef(new Set<string>());
  const callback = useRef(onMediaExpired);
  callback.current = onMediaExpired;
  return useCallback((url: string) => {
    const key = mediaKey(url);
    if (reported.current.has(key)) return;
    reported.current.add(key);
    callback.current?.();
  }, []);
}

function Unavailable({ testID }: { testID: string }) {
  return (
    <View style={styles.unavailable} testID={testID}>
      <Clock color="#94a3b8" size={28} />
      <Text style={styles.unavailableText}>{ALBUM_STRINGS.unavailable}</Text>
    </View>
  );
}

/** The photo with re-signed URL handling (grid tile or viewer). */
function AlbumImage({ url, onExpired, testID, fit }: { url: string; onExpired: (url: string) => void; testID: string; fit: 'cover' | 'contain' }) {
  const image = useStableUrl(url);
  const onError = () => {
    image.onError();
    onExpired(url);
  };
  if (image.uri === null) return <Unavailable testID={`${testID}-unavailable`} />;
  return <Image source={{ uri: image.uri }} resizeMode={fit} onError={onError} style={StyleSheet.absoluteFill} testID={testID} />;
}

/** The one video player of the album. Keyed by media by the caller → a new item = a new player. */
function AlbumVideo({ url, muted, onExpired }: { url: string; muted: boolean; onExpired: (url: string) => void }) {
  const appActive = useAppActive();
  const stable = useStableUrl(url);
  const source = useMemo(() => (stable.uri ? { uri: stable.uri, useCaching: true } : null), [stable.uri]);
  const player = useVideoPlayer(source, (p) => {
    p.loop = true;
    p.muted = true;
    p.audioMixingMode = 'mixWithOthers';
  });

  useEffect(() => {
    player.muted = muted;
  }, [player, muted]);

  useEffect(() => {
    if (source !== null && appActive) player.play();
    else player.pause();
  }, [player, source, appActive]);

  const onStableError = stable.onError;
  useEffect(() => {
    if (source === null) return;
    const handle = (status: StatusChangeEventPayload['status']) => {
      if (status !== 'error') return;
      onStableError();
      onExpired(source.uri);
    };
    const sub = player.addListener('statusChange', (payload: StatusChangeEventPayload) => handle(payload.status));
    handle(player.status);
    return () => sub.remove();
  }, [player, source, onStableError, onExpired]);

  if (source === null) return <Unavailable testID="album-video-unavailable" />;
  return (
    <VideoView
      player={player}
      contentFit="contain"
      nativeControls={false}
      allowsPictureInPicture={false}
      style={StyleSheet.absoluteFill}
      testID="album-video"
    />
  );
}

function Tile({ item, onPress, onExpired }: { item: AlbumItem; onPress: () => void; onExpired: (url: string) => void }) {
  const testID = `album-item-${item.id}`;
  if (item.kind === 'missing') {
    return (
      <View style={[styles.tile, styles.tileMissing]} testID={testID} accessible accessibilityLabel={`${item.label}, ${ALBUM_STRINGS.missing}`}>
        <Clock color="rgba(255, 255, 255, 0.35)" size={26} />
        <Text style={[styles.tileLabel, styles.tileLabelMissing]}>{item.label}</Text>
        <Text style={styles.tileHint}>{ALBUM_STRINGS.missing}</Text>
      </View>
    );
  }
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={item.label}
      testID={testID}
      style={({ pressed }) => [styles.tile, pressed && styles.pressed]}
    >
      {item.kind === 'photo' ? (
        <AlbumImage url={item.url} onExpired={onExpired} testID={`${testID}-image`} fit="cover" />
      ) : (
        <View style={styles.playBadge}>
          <Play color="#ffffff" fill="#ffffff" size={22} />
        </View>
      )}
      <View style={styles.tileCaption}>
        {item.kind === 'photo' && <ImageIcon color="#ffffff" size={12} />}
        <Text style={styles.tileLabel}>{item.label}</Text>
      </View>
    </Pressable>
  );
}

export default function PetAlbum({ media, onClose, onMediaExpired, testID = 'pet-album' }: PetAlbumProps) {
  const insets = useContext(SafeAreaInsetsContext) ?? ZERO_INSETS;
  const items = useMemo(() => buildAlbumItems(media), [media]);
  const playable = useMemo(() => items.filter(isPlayable), [items]);
  const [selectedId, setSelectedId] = useState<PlayableAlbumItem['id'] | null>(null);
  const [muted, setMuted] = useState(true);
  const reportExpired = useExpiryReporter(onMediaExpired);

  // An item that vanished (media changed under the viewer) → back to the grid.
  const index = selectedId === null ? -1 : playable.findIndex((i) => i.id === selectedId);
  const selected = index >= 0 ? playable[index] : null;

  const step = useCallback(
    (delta: -1 | 1) => {
      const next = stepIndex(index, delta, playable.length);
      if (next !== null) setSelectedId(playable[next].id);
    },
    [index, playable],
  );
  const stepRef = useRef(step);
  stepRef.current = step;

  const pan = useMemo(
    () =>
      PanResponder.create({
        onMoveShouldSetPanResponder: (_e: GestureResponderEvent, g: PanResponderGestureState) =>
          swipeDirection(g.dx, g.dy) !== null,
        onPanResponderRelease: (_e: GestureResponderEvent, g: PanResponderGestureState) => {
          const direction = swipeDirection(g.dx, g.dy);
          if (direction !== null) stepRef.current(direction);
        },
      }),
    [],
  );

  return (
    <View style={styles.root} testID={testID} accessibilityViewIsModal>
      <View style={[styles.header, { paddingTop: Math.max(insets.top, 20) + 8 }]}>
        {selected ? (
          <Pressable onPress={() => setSelectedId(null)} hitSlop={10} accessibilityRole="button" accessibilityLabel={ALBUM_STRINGS.back} testID="album-back" style={styles.iconButton}>
            <ChevronLeft color="#ffffff" size={22} />
          </Pressable>
        ) : (
          <View style={styles.iconButtonSpacer} />
        )}
        <Text style={styles.title} numberOfLines={1}>
          {selected ? selected.label : ALBUM_STRINGS.title}
        </Text>
        <Pressable onPress={onClose} hitSlop={10} accessibilityRole="button" accessibilityLabel={ALBUM_STRINGS.close} testID="album-close" style={styles.iconButton}>
          <X color="#ffffff" size={22} />
        </Pressable>
      </View>

      {selected ? (
        <View style={styles.viewer} testID="album-viewer" {...pan.panHandlers}>
          <View style={styles.stage}>
            {selected.kind === 'photo' ? (
              <AlbumImage key={selected.key} url={selected.url} onExpired={reportExpired} testID="album-photo" fit="contain" />
            ) : (
              <AlbumVideo key={selected.key} url={selected.url} muted={muted} onExpired={reportExpired} />
            )}
          </View>

          <View style={[styles.controls, { paddingBottom: Math.max(insets.bottom, 16) + 8 }]}>
            {playable.length > 1 ? (
              <Pressable onPress={() => step(-1)} accessibilityRole="button" accessibilityLabel={ALBUM_STRINGS.previous} testID="album-prev" style={({ pressed }) => [styles.roundButton, pressed && styles.pressed]}>
                <ChevronLeft color="#ffffff" size={24} />
              </Pressable>
            ) : (
              <View style={styles.roundSpacer} />
            )}
            <View style={styles.controlsCenter}>
              <Text style={styles.position} testID="album-position">
                {ALBUM_STRINGS.position(index + 1, playable.length)}
              </Text>
              {selected.kind === 'video' && (
                <Pressable
                  onPress={() => setMuted((m) => !m)}
                  accessibilityRole="button"
                  accessibilityLabel={muted ? ALBUM_STRINGS.unmute : ALBUM_STRINGS.mute}
                  testID="album-mute"
                  style={({ pressed }) => [styles.muteButton, pressed && styles.pressed]}
                >
                  {muted ? <VolumeX color="#ffffff" size={18} /> : <Volume2 color="#ffffff" size={18} />}
                </Pressable>
              )}
            </View>
            {playable.length > 1 ? (
              <Pressable onPress={() => step(1)} accessibilityRole="button" accessibilityLabel={ALBUM_STRINGS.next} testID="album-next" style={({ pressed }) => [styles.roundButton, pressed && styles.pressed]}>
                <ChevronRight color="#ffffff" size={24} />
              </Pressable>
            ) : (
              <View style={styles.roundSpacer} />
            )}
          </View>
        </View>
      ) : items.length === 0 ? (
        <View style={styles.emptyBox} testID="album-empty">
          <Text style={styles.emptyText}>{ALBUM_STRINGS.empty}</Text>
        </View>
      ) : (
        <ScrollView contentContainerStyle={[styles.grid, { paddingBottom: Math.max(insets.bottom, 16) + 16 }]} testID="album-grid">
          {items.map((item) => (
            <Tile
              key={item.id}
              item={item}
              onExpired={reportExpired}
              onPress={() => {
                if (isPlayable(item)) setSelectedId(item.id);
              }}
            />
          ))}
        </ScrollView>
      )}
    </View>
  );
}

/** Dark glass HUD language (ChildHudScreen header / dock tokens). */
const styles = StyleSheet.create({
  root: {
    ...StyleSheet.absoluteFill,
    zIndex: 40,
    backgroundColor: '#020617',
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
    paddingHorizontal: 16,
    paddingBottom: 12,
    backgroundColor: 'rgba(15, 23, 42, 0.92)',
    borderBottomWidth: 1,
    borderBottomColor: 'rgba(255, 255, 255, 0.1)',
  },
  title: { flex: 1, textAlign: 'center', fontSize: 18, fontWeight: '800', color: '#ffffff' },
  iconButton: {
    width: 36,
    height: 36,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255, 255, 255, 0.08)',
  },
  iconButtonSpacer: { width: 36, height: 36 },
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'space-between',
    rowGap: 12,
    padding: 16,
  },
  tile: {
    width: '48%',
    aspectRatio: 0.8,
    overflow: 'hidden',
    borderRadius: 24,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(15, 23, 42, 0.85)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.15)',
  },
  tileMissing: {
    gap: 6,
    backgroundColor: 'rgba(30, 41, 59, 0.45)',
    borderColor: 'rgba(255, 255, 255, 0.06)',
    borderStyle: 'dashed',
  },
  playBadge: {
    width: 52,
    height: 52,
    borderRadius: 26,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(16, 185, 129, 0.85)',
  },
  tileCaption: {
    position: 'absolute',
    left: 10,
    right: 10,
    bottom: 10,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 5,
    paddingVertical: 5,
    borderRadius: 12,
    backgroundColor: 'rgba(15, 23, 42, 0.8)',
  },
  tileLabel: { fontSize: 14, fontWeight: '700', color: '#ffffff' },
  tileLabelMissing: { color: 'rgba(255, 255, 255, 0.45)' },
  tileHint: { fontSize: 11, fontWeight: '600', color: 'rgba(255, 255, 255, 0.35)' },
  viewer: { flex: 1 },
  stage: { flex: 1, backgroundColor: '#000000' },
  controls: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 20,
    paddingTop: 12,
    backgroundColor: 'rgba(15, 23, 42, 0.92)',
  },
  controlsCenter: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  position: { fontSize: 13, fontWeight: '700', color: 'rgba(255, 255, 255, 0.7)' },
  roundButton: {
    width: 52,
    height: 52,
    borderRadius: 26,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255, 255, 255, 0.16)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.25)',
  },
  roundSpacer: { width: 52, height: 52 },
  muteButton: {
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255, 255, 255, 0.12)',
  },
  unavailable: { ...StyleSheet.absoluteFill, alignItems: 'center', justifyContent: 'center', gap: 10, padding: 24 },
  unavailableText: { fontSize: 14, fontWeight: '600', color: '#cbd5e1', textAlign: 'center' },
  emptyBox: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 32 },
  emptyText: { fontSize: 15, fontWeight: '600', color: '#cbd5e1', textAlign: 'center', lineHeight: 22 },
  pressed: { opacity: 0.8, transform: [{ scale: 0.97 }] },
});
