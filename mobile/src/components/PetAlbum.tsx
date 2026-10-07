/**
 * "Moj kuža" album (2026-10-05): every photo / state video of the dog, full screen.
 *
 * - Grid: the reference photo + one tile per entitled state (`modules/petMedia/album.ts`);
 *   not-yet-stored states are greyed "Še ni posnetka", non-entitled ones are hidden.
 *   Video tiles use the pet's reference photo as their poster (device feedback 2026-10-07:
 *   empty dark boxes): the state videos are image-to-video clips generated FROM that photo,
 *   so it is their real first frame — at zero cost (no player in the grid — only ONE
 *   player at a time; expo-image / thumbnail APIs are not installed). Every tile shares
 *   one layout: picture (or a brand placeholder while it loads / when it fails), a bottom
 *   caption pill (icon + label) and, for videos, a small play badge top-right.
 * - The viewer shows the same poster until the video's first frame is ready (no black box).
 * - Viewer: tap a tile → full-screen photo or looping video (expo-video, muted by
 *   default with a sound toggle, disk cache). Arrows and horizontal swipes move between
 *   the playable items (wrapping); back returns to the grid, X closes the album.
 * - Expired signed URLs: `useStableUrl` keeps the first URL while the server re-signs
 *   it; a load error switches to the newest URL or, when that is the one that failed,
 *   asks the caller for fresh URLs (`onMediaExpired` → refetch; once per media, again
 *   after a 5-min cooldown) and shows "Posnetka trenutno ni mogoče predvajati." with
 *   "Poskusi znova" (remounts the item). While open, the album also refetches 1 min
 *   before `media.expiresAt`, so URLs are usually fresh before they fail.
 * - Android hardware back: viewer → grid, grid → close (both callers).
 * - The caller pauses its own player (HUD / parent card) while the album is open.
 *
 * - "Kako je kuža rasel" (M5-R04 "Album rasti"): with ≥ 2 growth pictures (`growth`,
 *   fetched by the caller only while the album is open) a horizontal strip above the grid —
 *   stage, age then, family-local date, the current one marked "Zdaj". Tap → the same
 *   full-screen viewer; arrows / swipes move between the growth pictures only. Expiring
 *   growth URLs refetch through `onGrowthExpired` (same rules as the media).
 *
 * Shared by the child HUD and (read-only, same component) the parent's child detail.
 */

import { useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  ActivityIndicator,
  BackHandler,
  Image,
  PanResponder,
  Pressable,
  ScrollView,
  StyleSheet,
  View,
  type GestureResponderEvent,
  type LayoutChangeEvent,
  type PanResponderGestureState,
} from 'react-native';
import { Text } from '@/components/ui/Text';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { ChevronLeft, ChevronRight, Clock, Image as ImageIcon, Play, Video as VideoIcon, Volume2, VolumeX, X } from 'lucide-react-native';
import { useVideoPlayer, VideoView, type StatusChangeEventPayload } from 'expo-video';

