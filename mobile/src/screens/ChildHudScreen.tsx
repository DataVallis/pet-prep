/**
 * Child HUD (PRODUCT_SPEC §8) — full-bleed pet viewport, glass status bar, metric bars
 * and the action dock. Since M1-13/M1-14 everything it shows comes from the server:
 * `useChildPet()` (`GET /api/child/pet`, live via Reverb, 10 s polling without it);
 * Feed / Water / Clean call the API (optimistic, then the server's state); the walk
 * syncs today's steps. Buttons are disabled with a hint from `state.feeding` /
 * `state.water`; locks come from the server's per-child lock (M1-16). The dog itself is
 * `PetMediaView` (M4-03): the AI state video of `pet_state`, crossfaded, paused under
 * the lock screen / walk tracker and in the background.
 * M5-R02: a puppy gets a fifth dock button "Pelji ven" with a calm countdown above the
 * dock; an open accident / chewed slipper shows its scene (premium video, else an in-app
 * graphic) — an accident is cleaned with the cleaning game (puddles), a slipper with
 * "Pospravi in daj igračo", never by scrubbing.
 * M5-R03: a pet with training gets a "Šola" chip above the dock (amber dot while today's
 * session is open) that opens the training mini-game (`modules/training/TrainingOverlay`).
 * M5-R04: "Obroki danes" chip row above the dock (today's windows, ✓, now, "nahrani starš")
 * and the growth section of the album ("Kako je kuža rasel", fetched only while it is open).
 */

import { useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Animated, Pressable, StyleSheet, useWindowDimensions, View, type LayoutChangeEvent } from 'react-native';
import { Text } from '@/components/ui/Text';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import {
  Beef,
  DoorOpen,
  Droplet,
  Footprints,
  Heart,
  Images,
  LogOut,
  PawPrint,
  RefreshCw,
  Sparkles,
  WifiOff,
} from 'lucide-react-native';
import { useQueryClient } from '@tanstack/react-query';

import { useAppStore, type WebSocketStatus } from '@/store/appStore';
import { rememberFamilyTimezone } from '@/modules/session/familyTimezone';
import { usePetWebSocket } from '@/hooks/usePetWebSocket';
import { applyBroadcastToCache, childPetKey, isRecoverableError, useChildPet } from '@/hooks/queries/useChildPet';
import { useClean, useFeed, useResolveChewing, useTakeOut, useWater } from '@/hooks/queries/useChildActions';
import { childPetGrowthKey, useChildPetGrowth, useGrowthRefresh } from '@/hooks/queries/usePetGrowth';
import { useServerNow } from '@/hooks/useServerNow';
import {
  classifyActionError,
  cleanHint,
  failureMessage,
  feedHint,
  successMessage,
  waterHint,
} from '@/modules/childPet/actionMessages';
import {
  BEHAVIOUR_STRINGS,
  cleaningMess,
  needsScrubbing,
  onlyChewingOpen,
  takeOutCountdown,
} from '@/modules/behaviour/behaviour';
import BehaviourPanel from '@/components/BehaviourPanel';
import MealWindowsRow from '@/components/MealWindowsRow';
import { buildMealWindows } from '@/modules/childPet/mealWindows';
import { lockStateFromView, type CareAction, type ChildPetView } from '@/modules/childPet/childPetView';
import { computeHudLayout, METRICS_RESERVED_RIGHT, METRICS_RIGHT, type HudLayout } from '@/modules/hud/hudLayout';
import { WS_BADGE_STRINGS, wsBadge } from '@/modules/hud/wsBadge';
import { formatSteps } from '@/modules/steps/stepCounter';
import { useStepSync } from '@/modules/steps/useStepSync';
import { logout } from '@/modules/session/logout';
import ActionButton from '@/components/ActionButton';
import PetAlbum from '@/components/PetAlbum';
import { ALBUM_STRINGS, hasAlbum } from '@/modules/petMedia/album';
import CleaningOverlay from '@/components/CleaningOverlay';
import MetricBar from '@/components/MetricBar';
import PetMediaView from '@/components/PetMediaView';
import WalkTrackerOverlay from '@/modules/walk/WalkTrackerOverlay';
import { usePushPromptOnFirstView } from '@/modules/push/usePushPromptOnFirstView';
import { nextStageLine, originLine, stageLine } from '@/modules/petProfile/petProfile';
import TrainingChip from '@/modules/training/TrainingChip';
import TrainingOverlay from '@/modules/training/TrainingOverlay';
import { showTrainingDot, showTrainingEntry } from '@/modules/training/training';
import type { BreedType, PetState, PetUpdatedBroadcast } from '@/types';
import { alpha, palette, radius } from '@/theme';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';
import { useTranslation } from 'react-i18next';

/**
 * User-visible strings of the HUD (`child:hud`, M1-18). `autoRetry` sits under the error
 * screen: the HUD keeps trying by itself (hotfix 2026-10-06).
 */
export const HUD_STRINGS = strings('child', 'hud', {
  get takeOut(): string {
    return BEHAVIOUR_STRINGS.takeOut;
  },
  ws: WS_BADGE_STRINGS,
});

/** Every breed and mood has a label (compile-time check). */
const _hudTexts: { breeds: Record<BreedType, string>; moods: Record<PetState, string> } = HUD_STRINGS;
void _hudTexts;

const CHALLENGE_WEEKS = 12;

/**
 * Legacy pet (no profile = no real age): the server's `virtual_age_months` is the
 * 12-week challenge clock (whole weeks since birth), not the dog's age — so the HUD
 * shows "TEDEN 1 OD 12" (same wording as the parent dashboard) instead of a
 * misleading "STAROST: 0 MESECEV".
 */
export function formatChallengeWeek(weeksSinceBirth: number): string {
  const week = Math.min(CHALLENGE_WEEKS, Math.max(0, Math.floor(weeksSinceBirth)) + 1);
  return t('child:hud.challengeWeek', { week, total: CHALLENGE_WEEKS });
}

/** HUD second line of a profiled pet: "Posvojen iz zavetišča · 24. 11. 2026 postane mlad pes". */
function profileSubline(profile: NonNullable<ChildPetView['pet']['profile']>): string | null {
  const parts = [originLine(profile), nextStageLine(profile, 'child')].filter((x): x is string => x !== null);
  return parts.length > 0 ? parts.join(' · ') : null;
}

const TOAST_MS = 3_000;

type Toast = { tone: 'ok' | 'info'; message: string };

/**
 * Realtime status in the top bar: green "V ŽIVO", amber "POVEZUJEM", otherwise only a
 * small grey refresh icon — the HUD keeps updating by polling (`modules/hud/wsBadge`).
 */
function WsStatusDot({ status }: { status: WebSocketStatus }) {
  const badge = wsBadge(status);
  if (badge.tone === 'polling') {
    return (
      <View style={styles.wsRow} testID="hud-ws-polling" accessible accessibilityLabel={badge.accessibilityLabel}>
        <RefreshCw color={palette.n500} size={11} />
      </View>
    );
  }
  return (
    <View style={styles.wsRow} testID={`hud-ws-${badge.tone}`} accessible accessibilityLabel={badge.accessibilityLabel}>
      <View style={[styles.wsDot, badge.tone === 'live' ? styles.wsConnected : styles.wsConnecting]} />
      <Text style={styles.wsText}>{badge.label}</Text>
    </View>
  );
}

const ZERO_INSETS = { top: 0, bottom: 0 } as const;

/**
 * Screen geometry for the header, metric column and dock (`modules/hud/hudLayout`):
 * window height + safe-area insets, refined with the measured header / dock heights.
 * Reads the insets context directly so the screen also renders without a provider (tests).
 */
function useHudLayout(): {
  layout: HudLayout;
  onHeaderLayout: (e: LayoutChangeEvent) => void;
  onDockLayout: (e: LayoutChangeEvent) => void;
} {
  const { height } = useWindowDimensions();
  const insets = useContext(SafeAreaInsetsContext) ?? ZERO_INSETS;
  const [headerHeight, setHeaderHeight] = useState<number | undefined>(undefined);
  const [dockHeight, setDockHeight] = useState<number | undefined>(undefined);

  const layout = useMemo(
    () =>
      computeHudLayout({
        screenHeight: height,
        insets: { top: insets.top, bottom: insets.bottom },
        headerHeight,
        dockHeight,
      }),
    [height, insets.top, insets.bottom, headerHeight, dockHeight],
  );
  const onHeaderLayout = useCallback((e: LayoutChangeEvent) => {
    const h = Math.round(e.nativeEvent.layout.height);
    if (h > 0) setHeaderHeight(h);
  }, []);
  const onDockLayout = useCallback((e: LayoutChangeEvent) => {
    const h = Math.round(e.nativeEvent.layout.height);
    if (h > 0) setDockHeight(h);
  }, []);
  return { layout, onHeaderLayout, onDockLayout };
}