import {
  ALBUM_STRINGS,
  buildAlbumItems,
  isPlayable,
  mayRefresh,
  refreshDelay,
  stepIndex,
  swipeDirection,
  type AlbumItem,
  type PlayableAlbumItem,
} from '@/modules/petMedia/album';
import { useAppActive, useStableUrl } from '@/modules/petMedia/hooks';
import { GROWTH_STRINGS, growthViewerItems, hasGrowthSection, type GrowthAlbum, type GrowthViewerItem } from '@/modules/petMedia/growth';
import { mediaKey, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import { alpha, fonts, palette, tightTracking } from '@/theme';

export interface PetAlbumProps {
  media: PetMediaInfo;
  onClose: () => void;
  /** A signed URL failed (likely expired) — refetch the state for fresh URLs. */
  onMediaExpired?: () => void;
  /** Header title of the grid (child: "Moj kuža", parent: "Posnetki kužka"). */
  title?: string;
  /** "Album rasti" (M5-R04); the section shows only from 2 pictures on. null / undefined = none. */
  growth?: GrowthAlbum | null;
  /** Family IANA zone for the growth dates. */
  timeZone?: string | null;
  /** A growth URL failed (likely expired) or is about to expire — refetch the growth album. */
  onGrowthExpired?: () => void;
  testID?: string;
}

/** What the full-screen viewer can show: an album item or a growth picture. */
type ViewerItem = PlayableAlbumItem | GrowthViewerItem;
type ViewerList = 'media' | 'growth';

const ZERO_INSETS = { top: 0, bottom: 0 } as const;

/** Asks for fresh URLs once per media, again only after the cooldown (no refetch loop). */
function useExpiryReporter(onMediaExpired: (() => void) | undefined): (url: string) => void {
  const reported = useRef(new Map<string, number>());
  const callback = useRef(onMediaExpired);
  callback.current = onMediaExpired;
  return useCallback((url: string) => {
    const key = mediaKey(url);
    const now = Date.now();
    if (!mayRefresh(reported.current.get(key), now)) return;
    reported.current.set(key, now);
    callback.current?.();
  }, []);
}

/** Refetch shortly before the signed URLs expire while the album is open. */
function useRefreshBeforeExpiry(expiresAt: string | null, onMediaExpired: (() => void) | undefined): void {
  const callback = useRef(onMediaExpired);
  callback.current = onMediaExpired;
  useEffect(() => {
    const delay = refreshDelay(expiresAt, Date.now());
    if (delay === null) return;
    const timer = setTimeout(() => callback.current?.(), delay);
    return () => clearTimeout(timer);
  }, [expiresAt]);
}

function Unavailable({ testID, onRetry }: { testID: string; onRetry?: () => void }) {
  return (
    <View style={styles.unavailable} testID={testID}>
      <Clock color={palette.n400} size={28} />
      <Text style={styles.unavailableText}>{ALBUM_STRINGS.unavailable}</Text>
      {onRetry && (
        <Pressable onPress={onRetry} accessibilityRole="button" testID={`${testID}-retry`} style={({ pressed }) => [styles.retryButton, pressed && styles.pressed]}>
          <Text style={styles.retryText}>{ALBUM_STRINGS.retry}</Text>
        </Pressable>
      )}
    </View>
  );
}

/** The photo with re-signed URL handling (grid tile or viewer). */
function AlbumImage({
  url,
  onExpired,
  testID,
  fit,
  onRetry,
}: {
  url: string;
  onExpired: (url: string) => void;
  testID: string;
  fit: 'cover' | 'contain';
  onRetry?: () => void;
}) {
  const image = useStableUrl(url);
  const onError = () => {
    image.onError();
    onExpired(url);
  };
  if (image.uri === null) return <Unavailable testID={`${testID}-unavailable`} onRetry={onRetry} />;
  return <Image source={{ uri: image.uri }} resizeMode={fit} onError={onError} style={StyleSheet.absoluteFill} testID={testID} />;
}

/** The one video player of the album. Keyed by media by the caller → a new item = a new player. */
function AlbumVideo({
  url,
  posterUrl,
  muted,
  onExpired,
  onRetry,
}: {
  url: string;
  /** Shown over the player until its first frame is ready (the reference photo). */
  posterUrl: string | null;
  muted: boolean;
  onExpired: (url: string) => void;
  onRetry: () => void;
}) {
  const appActive = useAppActive();
  const stable = useStableUrl(url);
  const source = useMemo(() => (stable.uri ? { uri: stable.uri, useCaching: true } : null), [stable.uri]);
  const player = useVideoPlayer(source, (p) => {
    p.loop = true;
    p.muted = true;
    p.audioMixingMode = 'mixWithOthers';
  });
  // The poster covers the player until a frame can be shown (status or first frame —
  // some devices are late with one of them).
  const [ready, setReady] = useState(false);

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
    setReady(player.status === 'readyToPlay');
    const handle = (status: StatusChangeEventPayload['status']) => {
      if (status === 'readyToPlay') setReady(true);
      if (status !== 'error') return;
      onStableError();
      onExpired(source.uri);
    };
    const sub = player.addListener('statusChange', (payload: StatusChangeEventPayload) => handle(payload.status));
    handle(player.status);
    return () => sub.remove();
  }, [player, source, onStableError, onExpired]);

  if (source === null) return <Unavailable testID="album-video-unavailable" onRetry={onRetry} />;
  return (
    <>
      <VideoView
        player={player}
        contentFit="contain"
        nativeControls={false}
        allowsPictureInPicture={false}
        onFirstFrameRender={() => setReady(true)}
        style={StyleSheet.absoluteFill}
        testID="album-video"
      />
      {!ready && (
        <View style={styles.videoPoster} pointerEvents="none" testID="album-video-poster">
          <TileBackdrop url={posterUrl} kind="video" fit="contain" testID="album-video-poster" />
          <ActivityIndicator color={palette.mint} style={styles.videoSpinner} />
        </View>
      )}
    </>
  );
}

/**
 * Picture of a tile (or of the viewer while a video loads): a brand placeholder — graphite
 * surface, soft mint disc, photo / video icon — under the image, visible until the image
 * has loaded and again when it fails (never a bare black box). A failed URL is reported
 * once (`onExpired`) so the caller can refetch fresh signed URLs.
 */
export function TileBackdrop({
  url,
  kind,
  fit = 'cover',
  onExpired,
  testID,
}: {
  url: string | null;
  kind: 'photo' | 'video';
  fit?: 'cover' | 'contain';
  onExpired?: (url: string) => void;
  testID: string;
}) {
  const image = useStableUrl(url);
  const [loadedUri, setLoadedUri] = useState<string | null>(null);
  const loaded = image.uri !== null && loadedUri === image.uri;
  const Icon = kind === 'video' ? VideoIcon : ImageIcon;
  return (
    <>
      {!loaded && (
        <View style={styles.placeholder} testID={`${testID}-placeholder`}>
          <View style={styles.placeholderDisc}>
            <Icon color={palette.mint} size={26} />
          </View>
        </View>
      )}
      {image.uri !== null && (
        <Image
          source={{ uri: image.uri }}
          resizeMode={fit}
          onLoad={() => setLoadedUri(image.uri)}
          onError={() => {
            image.onError();
            if (url !== null) onExpired?.(url);
          }}
          style={StyleSheet.absoluteFill}
          testID={`${testID}-image`}
        />
      )}
    </>
  );
}

/** Two columns, 12 pt apart, portrait 4:5 — measured from the grid so it fits any width. */
const GRID_GAP = 12;
const TILE_ASPECT = 0.8;

export function tileSize(gridWidth: number): { width: number; height: number } | null {
  if (!(gridWidth > GRID_GAP)) return null;
  const width = Math.floor((gridWidth - GRID_GAP) / 2);
  return { width, height: Math.round(width / TILE_ASPECT) };
}