/** Server lock → session lock overlay; per-child `awaiting_contract` → contract step. */
function useSessionSync(view: ChildPetView | undefined): void {
  const setLockState = useAppStore((s) => s.setLockState);
  const setAwaitingContract = useAppStore((s) => s.setAwaitingContract);
  useEffect(() => {
    if (!view) return;
    setLockState(lockStateFromView(view), {
      until: view.lock.until ?? view.pet.illness_until,
      timezone: view.timezone,
    });
    // For the lock overlay after a relaunch, before this state loads (hotfix 2026-10-06).
    rememberFamilyTimezone(view.timezone);
    // AppNavigator swaps the HUD for ContractScreen (M1-07b / M2-01).
    if (view.pet.awaiting_contract) setAwaitingContract(true);
  }, [view, setLockState, setAwaitingContract]);
}

export default function ChildHudScreen() {
  // M3-02 / PR #35: first HUD view of the session → "Naj te kuža pokliče?" while undecided.
  usePushPromptOnFirstView('child');
  // Re-render on a language switch (hints, toasts and the dock are read at render time).
  useTranslation();
  const sessionPet = useAppStore((s) => s.pet);
  const wsStatus = useAppStore((s) => s.wsStatus);
  const isWalkModalVisible = useAppStore((s) => s.isWalkModalVisible);
  const isCleaningOverlayVisible = useAppStore((s) => s.isCleaningOverlayVisible);
  const setWalkModalVisible = useAppStore((s) => s.setWalkModalVisible);
  const setCleaningOverlayVisible = useAppStore((s) => s.setCleaningOverlayVisible);
  const isAlbumVisible = useAppStore((s) => s.isAlbumVisible);
  const setAlbumVisible = useAppStore((s) => s.setAlbumVisible);
  const isTrainingVisible = useAppStore((s) => s.isTrainingVisible);
  const setTrainingVisible = useAppStore((s) => s.setTrainingVisible);
  const setHudVideoState = useAppStore((s) => s.setHudVideoState);
  const hudVideoState = useAppStore((s) => s.hudVideoState);
  // No HUD → no video under the lock veil.
  useEffect(() => () => setHudVideoState(null), [setHudVideoState]);

  const queryClient = useQueryClient();
  const { layout, onHeaderLayout, onDockLayout } = useHudLayout();
  const petQuery = useChildPet();
  const view = petQuery.data;
  // No view → PetMediaView is not mounted; don't keep a stale veil hint.
  useEffect(() => {
    if (!view) setHudVideoState(null);
  }, [view, setHudVideoState]);
  useSessionSync(view);

  const onBroadcast = useCallback(
    (event: PetUpdatedBroadcast) => {
      applyBroadcastToCache(queryClient, event);
    },
    [queryClient],
  );
  usePetWebSocket(view?.pet.id ?? sessionPet?.id ?? null, onBroadcast);

  const feed = useFeed();
  const water = useWater();
  const clean = useClean();
  const takeOut = useTakeOut();
  const resolveChewing = useResolveChewing();
  // M5-R02: a puppy (bladder clock) gets "Pelji ven" and the countdown ticks while unlocked.
  // The server refuses take-out (422) exactly when `take_out` is null (PR #42) — and a pet
  // created without the `behaviour_events` feature never has one.
  const hasTakeOut = view !== undefined && view.behaviour.take_out !== null;
  const serverNow = useServerNow(view?.clockSkewMs ?? 0, hasTakeOut && !(view?.lock.is_locked ?? false));
  // The album never survives a lock (it would reopen when the lock lifts) or the HUD.
  const lockedNow = view?.lock.is_locked ?? false;
  useEffect(() => {
    if (lockedNow) {
      setAlbumVisible(false);
      // A lock ends a running training session on this screen (the server refuses it anyway).
      setTrainingVisible(false);
    }
  }, [lockedNow, setAlbumVisible, setTrainingVisible]);
  useEffect(
    () => () => {
      setAlbumVisible(false);
      setTrainingVisible(false);
    },
    [setAlbumVisible, setTrainingVisible],
  );
  // M5-R03 / PR #53: the child's own session is still running (e.g. the app was restarted
  // mid-game) → open "Šola" so the game resumes / is saved. Once per session: closing the
  // overlay never reopens it for the same session.
  const ownSession = view?.training.session;
  const ownSessionId = ownSession?.mine === true && ownSession.schedule !== null ? ownSession.id : null;
  const dismissedSessions = useRef<Set<string>>(new Set());
  useEffect(() => {
    if (ownSessionId !== null && !lockedNow && !dismissedSessions.current.has(ownSessionId)) setTrainingVisible(true);
  }, [ownSessionId, lockedNow, setTrainingVisible]);
  const closeTraining = useCallback(() => {
    if (ownSessionId !== null) dismissedSessions.current.add(ownSessionId);
    setTrainingVisible(false);
  }, [ownSessionId, setTrainingVisible]);

  const stepSync = useStepSync({
    enabled: view !== undefined && !view.lock.is_locked,
    myStepsToday: view?.steps.my_steps_today ?? 0,
    serverTime: view?.server_time ?? null,
    timezone: view?.timezone ?? null,
  });

  const [toast, setToast] = useState<Toast | null>(null);
  const toastTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const showToast = useCallback((next: Toast) => {
    if (toastTimer.current) clearTimeout(toastTimer.current);
    setToast(next);
    toastTimer.current = setTimeout(() => setToast(null), TOAST_MS);
  }, []);
  useEffect(
    () => () => {
      if (toastTimer.current) clearTimeout(toastTimer.current);
    },
    [],
  );

  // Breathing animation for avatar fallback
  const bounceAnim = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(bounceAnim, { toValue: -8, duration: 1500, useNativeDriver: true }),
        Animated.timing(bounceAnim, { toValue: 0, duration: 1500, useNativeDriver: true }),
      ]),
    );
    loop.start();
    return () => loop.stop();
  }, [bounceAnim]);

  // A signed media URL failed (likely expired): fetch a freshly signed state once.
  const onMediaExpired = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: childPetKey });
  }, [queryClient]);

  // M5-R04 "Album rasti": only while the album is open; an error just hides the section.
  const growthPetId = view?.pet.id ?? null;
  const growthQuery = useChildPetGrowth(growthPetId, isAlbumVisible && !lockedNow);
  const growth = growthQuery.isError ? null : (growthQuery.data ?? null);
  const onGrowthExpired = useGrowthRefresh(childPetGrowthKey(growthPetId ?? 0));

  const mutations = { feed, water, clean, take_out: takeOut, resolve_chewing: resolveChewing } as const;
  const runAction = (action: CareAction) => {
    mutations[action].mutate(undefined, {
      onSuccess: (response) => {
        showToast({ tone: 'ok', message: successMessage(action, response.status) });
      },
      onError: (error) => {
        const message = failureMessage(
          classifyActionError(error),
          queryClient.getQueryData<ChildPetView>(childPetKey) ?? null,
        );
        if (message) showToast({ tone: 'info', message });
      },
    });
  };

  const handleCleaned = useCallback(() => {
    setCleaningOverlayVisible(false);
    clean.mutate(undefined, {
      onSuccess: (response) => showToast({ tone: 'ok', message: successMessage('clean', response.status) }),
      onError: (error) => {
        const message = failureMessage(
          classifyActionError(error),
          queryClient.getQueryData<ChildPetView>(childPetKey) ?? null,
        );
        if (message) showToast({ tone: 'info', message });
      },
    });
  }, [clean, queryClient, setCleaningOverlayVisible, showToast]);

  const handleLogout = () => {
    void logout();
  };

  // ── Loading / error without any state yet ────────────────────
  if (!view) {
    return (
      <View style={[styles.container, styles.centered]}>
        {petQuery.isError ? (
          <View style={styles.centeredBox} testID="hud-load-error">
            <WifiOff color={palette.n400} size={32} />
            <Text style={styles.centeredText}>{HUD_STRINGS.loadFailed}</Text>
            {isRecoverableError(petQuery.error) && (
              <Text style={styles.centeredHint} testID="hud-auto-retry">
                {HUD_STRINGS.autoRetry}
              </Text>
            )}
            <Pressable
              accessibilityRole="button"
              style={({ pressed }) => [styles.retryButton, pressed && styles.pressed]}
              onPress={() => {
                void petQuery.refetch();
              }}
            >
              <Text style={styles.retryText}>{HUD_STRINGS.retry}</Text>
            </Pressable>
            <Pressable accessibilityRole="button" onPress={handleLogout} style={styles.linkButton}>
              <Text style={styles.linkText}>{HUD_STRINGS.logout}</Text>
            </Pressable>
          </View>
        ) : (
          <View style={styles.centeredBox} testID="hud-loading">
            <ActivityIndicator color={palette.mint} />
            <Text style={styles.centeredText}>{HUD_STRINGS.loading}</Text>
          </View>
        )}
      </View>
    );
  }

  const { pet } = view;
  const profileSub = pet.profile ? profileSubline(pet.profile) : null;
  const locked = view.lock.is_locked;
  const opaqueLock = view.lock.reason === 'game_over' || view.lock.reason === 'inactive';
  const feedDisabled = !view.feeding.can_feed;
  const waterDisabled = !view.water.can_water;
  const walkDisabled = locked;
  const alreadyClean = pet.hygiene_level >= 100 && !pet.needs_cleaning;
  // M5-R02: a chewed slipper is tidied up with its own button — never scrubbed.
  const needsScrub = needsScrubbing(pet.needs_cleaning, view.behaviour);
  const chewingOnly = pet.needs_cleaning && onlyChewingOpen(view.behaviour);
  const cleanDisabled = locked || alreadyClean || chewingOnly;
  // A mess that appears during a training session waits until the game is closed.
  const showCleaning = !locked && !(isTrainingVisible && showTrainingEntry(view.training)) && (needsScrub || (isCleaningOverlayVisible && !chewingOnly));
  const takeOutClock = view.behaviour.take_out;
  const countdown = !locked && takeOutClock !== null ? takeOutCountdown(takeOutClock, serverNow, view.timezone) : null;
  const takeOutDisabled = !view.behaviour.can_take_out;
  // CGP v2: the care that is due right now is the ONE solid-mint button —
  // priority: puppy must go out > a mess to scrub > food.
  const takeOutDue = !takeOutDisabled && countdown?.due === true;
  const cleanDue = !takeOutDue && !cleanDisabled && needsScrub;
  const feedDue = !takeOutDue && !cleanDue && !locked && view.feeding.can_feed;
  const dockIcon = (due: boolean) => (due ? palette.graphite : palette.white);
  const iconSize = hasTakeOut ? 20 : 24;
  const stale = petQuery.isError;
  const albumAvailable = hasAlbum(pet.media);
  const showAlbum = isAlbumVisible && !locked && albumAvailable;
  // M5-R03: "Šola" only for a pet with training (legacy / older app / older server: nothing).
  const hasTraining = showTrainingEntry(view.training);
  // M5-R04: today's feed windows ("nahrani starš" for quiet hours) near the feed button.
  // Profiled pets only (a legacy pet keeps the pre-M5 HUD).
  const mealWindows = pet.profile ? buildMealWindows(view) : [];
  const showTraining = isTrainingVisible && !locked && hasTraining && !showAlbum;
  // Android: TalkBack must not reach the HUD under the album / training game (iOS: accessibilityViewIsModal).
  const hiddenUnderAlbum = showAlbum || showTraining
    ? ({ importantForAccessibility: 'no-hide-descendants', accessibilityElementsHidden: true } as const)
    : ({ importantForAccessibility: 'auto', accessibilityElementsHidden: false } as const);

  return (
    <View style={styles.container}>
      <View style={StyleSheet.absoluteFill} testID="hud-content" {...hiddenUnderAlbum}>
        {/* Full-bleed AI dog (M4-03): state video → idle video → reference image → placeholder */}
        <PetMediaView
          media={pet.media}
          petState={pet.pet_state}
          lockReason={view.lock.reason}
          scene={view.behaviour.scene}
          breed={pet.breed_type}
          // Vet visit / hard stop: the sick / sleeping video keeps playing under the
          // translucent grey lock (PRODUCT_SPEC §7). Paused under the opaque game-over /
          // inactive screen, the walk tracker, the album (one player at a time) and in the background.
          active={!opaqueLock && !isWalkModalVisible && !showAlbum && !showTraining}
          onMediaExpired={onMediaExpired}
          // The vet veil darkens when a substitute (sleeping / idle) stands in for `sick`.
          onVideoStateChange={setHudVideoState}
          variant="hud"
          testID="hud-pet-media"
          placeholder={
            <View style={styles.fallbackViewport}>

              <Animated.View style={[styles.petAvatarWrapper, { transform: [{ translateY: bounceAnim }] }]}>
                <View style={styles.petAvatarCircle}>
                  <Text style={styles.petEmoji}>🐕</Text>
                  <View style={styles.petHeartBadge}>
                    <Heart color={palette.raspberry} fill={palette.raspberry} size={16} />
                  </View>
                </View>

                <View style={styles.petStatusPill}>
                  <Sparkles color={palette.mint} size={14} />
                  <Text style={styles.petStatusPillText}>{HUD_STRINGS.moods[pet.pet_state]}</Text>
                </View>
              </Animated.View>
            </View>
          }
        />

        {/* Glassmorphism top status bar */}
        <View style={[styles.topBar, { top: layout.headerTop }]} onLayout={onHeaderLayout} testID="hud-header">
          <View style={styles.petInfoLeft}>
            <View style={styles.petIconBox}>
              <PawPrint color={palette.mint} size={20} />
            </View>
            <View style={styles.petInfoText}>
              <Text style={styles.petBreedName}>{HUD_STRINGS.breeds[pet.breed_type]}</Text>
              {pet.profile ? (
                <>
                  <Text style={styles.petAgeText} testID="hud-stage">
                    {stageLine(pet.profile)}
                  </Text>
                  {profileSub && (
                    <Text style={styles.petProfileText} numberOfLines={2} ellipsizeMode="tail" testID="hud-profile-sub">
                      {profileSub}
                    </Text>
                  )}
                </>
              ) : (
                // Free plan = sandbox without the 12-week programme (PAYMENTS_SPEC §2): no week label.
                pet.plan.type !== 'free' && <Text style={styles.petAgeText}>{formatChallengeWeek(pet.virtual_age_months)}</Text>
              )}
            </View>
          </View>

          <View style={styles.topBarRight}>
            <WsStatusDot status={wsStatus} />
            {albumAvailable && (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={ALBUM_STRINGS.open}
                testID="hud-album-open"
                style={({ pressed }) => [styles.logoutButton, pressed && styles.pressed]}
                onPress={() => setAlbumVisible(true)}
              >
                <Images color={palette.mint} size={16} />
              </Pressable>
            )}
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={HUD_STRINGS.logout}
              style={({ pressed }) => [styles.logoutButton, pressed && styles.pressed]}
              onPress={handleLogout}
            >
              <LogOut color={palette.n400} size={16} />
            </Pressable>
          </View>
        </View>

        {stale && (
          // Left of the metric column (PR #30 review): never covers the bars.
          <View pointerEvents="none" style={[styles.bannerSlot, { top: layout.bannerTop }]} testID="hud-stale-slot">
            <View style={styles.staleBanner} testID="hud-stale">
              <WifiOff color={palette.warnDark} size={14} />
              <Text style={styles.staleText}>{HUD_STRINGS.stale}</Text>
            </View>
          </View>
        )}

        {/* Feedback toast */}
        {toast && (
          <View pointerEvents="none" style={[styles.bannerSlot, styles.toastSlot, { top: layout.bannerTop + 40 }]} testID="hud-toast-slot">
            <View style={[styles.feedbackToast, toast.tone === 'info' && styles.feedbackToastInfo]} testID="hud-toast">
              <Text style={styles.feedbackText}>{toast.message}</Text>
            </View>
          </View>
        )}

        {/* Right-edge vertical progress sliders — sized to fit between header and dock */}
        {layout.metric.variant !== 'hidden' && (
          <View style={[styles.metricsColumn, { top: layout.metricsTop, gap: layout.metric.gap }]} testID="hud-metrics">
            <MetricBar
              testID="metric-hunger"
              level={pet.hunger_level}
              label={HUD_STRINGS.metrics.hunger}
              sizing={layout.metric}
              icon={<Beef color={palette.white} size={layout.metric.iconSize} />}
            />
            <MetricBar
              testID="metric-thirst"
              level={pet.thirst_level}
              label={HUD_STRINGS.metrics.thirst}
              sizing={layout.metric}
              icon={<Droplet color={palette.white} size={layout.metric.iconSize} />}
            />
            <MetricBar
              testID="metric-energy"
              level={pet.energy_level}
              label={HUD_STRINGS.metrics.energy}
              sizing={layout.metric}
              icon={<Footprints color={palette.white} size={layout.metric.iconSize} />}
            />
            <MetricBar
              testID="metric-hygiene"
              level={pet.hygiene_level}
              label={HUD_STRINGS.metrics.hygiene}
              sizing={layout.metric}
              icon={<Sparkles color={palette.white} size={layout.metric.iconSize} />}
            />
          </View>
        )}

        {/* Bottom floating control dock */}
        <View style={[styles.bottomDock, { bottom: layout.dockBottom }]} onLayout={onDockLayout} testID="hud-dock">
          <ActionButton
            testID="action-feed"
            icon={<Beef color={dockIcon(feedDue)} size={iconSize} />}
            label={HUD_STRINGS.feed}
            onPress={() => runAction('feed')}
            disabled={feedDisabled}
            due={feedDue}
            busy={feed.isPending}
            hint={feedHint(view)}
            compact={hasTakeOut}
          />
          <ActionButton
            testID="action-water"
            icon={<Droplet color={palette.white} size={iconSize} />}
            label={HUD_STRINGS.water}
            onPress={() => runAction('water')}
            disabled={waterDisabled}
            busy={water.isPending}
            hint={waterHint(view)}
            compact={hasTakeOut}
          />
          {hasTakeOut && (
            <ActionButton
              testID="action-take-out"
              icon={<DoorOpen color={dockIcon(takeOutDue)} size={iconSize} />}
              label={HUD_STRINGS.takeOut}
              onPress={() => runAction('take_out')}
              disabled={takeOutDisabled}
              due={takeOutDue}
              busy={takeOut.isPending}
              hint={countdown?.hint ?? null}
              accessibilityHint={countdown?.line}
              compact
            />
          )}
          <ActionButton
            testID="action-walk"
            icon={<Footprints color={palette.white} size={iconSize} />}
            label={HUD_STRINGS.walk}
            onPress={() => setWalkModalVisible(true)}
            disabled={walkDisabled}
            hint={`${formatSteps(view.steps.steps_today)}/${formatSteps(view.steps.goal)}`}
            compact={hasTakeOut}
          />
          <ActionButton
            testID="action-clean"
            icon={<Sparkles color={dockIcon(cleanDue)} size={iconSize} />}
            label={HUD_STRINGS.clean}
            onPress={() => setCleaningOverlayVisible(true)}
            disabled={cleanDisabled}
            due={cleanDue}
            busy={clean.isPending}
            hint={cleanHint(view)}
            compact={hasTakeOut}
          />
        </View>

        {/* M5-R02: puppy countdown and the scene of an open accident / chewed slipper, above the dock */}
        {!locked && (
          <BehaviourPanel
            behaviour={view.behaviour}
            countdown={countdown}
            videoState={hudVideoState}
            onResolveChewing={() => runAction('resolve_chewing')}
            resolveBusy={resolveChewing.isPending}
            bottom={layout.aboveDock}
            right={METRICS_RESERVED_RIGHT}
            footer={
              hasTraining ? (
                <TrainingChip
                  todayDone={view.training.today_done}
                  pending={showTrainingDot(view.training)}
                  onPress={() => setTrainingVisible(true)} />
              ) : null
            }
            // Hidden by the panel while a scene card is open; one line on smaller screens.
            meals={
              mealWindows.length > 0 ? <MealWindowsRow items={mealWindows} compact={layout.metric.variant !== 'regular'} /> : null
            }
          />
        )}

        {/* Conditional overlays (the lock overlay is rendered by AppNavigator above this screen) */}
        {isWalkModalVisible && !locked && (
          <WalkTrackerOverlay
            view={view}
            stepSync={stepSync}
            onClose={() => {
              setWalkModalVisible(false);
              // Back from a walk: send the new steps now, not in up to 5 minutes.
              void stepSync.syncNow();
            }}
          />
        )}
        {showCleaning && (
          <CleaningOverlay
            onCleaned={handleCleaned}
            onClose={needsScrub ? undefined : () => setCleaningOverlayVisible(false)}
            mess={cleaningMess(view.behaviour)}
          />
        )}
      </View>
      {/* Outside hud-content, so hiding the HUD from TalkBack never hides the game. */}
      {showTraining && <TrainingOverlay view={view} onClose={closeTraining} />}
      {showAlbum && (
        <PetAlbum
          media={pet.media}
          onClose={() => setAlbumVisible(false)}
          onMediaExpired={onMediaExpired}
          growth={growth}
          timeZone={view.timezone}
          onGrowthExpired={onGrowthExpired}
          testID="hud-album"
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: palette.graphite,
  },
  fallbackViewport: {
    ...StyleSheet.absoluteFill,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: palette.graphite,
  },
  petAvatarWrapper: {
    alignItems: 'center',
    justifyContent: 'center',
    gap: 16,
  },
  petAvatarCircle: {
    width: 150,
    height: 150,
    borderRadius: 75,
    backgroundColor: alpha(palette.white, 0.08),
    borderWidth: 2,
    borderColor: alpha(palette.white, 0.2),
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: palette.mint,
    shadowOffset: { width: 0, height: 10 },
    shadowOpacity: 0.25,
    shadowRadius: 20,
    elevation: 10,
  },
  petEmoji: {
    fontSize: 72,
  },
  petHeartBadge: {
    position: 'absolute',
    top: 4,
    right: 4,
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: alpha(palette.graphite, 0.9),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.2),
    alignItems: 'center',
    justifyContent: 'center',
  },
  petStatusPill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderRadius: 20,
    backgroundColor: alpha(palette.graphite, 0.8),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
  },
  petStatusPillText: {
    fontSize: 13,
    fontWeight: '700',
    color: palette.white,
  },
  topBar: {
    position: 'absolute',
    left: 16,
    right: 16,
    zIndex: 20,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
    borderRadius: 20,
    paddingHorizontal: 16,
    paddingVertical: 12,
    shadowColor: palette.black,
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.4,
    shadowRadius: 12,
  },
  petInfoLeft: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    flex: 1,
  },
  petIconBox: {
    width: 38,
    height: 38,
    borderRadius: 12,
    backgroundColor: alpha(palette.white, 0.1),
    alignItems: 'center',
    justifyContent: 'center',
  },
  petBreedName: {
    fontSize: 16,
    fontWeight: '800',
    textTransform: 'capitalize',
    color: palette.white,
  },
  petInfoText: { flex: 1, minWidth: 0 },
  petProfileText: {
    marginTop: 2,
    fontSize: 11,
    color: alpha(palette.white, 0.7),
  },
  petAgeText: {
    fontSize: 10,
    fontWeight: '600',
    textTransform: 'uppercase',
    letterSpacing: 0.8,
    color: alpha(palette.white, 0.55),
  },
  topBarRight: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
  },
  wsRow: {
    flexDirection: 'row',
    alignItems: 'center',
    minHeight: 20,
    gap: 5,
    paddingHorizontal: 8,
    paddingVertical: 4,
    borderRadius: 8,
    backgroundColor: alpha(palette.white, 0.06),
  },
  wsDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
  },
  wsConnected: {
    backgroundColor: palette.okDark,
  },
  wsConnecting: {
    backgroundColor: palette.warnDark,
  },
  wsText: {
    fontSize: 9,
    fontWeight: '700',
    color: palette.n400,
  },
  logoutButton: {
    width: 34,
    height: 34,
    borderRadius: 10,
    backgroundColor: alpha(palette.white, 0.08),
    alignItems: 'center',
    justifyContent: 'center',
  },
  /** Row between the screen's left edge and the metric column; centres its pill. */
  bannerSlot: {
    position: 'absolute',
    left: 16,
    right: METRICS_RESERVED_RIGHT,
    zIndex: 25,
    alignItems: 'center',
  },
  toastSlot: {
    zIndex: 30,
  },
  feedbackToast: {
    maxWidth: '100%',
    backgroundColor: palette.mint,
    paddingHorizontal: 20,
    paddingVertical: 10,
    borderRadius: 20,
    shadowColor: palette.mint,
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.3,
    shadowRadius: 10,
  },
  feedbackText: {
    textAlign: 'center',
    color: palette.graphite,
    fontWeight: '700',
    fontSize: 14,
  },
  metricsColumn: {
    position: 'absolute',
    right: METRICS_RIGHT,
    zIndex: 20,
  },
  bottomDock: {
    position: 'absolute',
    left: 20,
    right: 20,
    zIndex: 20,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-around',
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
    borderRadius: 30,
    paddingVertical: 14,
    paddingHorizontal: 12,
    shadowColor: palette.black,
    shadowOffset: { width: 0, height: 8 },
    shadowOpacity: 0.5,
    shadowRadius: 16,
  },
  pressed: {
    opacity: 0.7,
  },
  centered: {
    alignItems: 'center',
    justifyContent: 'center',
  },
  centeredBox: {
    alignItems: 'center',
    gap: 14,
    paddingHorizontal: 32,
  },
  centeredText: {
    color: palette.n300,
    fontSize: 15,
    fontWeight: '600',
    textAlign: 'center',
  },
  centeredHint: {
    color: palette.n400,
    fontSize: 13,
    textAlign: 'center',
  },
  retryButton: {
    marginTop: 4,
    paddingHorizontal: 24,
    paddingVertical: 12,
    borderRadius: radius.button,
    backgroundColor: palette.mint,
  },
  retryText: {
    color: palette.graphite,
    fontWeight: '700',
    fontSize: 15,
  },
  linkButton: {
    padding: 8,
  },
  linkText: {
    color: palette.n400,
    fontSize: 13,
    fontWeight: '600',
  },
  staleBanner: {
    maxWidth: '100%',
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 12,
    backgroundColor: alpha(palette.graphite, 0.9),
    borderWidth: 1,
    borderColor: alpha(palette.warnDark, 0.35),
  },
  staleText: {
    flexShrink: 1,
    color: palette.warnDark,
    fontSize: 11,
    fontWeight: '600',
  },
  feedbackToastInfo: {
    backgroundColor: palette.warn,
    shadowColor: palette.warn,
  },
});