function Tile({
  item,
  posterUrl,
  size,
  onPress,
  onExpired,
}: {
  item: AlbumItem;
  /** Poster of a video tile (the reference photo); null → brand placeholder. */
  posterUrl: string | null;
  size: { width: number; height: number } | null;
  onPress: () => void;
  onExpired: (url: string) => void;
}) {
  const testID = `album-item-${item.id}`;
  const sized = size ?? styles.tileFallbackSize;
  if (item.kind === 'missing') {
    return (
      <View
        style={[styles.tile, sized, styles.tileMissing]}
        testID={testID}
        accessible
        accessibilityLabel={`${item.label}, ${ALBUM_STRINGS.missing}`}
      >
        <Clock color={alpha(palette.white, 0.35)} size={26} />
        <Text style={[styles.tileLabel, styles.tileLabelMissing]} numberOfLines={1}>
          {item.label}
        </Text>
        <Text style={styles.tileHint} numberOfLines={2}>
          {ALBUM_STRINGS.missing}
        </Text>
      </View>
    );
  }
  const isVideo = item.kind === 'video';
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={item.label}
      accessibilityHint={isVideo ? ALBUM_STRINGS.videoHint : ALBUM_STRINGS.photoHint}
      testID={testID}
      style={({ pressed }) => [styles.tile, sized, pressed && styles.pressed]}
    >
      <TileBackdrop
        url={isVideo ? posterUrl : item.url}
        kind={item.kind}
        onExpired={onExpired}
        testID={isVideo ? `${testID}-poster` : testID}
      />
      {isVideo && (
        <View style={styles.playBadge} testID={`${testID}-play`}>
          <Play color={palette.white} fill={palette.white} size={14} />
        </View>
      )}
      <View style={styles.tileCaption} testID={`${testID}-caption`}>
        {isVideo ? <VideoIcon color={palette.white} size={13} /> : <ImageIcon color={palette.white} size={12} />}
        <Text style={styles.tileLabel} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.8}>
          {item.label}
        </Text>
      </View>
    </Pressable>
  );
}

/** "Kako je kuža rasel": horizontal strip of growth pictures, oldest first, current last. */
function GrowthStrip({
  items,
  onOpen,
  onExpired,
}: {
  items: GrowthViewerItem[];
  onOpen: (id: string) => void;
  onExpired: (url: string) => void;
}) {
  return (
    <View style={styles.growthSection} testID="album-growth">
      <Text style={styles.sectionTitle} accessibilityRole="header">
        {GROWTH_STRINGS.section}
      </Text>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.growthStrip} testID="album-growth-strip">
        {items.map((item) => {
          const testID = `album-${item.id}`;
          const a11y = [item.stageLabel, item.ageLabel, item.dateLabel, item.isCurrent ? GROWTH_STRINGS.current : null]
            .filter((x): x is string => x !== null)
            .join(', ');
          return (
            <Pressable
              key={item.id}
              onPress={() => onOpen(item.id)}
              accessibilityRole="button"
              accessibilityLabel={a11y.length > 0 ? a11y : GROWTH_STRINGS.photo}
              accessibilityHint={GROWTH_STRINGS.hint}
              testID={testID}
              style={({ pressed }) => [styles.growthTile, pressed && styles.pressed]}
            >
              <View style={[styles.growthPhoto, item.isCurrent && styles.growthPhotoCurrent]}>
                <AlbumImage url={item.url} onExpired={onExpired} testID={`${testID}-image`} fit="cover" />
                {item.isCurrent && (
                  <View style={styles.nowBadge} testID={`${testID}-current`}>
                    <Text style={styles.nowText}>{GROWTH_STRINGS.current}</Text>
                  </View>
                )}
              </View>
              {item.stageLabel !== null && (
                <Text style={styles.growthStage} numberOfLines={1} testID={`${testID}-stage`}>
                  {item.stageLabel}
                </Text>
              )}
              {item.ageLabel !== null && (
                <Text style={styles.growthMeta} numberOfLines={1} testID={`${testID}-age`}>
                  {item.ageLabel}
                </Text>
              )}
              {item.dateLabel !== null && (
                <Text style={styles.growthDate} numberOfLines={1} testID={`${testID}-date`}>
                  {item.dateLabel}
                </Text>
              )}
            </Pressable>
          );
        })}
      </ScrollView>
    </View>
  );
}

export default function PetAlbum({
  media,
  onClose,
  onMediaExpired,
  title = ALBUM_STRINGS.title,
  growth = null,
  timeZone = null,
  onGrowthExpired,
  testID = 'pet-album',
}: PetAlbumProps) {
  const insets = useContext(SafeAreaInsetsContext) ?? ZERO_INSETS;
  // Item labels are translated when built → rebuild on a language change (M1-18).
  const { i18n } = useTranslation();
  const language = i18n.language;
  const items = useMemo(() => buildAlbumItems(media), [media, language]);
  const playable = useMemo(() => items.filter(isPlayable), [items]);
  const growthItems = useMemo(
    () => (hasGrowthSection(growth) ? growthViewerItems(growth, timeZone) : []),
    [growth, timeZone, language],
  );
  const [selection, setSelection] = useState<{ list: ViewerList; id: string } | null>(null);
  const [muted, setMuted] = useState(true);
  const reportExpired = useExpiryReporter(onMediaExpired);
  const reportGrowthExpired = useExpiryReporter(onGrowthExpired);
  useRefreshBeforeExpiry(media.expiresAt, onMediaExpired);
  useRefreshBeforeExpiry(growthItems.length > 0 ? (growth?.expiresAt ?? null) : null, onGrowthExpired);
  // "Poskusi znova" remounts the open item (fresh useStableUrl → newest URL).
  const [attempt, setAttempt] = useState(0);
  const retry = useCallback(() => setAttempt((n) => n + 1), []);
  const [gridWidth, setGridWidth] = useState(0);
  const onGridLayout = useCallback((e: LayoutChangeEvent) => setGridWidth(e.nativeEvent.layout.width), []);
  const size = useMemo(() => tileSize(gridWidth), [gridWidth]);

  // The viewer moves within one list: the album items or the growth pictures.
  const viewerList: ViewerList = selection?.list ?? 'media';
  const viewerItems: readonly ViewerItem[] = viewerList === 'growth' ? growthItems : playable;
  const onViewerExpired = viewerList === 'growth' ? reportGrowthExpired : reportExpired;
  // An item that vanished (media changed under the viewer) → back to the grid.
  const index = selection === null ? -1 : viewerItems.findIndex((i) => i.id === selection.id);
  const selected = index >= 0 ? viewerItems[index] : null;
  const selectedDetail = selected !== null && 'detail' in selected ? selected.detail : null;

  const step = useCallback(
    (delta: -1 | 1) => {
      const next = stepIndex(index, delta, viewerItems.length);
      if (next !== null) setSelection({ list: viewerList, id: viewerItems[next].id });
    },
    [index, viewerItems, viewerList],
  );
  const stepRef = useRef(step);
  stepRef.current = step;

  // Android hardware back: viewer → grid, grid → close.
  const backRef = useRef<() => void>(() => undefined);
  backRef.current = () => {
    if (selected) setSelection(null);
    else onClose();
  };
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      backRef.current();
      return true;
    });
    return () => sub.remove();
  }, []);

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
          <Pressable onPress={() => setSelection(null)} hitSlop={10} accessibilityRole="button" accessibilityLabel={ALBUM_STRINGS.back} testID="album-back" style={styles.iconButton}>
            <ChevronLeft color={palette.white} size={22} />
          </Pressable>
        ) : (
          <View style={styles.iconButtonSpacer} />
        )}
        <Text style={styles.title} numberOfLines={1}>
          {selected ? selected.label : title}
        </Text>
        <Pressable onPress={onClose} hitSlop={10} accessibilityRole="button" accessibilityLabel={ALBUM_STRINGS.close} testID="album-close" style={styles.iconButton}>
          <X color={palette.white} size={22} />
        </Pressable>
      </View>

      {selected ? (
        <View style={styles.viewer} testID="album-viewer" {...pan.panHandlers}>
          <View style={styles.stage}>
            {selected.kind === 'photo' ? (
              <AlbumImage
                key={`${selected.key}#${attempt}`}
                url={selected.url}
                onExpired={onViewerExpired}
                onRetry={retry}
                testID="album-photo"
                fit="contain"
              />
            ) : (
              <AlbumVideo
                key={`${selected.key}#${attempt}`}
                url={selected.url}
                posterUrl={media.referenceImageUrl}
                muted={muted}
                onExpired={onViewerExpired}
                onRetry={retry}
              />
            )}
          </View>

          <View style={[styles.controls, { paddingBottom: Math.max(insets.bottom, 16) + 8 }]}>
            {viewerItems.length > 1 ? (
              <Pressable onPress={() => step(-1)} accessibilityRole="button" accessibilityLabel={ALBUM_STRINGS.previous} testID="album-prev" style={({ pressed }) => [styles.roundButton, pressed && styles.pressed]}>
                <ChevronLeft color={palette.white} size={24} />
              </Pressable>
            ) : (
              <View style={styles.roundSpacer} />
            )}
            <View style={styles.controlsCenter}>
              <View style={styles.positionBox}>
                <Text style={styles.position} testID="album-position">
                  {ALBUM_STRINGS.position(index + 1, viewerItems.length)}
                </Text>
                {selectedDetail !== null && (
                  <Text style={styles.detail} numberOfLines={1} testID="album-detail">
                    {selectedDetail}
                  </Text>
                )}
              </View>
              {selected.kind === 'video' && (
                <Pressable
                  onPress={() => setMuted((m) => !m)}
                  accessibilityRole="button"
                  accessibilityLabel={muted ? ALBUM_STRINGS.unmute : ALBUM_STRINGS.mute}
                  testID="album-mute"
                  style={({ pressed }) => [styles.muteButton, pressed && styles.pressed]}
                >
                  {muted ? <VolumeX color={palette.white} size={18} /> : <Volume2 color={palette.white} size={18} />}
                </Pressable>
              )}
            </View>
            {viewerItems.length > 1 ? (
              <Pressable onPress={() => step(1)} accessibilityRole="button" accessibilityLabel={ALBUM_STRINGS.next} testID="album-next" style={({ pressed }) => [styles.roundButton, pressed && styles.pressed]}>
                <ChevronRight color={palette.white} size={24} />
              </Pressable>
            ) : (
              <View style={styles.roundSpacer} />
            )}
          </View>
        </View>
      ) : items.length === 0 && growthItems.length === 0 ? (
        <View style={styles.emptyBox} testID="album-empty">
          <Text style={styles.emptyText}>{ALBUM_STRINGS.empty}</Text>
        </View>
      ) : (
        <ScrollView contentContainerStyle={[styles.scrollContent, { paddingBottom: Math.max(insets.bottom, 16) + 16 }]} testID="album-grid">
          {growthItems.length > 0 && (
            <GrowthStrip items={growthItems} onExpired={reportGrowthExpired} onOpen={(id) => setSelection({ list: 'growth', id })} />
          )}
          <View style={styles.grid} onLayout={onGridLayout} testID="album-tiles">
            {items.map((item) => (
              <Tile
                key={item.id}
                item={item}
                posterUrl={media.referenceImageUrl}
                size={size}
                onExpired={reportExpired}
                onPress={() => {
                  if (isPlayable(item)) setSelection({ list: 'media', id: item.id });
                }}
              />
            ))}
          </View>
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
    backgroundColor: palette.graphite,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
    paddingHorizontal: 16,
    paddingBottom: 12,
    backgroundColor: alpha(palette.graphite, 0.92),
    borderBottomWidth: 1,
    borderBottomColor: alpha(palette.white, 0.1),
  },
  title: { flex: 1, textAlign: 'center', fontSize: 18, letterSpacing: tightTracking(18), fontFamily: fonts.displayBold, color: palette.white },
  iconButton: {
    width: 36,
    height: 36,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(palette.white, 0.08),
  },
  iconButtonSpacer: { width: 36, height: 36 },
  scrollContent: { padding: 16, gap: 20 },
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'space-between',
    rowGap: GRID_GAP,
  },
  growthSection: { gap: 10 },
  sectionTitle: { fontSize: 16, letterSpacing: tightTracking(16), fontFamily: fonts.displayBold, color: palette.white },
  growthStrip: { gap: 12, paddingRight: 4 },
  growthTile: { width: 116, gap: 2 },
  growthPhoto: {
    width: 116,
    height: 145,
    marginBottom: 6,
    overflow: 'hidden',
    borderRadius: 20,
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
  },
  growthPhotoCurrent: { borderWidth: 2, borderColor: palette.mint },
  nowBadge: {
    position: 'absolute',
    top: 8,
    left: 8,
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 10,
    backgroundColor: palette.mint,
  },
  nowText: { fontSize: 11, fontWeight: '800', color: palette.graphite },
  growthStage: { fontSize: 14, fontWeight: '700', color: palette.white },
  growthMeta: { fontSize: 12, fontWeight: '600', color: palette.n300 },
  growthDate: { fontSize: 11, fontWeight: '600', color: palette.n400 },
  tile: {
    overflow: 'hidden',
    borderRadius: 24,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: palette.n850,
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
  },
  /** Until the grid is measured (first frame only). */
  tileFallbackSize: { width: '48%', aspectRatio: TILE_ASPECT },
  tileMissing: {
    gap: 6,
    paddingHorizontal: 12,
    backgroundColor: alpha(palette.n850, 0.45),
    borderColor: alpha(palette.white, 0.06),
    borderStyle: 'dashed',
  },
  placeholder: {
    ...StyleSheet.absoluteFill,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: palette.n850,
  },
  placeholderDisc: {
    width: 64,
    height: 64,
    borderRadius: 32,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(palette.mint, 0.12),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.25),
  },
  playBadge: {
    position: 'absolute',
    top: 10,
    right: 10,
    width: 32,
    height: 32,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
    paddingLeft: 2,
    backgroundColor: alpha(palette.graphite, 0.72),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.25),
  },
  tileCaption: {
    position: 'absolute',
    left: 10,
    right: 10,
    bottom: 10,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    paddingVertical: 6,
    paddingHorizontal: 10,
    borderRadius: 12,
    backgroundColor: alpha(palette.graphite, 0.8),
  },
  tileLabel: { flexShrink: 1, fontSize: 14, fontWeight: '700', color: palette.white },
  tileLabelMissing: { color: alpha(palette.white, 0.45) },
  tileHint: { fontSize: 11, fontWeight: '600', color: alpha(palette.white, 0.35), textAlign: 'center' },
  videoPoster: { ...StyleSheet.absoluteFill, alignItems: 'center', justifyContent: 'center' },
  videoSpinner: { position: 'absolute', bottom: 24 },
  viewer: { flex: 1 },
  stage: { flex: 1, backgroundColor: palette.black },
  controls: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 20,
    paddingTop: 12,
    backgroundColor: alpha(palette.graphite, 0.92),
  },
  controlsCenter: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  positionBox: { alignItems: 'center', gap: 2 },
  position: { fontSize: 13, fontWeight: '700', color: alpha(palette.white, 0.7) },
  detail: { fontSize: 12, fontWeight: '600', color: palette.n300 },
  roundButton: {
    width: 52,
    height: 52,
    borderRadius: 26,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(palette.white, 0.16),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.25),
  },
  roundSpacer: { width: 52, height: 52 },
  muteButton: {
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(palette.white, 0.12),
  },
  unavailable: { ...StyleSheet.absoluteFill, alignItems: 'center', justifyContent: 'center', gap: 10, padding: 24 },
  unavailableText: { fontSize: 14, fontWeight: '600', color: palette.n300, textAlign: 'center' },
  retryButton: {
    marginTop: 4,
    paddingHorizontal: 20,
    paddingVertical: 10,
    borderRadius: 16,
    backgroundColor: alpha(palette.white, 0.16),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.25),
  },
  retryText: { fontSize: 14, fontWeight: '700', color: palette.white },
  emptyBox: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 32 },
  emptyText: { fontSize: 15, fontWeight: '600', color: palette.n300, textAlign: 'center', lineHeight: 22 },
  pressed: { opacity: 0.8, transform: [{ scale: 0.97 }] },
});
