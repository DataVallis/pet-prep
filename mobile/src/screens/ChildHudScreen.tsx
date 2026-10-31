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
 * David 2026-10-25 »pes mora biti viden«: every card / chip / note / toast below sits in ONE compact
 * stack directly under the header, left of the metric column (`hud-top-stack`; order and the "+N"
 * overflow in `modules/hud/topStack.ts`) — only the action dock stays at the bottom, the dog's
 * centre and lower half stay free. "Above the dock" in the notes below now means "in the top stack".
 * M5-R03: a pet with training gets a "Šola" chip above the dock (amber dot while today's
 * session is open) that opens the training mini-game (`modules/training/TrainingOverlay`).
 * M5-R04: "Obroki danes" chip row above the dock (today's windows, ✓, now, "nahrani starš")
 * and the growth section of the album ("Kako je kuža rasel", fetched only while it is open).
 * M5-F05: the header is one short line; tapping it opens `PetProfileSheet` (breed, stage,
 * age, origin, next stage, meals per day). M5-F06: "Kuža se pripravlja …" sits at the top
 * of the above-dock column (never under the meals row or the dock).
 * M5-R05: a pet with play gets "Igra" next to "Šola" (disabled with its reason while the
 * server says no), the dog's invitation card instead of it while one is offered, the play
 * layer (`modules/play/PlayOverlay`: ball game / cuddles) and the 30-minute happy mood —
 * the `playing` video when stored, else the usual video with soft hearts — plus
 * "Kuža je vesel". Mood only: nothing here changes metrics or points.
 * M5-R06-08b: a cat (`pet.species`) gets its own dock (`modules/catCare/catHud#dockKinds`: food,
 * water, "Pesek", "Igra" with the feather wand, clean — no walk, no steps, no Health card, no
 * "Šola"), the meter "Igra", chips "Počeši" (Maine Coon) / "Menjava peska" / "Crkljanje", "Na
 * praskalnik" on the scratched-sofa card, the "first aid" note while hungry (C24) and the cat
 * mini-games (`CatCareOverlay`, opened with `openCatGame`). Child texts switch to the cat's
 * (`setTextSpecies`). A dog's HUD is unchanged.
 * M5-R09c »Ali si vedel?«: a pedigree pet with approved facts gets the book button in the header
 * (raspberry dot while a fact is unread) → »Moja pasma«; the card opens by itself in a calm moment
 * after a care success (`modules/breedFacts/useFactMoment`), otherwise the chip »Nekaj novega o
 * tvoji pasmi« sits above the dock. A mutt / domestic cat / breed without facts sees nothing new.
 */

import { useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Animated, Pressable, StyleSheet, useWindowDimensions, View, type LayoutChangeEvent } from 'react-native';
import { Text } from '@/components/ui/Text';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import {
  Beef,
  Check,
  DoorOpen,
  X,
  Droplet,
  Feather,
  Footprints,
  Shovel,
  Heart,
  ChevronDown,
  PawPrint,
  RefreshCw,
  Sparkles,
  WifiOff,
  Settings,
  Award,
  BookOpen,
  HandHeart,
  Hourglass,
  Info,
  Moon,
  Sun,
  Utensils,
} from 'lucide-react-native';
import { useQueryClient } from '@tanstack/react-query';

import { useAppStore, type WebSocketStatus } from '@/store/appStore';
import { rememberFamilyTimezone } from '@/modules/session/familyTimezone';
import { usePetWebSocket } from '@/hooks/usePetWebSocket';
import ServerUpdatingBanner, { SERVER_UPDATING_STRINGS } from '@/components/ServerUpdatingBanner';
import { useLongOutage, useServerOutage } from '@/store/serverStatusStore';
import { applyBroadcastToCache, childPetKey, isRecoverableError, useChildPet } from '@/hooks/queries/useChildPet';
import { useClean, useFeed, useResolveChewing, useTakeOut, useWater } from '@/hooks/queries/useChildActions';
import CatCareOverlay from '@/modules/catCare/CatCareOverlay';
import { CareTaskChip } from '@/modules/careTasks/CareTaskChip';
import { CareTaskOverlayHost } from '@/modules/careTasks/CareTaskOverlay';
import HeatNote from '@/modules/careTasks/HeatNote';
import { heatWarningActive, showCareTaskChip } from '@/modules/careTasks/careTasks';
import { useHeatClock } from '@/modules/careTasks/useHeatClock';
import { CatHudChip, CatHudNote, FirstAidNote } from '@/modules/catCare/CatHudChips';
import SocialisationSheet, { SocialisationChip } from '@/modules/socialisation/SocialisationSheet';
import { showSocialisationChip } from '@/modules/socialisation/socialisation';
import {
  CAT_HUD_STRINGS,
  careChips,
  dockKinds,
  isCatView,
  scoopDock,
  showFirstAid,
  ownCatGame,
  showScratcherButton,
  wandDock,
  type DockKind,
} from '@/modules/catCare/catHud';
import { childPetGrowthKey, useChildPetGrowth, useGrowthRefresh } from '@/hooks/queries/usePetGrowth';
import { useServerNow } from '@/hooks/useServerNow';
import {
  classifyActionError,
  cleanHint,
  failureMessage,
  feedDockHint,
  feedLabel,
  confirmationNext,
  feedSuccessMessage,
  successMessage,
  waterDockHint,
} from '@/modules/childPet/actionMessages';
import { useMessageQueue } from '@/modules/childPet/messageQueue';
import PetPreparationScreen from '@/modules/preparation/PetPreparationScreen';
import { NO_PREPARATION, readyMessage, showPreparationScreen } from '@/modules/preparation/preparation';
import { useReadyAnnouncement } from '@/modules/preparation/readyAnnounced';
import { usePreparationScreen } from '@/modules/preparation/usePreparationScreen';
import {
  BEHAVIOUR_STRINGS,
  cleaningMess,
  needsScrubbing,
  onlyChewingOpen,
  takeOutCountdown,
} from '@/modules/behaviour/behaviour';
import BehaviourPanel, { type StackCard } from '@/components/BehaviourPanel';
import MealWindowsRow from '@/components/MealWindowsRow';
import { buildMealWindows, MEAL_STRINGS } from '@/modules/childPet/mealWindows';
import { lockStateFromView, normalizeChildState, type CareAction, type ChildPetView } from '@/modules/childPet/childPetView';
import { computeHudLayout, METRICS_RESERVED_RIGHT, METRICS_RIGHT, type HudLayout } from '@/modules/hud/hudLayout';
import { STACK_PRIORITY } from '@/modules/hud/topStack';
import { WS_BADGE_STRINGS, wsBadge } from '@/modules/hud/wsBadge';
import { formatSteps } from '@/modules/steps/stepCounter';
import { useStepSync } from '@/modules/steps/useStepSync';
import { useChildExit } from '@/modules/session/useChildExit';
import ParentalGate, { PARENTAL_GATE_STRINGS } from '@/components/ParentalGate';
import ActionButton from '@/components/ActionButton';
import PetAlbum from '@/components/PetAlbum';
import { ALBUM_STRINGS, hasAlbum } from '@/modules/petMedia/album';
import { isMediaPending } from '@/modules/petMedia/petMedia';
import CleaningOverlay from '@/components/CleaningOverlay';
import { loadMessGames } from '@/modules/cleaning/loadMessGames';
import { messStages } from '@/modules/cleaning/messPlan';
import MetricBar from '@/components/MetricBar';
import PetMediaView, { MediaPendingNotice, PET_MEDIA_STRINGS } from '@/components/PetMediaView';
import WalkTrackerOverlay from '@/modules/walk/WalkTrackerOverlay';
import { usePushPromptOnFirstView } from '@/modules/push/usePushPromptOnFirstView';
import { nextStageLine, originLine, stageLine } from '@/modules/petProfile/petProfile';
import { profileHeaderA11y, profileSheetRows } from '@/modules/petProfile/profileSheet';
import PetProfileSheet from '@/components/PetProfileSheet';
import CareGuideSheet from '@/modules/careGuide/CareGuideSheet';
import { useCareGuide } from '@/modules/careGuide/careGuideSeen';
import BirthdayCard from '@/modules/birthday/BirthdayCard';
import BirthdayHat from '@/modules/birthday/BirthdayHat';
import { showBirthdayHat } from '@/modules/birthday/birthday';
import { useBirthdayCard } from '@/modules/birthday/birthdaySeen';
import ChildSettingsSheet from '@/components/ChildSettingsSheet';
import WishesSheet from '@/modules/wishes/WishesSheet';
import { TreatChip, TreatClip, treatErrorText } from '@/modules/wishes/TreatChip';
import { showTreatButton } from '@/modules/wishes/wishes';
import { useGiveTreat } from '@/hooks/queries/useWishes';

import { mediaModeOf } from '@/modules/display/display';
import ChildCertificateSheet from '@/components/ChildCertificateSheet';
import { CERTIFICATE_CHILD_STRINGS, childCertificateEntry } from '@/modules/certificate/certificate';
import TrainingChip from '@/modules/training/TrainingChip';
import TrainingOverlay from '@/modules/training/TrainingOverlay';
import { showTrainingDot, showTrainingEntry } from '@/modules/training/training';
import { usePlay } from '@/hooks/queries/usePlay';
import { useReduceMotion } from '@/hooks/useReduceMotion';
import { HappyBadge, PlayBlockedNote, PlayChip, PlayInvitationCard } from '@/modules/play/PlayChip';
import HeartsLayer from '@/modules/play/HeartsLayer';
import PlayOverlay from '@/modules/play/PlayOverlay';
import { invitationTexts, MOOD_STRINGS, moodSceneAt, playBlockText, playEntry, playRefusalMessage, type PlayKind } from '@/modules/play/play';
import { actionRules } from '@/modules/childPet/actionRules';
import { usePlayClock } from '@/modules/play/usePlayClock';
import DigestionNote from '@/modules/digestion/DigestionNote';
import { digestionNote } from '@/modules/digestion/digestion';
import { useDigestionClock } from '@/modules/digestion/useDigestionClock';
import type { PetState, PetUpdatedBroadcast } from '@/types';
import type { CatGameKind } from '@/modules/catCare/catCare';
import { breedName } from '@/modules/species/species';
import { alpha, palette, radius } from '@/theme';
import { setTextSpecies, t } from '@/i18n';
import { strings } from '@/i18n/strings';
import { isolatePetName } from '@/modules/petName/petName';
import { useTranslation } from 'react-i18next';
import { useFactMoment } from '@/modules/breedFacts/useFactMoment';
import FactSheet from '@/modules/breedFacts/FactSheet';
import FactChip from '@/modules/breedFacts/FactChip';
import BreedGuideScreen from '@/modules/breedFacts/BreedGuideScreen';

/** M6-W04: hearts over the pet after a treat without a clip. */
const TREAT_HEARTS_MS = 3_000;

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

/** Every mood has a label (compile-time check). Breed names: `breedName()` (`family:breeds`, M5-R06-02). */
const _hudTexts: { moods: Record<PetState, string> } = HUD_STRINGS;
void _hudTexts;

const CHALLENGE_WEEKS = 12;
/** Hit area of the HUD message's "×" (≥ 44 pt, David 2026-10-21). */
export const FEEDBACK_CLOSE_SIZE = 44;

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

/** M5-R08: the header's screen-reader label starts with the pet's name (unchanged without one). */
function headerA11y(name: string | null, profile: string): string {
  return name === null ? profile : `${name}, ${profile}`;
}

/** HUD second line of a profiled pet: "Posvojen iz zavetišča · 24. 11. 2026 postane mlad pes". */
function profileSubline(profile: NonNullable<ChildPetView['pet']['profile']>): string | null {
  const parts = [originLine(profile), nextStageLine(profile, 'child')].filter((x): x is string => x !== null);
  return parts.length > 0 ? parts.join(' · ') : null;
}


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
      challengeFinished: view.certificate !== null,
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
  const playOverlay = useAppStore((s) => s.playOverlay);
  const setPlayOverlay = useAppStore((s) => s.setPlayOverlay);
  // M5-R06-08b: the cat mini-game open over the HUD (one at a time).
  const catOverlay = useAppStore((s) => s.catOverlay);
  const openCatGame = useAppStore((s) => s.openCatGame);
  const closeCatGame = useAppStore((s) => s.closeCatGame);
  const abandonedCatSessions = useAppStore((s) => s.abandonedCatSessions);
  // M5-R14 / R15 / R19 / R20: the breed care mini-game open over the HUD.
  const careTaskOverlay = useAppStore((s) => s.careTaskOverlay);
  const openCareTask = useAppStore((s) => s.openCareTask);
  const setHudVideoState = useAppStore((s) => s.setHudVideoState);
  const hudVideoState = useAppStore((s) => s.hudVideoState);
  // No HUD → no video under the lock veil.
  useEffect(() => () => setHudVideoState(null), [setHudVideoState]);

  const queryClient = useQueryClient();
  const { layout, onHeaderLayout, onDockLayout } = useHudLayout();
  const petQuery = useChildPet();
  const view = petQuery.data;
  // M5-R06-08b: child texts follow the pet's species ("muca" for a cat). Set while rendering
  // (idempotent), so this very render already reads the cat's texts; else the session pet's.
  setTextSpecies(view?.pet.species ?? sessionPet?.species ?? null);
  const cat = view !== undefined && isCatView(view);
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
  // M5-F13: after a socket reconnect (deploy, network) broadcasts of the gap are lost → refetch.
  // `cancelRefetch: false`: a fetch already running (recovery refetch) is reused, not restarted.
  const onResubscribed = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: childPetKey }, { cancelRefetch: false });
  }, [queryClient]);
  usePetWebSocket(view?.pet.id ?? sessionPet?.id ?? null, onBroadcast, onResubscribed);
  // M5-F13: the server is updating (deploy) — a calm banner instead of error states.
  const serverUpdating = useServerOutage();
  const longOutage = useLongOutage();

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
      // M5-R05: and a game (nothing is reported; the lock screen explains).
      setPlayOverlay(null);
      // M5-R06-08b: and a cat game (the server refuses it while locked; no penalty).
      closeCatGame();
    }
  }, [lockedNow, setAlbumVisible, setTrainingVisible, setPlayOverlay, closeCatGame]);
  useEffect(
    () => () => {
      setAlbumVisible(false);
      setTrainingVisible(false);
      setPlayOverlay(null);
      closeCatGame();
    },
    [setAlbumVisible, setTrainingVisible, setPlayOverlay, closeCatGame],
  );

  // M5-R05 play & cuddle: the clock of the happy scene / invitation, "Mogoče kasneje".
  const playNow = usePlayClock(view);
  // M5-R16: the "wait with the walk — digesting" note ends by the clock (one timer).
  const digestionNow = useDigestionClock(view);
  // M5-R14: re-render when the summer midday heat window opens / closes.
  const heatNow = useHeatClock(view);
  const reduceMotion = useReduceMotion();
  const play = usePlay();
  // "Mogoče kasneje" lives in the session store (survives the HUD remounting).
  const dismissedIds = useAppStore((s) => s.dismissedPlayInvitations);
  const dismissInvitation = useAppStore((s) => s.dismissPlayInvitation);
  const dismissedInvitations = useMemo<ReadonlySet<number>>(() => new Set(dismissedIds), [dismissedIds]);
  // Another layer (walk, cleaning, album, training) or a mess to scrub closes the play layer
  // (like a lock does) — otherwise it would reappear when that layer closes.
  const messToScrub = view ? needsScrubbing(view.pet.needs_cleaning, view.behaviour) : false;
  const otherLayerOpen =
    isWalkModalVisible || isCleaningOverlayVisible || isAlbumVisible || isTrainingVisible || catOverlay !== null || careTaskOverlay !== null || messToScrub;
  useEffect(() => {
    if (otherLayerOpen) setPlayOverlay(null);
  }, [otherLayerOpen, setPlayOverlay]);
  // End of quiet hours as a 422 named it (the child state doesn't carry it) → "Kuža spi do 7:00.".
  const [playSleepsUntil, setPlaySleepsUntil] = useState<string | null>(null);
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
  // M5-R06-08b: the same for the child's own running cat game (once per session; a game the
  // child stopped — `abandonedCatSessions` — never reopens).
  const ownCat = view ? ownCatGame(view) : null;
  const ownCatKind = ownCat?.kind ?? null;
  const ownCatId = ownCat?.id ?? null;
  const resumedCatSessions = useRef<Set<string>>(new Set());
  useEffect(() => {
    if (ownCatKind === null || ownCatId === null || lockedNow) return;
    if (resumedCatSessions.current.has(ownCatId) || abandonedCatSessions.includes(ownCatId)) return;
    resumedCatSessions.current.add(ownCatId);
    openCatGame(ownCatKind);
  }, [ownCatKind, ownCatId, lockedNow, abandonedCatSessions, openCatGame]);

  const stepSync = useStepSync({
    // M5-R06-08b: a cat has no steps (the server refuses them, CAT_SPEC §5.3) — never ask
    // for the step permission or sync.
    enabled: view !== undefined && !view.lock.is_locked && !cat,
    // M5-T12: the device part (without parent-logged walk minutes) gates syncs and the counter.
    myStepsToday: view?.steps.my_device_steps_today ?? 0,
    serverTime: view?.server_time ?? null,
    timezone: view?.timezone ?? null,
    // M5-T12: the parent's adapted mode — wheelchair pushes from the health store.
    adaptedMode: view?.steps.adapted_mode ?? false,
  });

  // M5-T03 (spec §12): one message at a time, FIFO, ≥ 2.5 s + 60 ms per character, tap closes.
  const messages = useMessageQueue();
  const toast = messages.current;
  const showToast = messages.show;
  // M5-F05: the header shows one short line; a tap opens the full profile.
  const [isProfileVisible, setProfileVisible] = useState(false);
  /** David 2026-10-12: the child can change the app language from the HUD, not only before sign-in. */
  const [isSettingsVisible, setSettingsVisible] = useState(false);
  // M6-W04 »Želje«: the catalogue sheet (UI state) and the treat moment (clip or brief hearts).
  const [isWishesVisible, setWishesVisible] = useState(false);
  const [treatClip, setTreatClip] = useState<string | null>(null);
  const [treatHearts, setTreatHearts] = useState(false);
  const giveTreat = useGiveTreat();
  useEffect(() => {
    if (!treatHearts) return;
    const id = setTimeout(() => setTreatHearts(false), TREAT_HEARTS_MS);
    return () => clearTimeout(id);
  }, [treatHearts]);
  const closeTreatClip = useCallback(() => setTreatClip(null), []);
  const handleTreat = useCallback(() => {
    giveTreat.mutate(undefined, {
      onSuccess: (result) => {
        if (result.clipUrl !== null) setTreatClip(result.clipUrl);
        else setTreatHearts(true);
        showToast({ tone: 'ok', message: t('wishes:child.treat.given') });
      },
      onError: (error) => {
        showToast({ tone: 'info', message: treatErrorText(error, queryClient.getQueryData<ChildPetView>(childPetKey) ?? null) });
      },
    });
  }, [giveTreat, queryClient, showToast]);
  // M2-01d: the finished challenge (certificate / "the parent will prepare it" / kind note).
  const [isCertificateVisible, setCertificateVisible] = useState(false);
  // GAMES_SPEC §6.2: the slipper game (native layer only); a lock closes it.
  const [slipperOpen, setSlipperOpen] = useState(false);
  // David 2026-10-31: the escape from a forced mess game (its × after GAME_ESCAPE_MS, Android
  // back, the watchdog) → this cleaning continues on the plain tap overlay (still must be cleaned).
  const [messEscaped, setMessEscaped] = useState(false);
  useEffect(() => {
    if (lockedNow) setSlipperOpen(false);
  }, [lockedNow]);
  // M5-R17: the puppy's weekly "Nova izkušnja" card (UI-only state, like the language sheet).
  const [isSocialisationVisible, setSocialisationVisible] = useState(false);
  // M5-T05: the care guide »Kako skrbim za …« — opens by itself once per pet on this device,
  // then from the »?« button (never over a lock).
  // M5-T06: the set-up screen covers the HUD (capped: a clip that hangs never blocks the pet).
  const preparingNow = usePreparationScreen(view?.pet.id ?? null, view ? showPreparationScreen(view.pet.preparation, view.lock.is_locked) : false);
  const careGuide = useCareGuide(view?.pet.id ?? null, view?.care_guide != null && !view.lock.is_locked && !preparingNow);
  // M5-T13 (spec §9): the first birthday — party hat all day, the card opens by itself once per pet.
  const birthdayToday = view ? showBirthdayHat(view.pet.birthday, view.lock.is_locked) && !preparingNow : false;
  const birthdayCard = useBirthdayCard(view?.pet.id ?? null, birthdayToday && !careGuide.visible);
  // M5-T06 (spec §13): »Vse je pripravljeno: [ime]« once per pet when the shown preparation ends
  // (the server also sends one push). Through the message queue like every HUD message.
  const skewMs = view?.clockSkewMs ?? 0;
  const readyNow = useCallback(() => Date.now() + skewMs, [skewMs]);
  const readyName = view?.pet.name ?? null;
  const readySpecies = view?.pet.species ?? null;
  useReadyAnnouncement(view?.pet.id ?? null, view?.pet.preparation ?? NO_PREPARATION, view?.lock.is_locked ?? true, readyNow, () =>
    showToast({ tone: 'ok', message: readyMessage(readyName, readySpecies) }),
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
        const after = normalizeChildState(response.state);
        const message =
          action === 'feed'
            ? feedSuccessMessage(response.status, response.feed_mode, after, response.covers_window)
            : successMessage(action, response.status);
        // M5-T01 (spec §4): an accepted care is a ✓ confirmation with "next time" where the state knows it.
        const accepted = response.status === 'accepted';
        showToast({ tone: 'ok', message, confirm: accepted, next: confirmationNext(action, response.status, after, response.feed_mode) });
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
      onSuccess: (response) =>
        showToast({ tone: 'ok', message: successMessage('clean', response.status), confirm: response.status === 'accepted' }),
      onError: (error) => {
        const message = failureMessage(
          classifyActionError(error),
          queryClient.getQueryData<ChildPetView>(childPetKey) ?? null,
        );
        if (message) showToast({ tone: 'info', message });
      },
    });
  }, [clean, queryClient, setCleaningOverlayVisible, showToast]);

  // M5-R05: a finished mini-game → one request; optimistic happy dog, then the server's state.
  const handlePlayFinished = useCallback(
    (kind: PlayKind) => {
      play.mutate(kind, {
        onError: (error) => {
          const failure = classifyActionError(error);
          const current = queryClient.getQueryData<ChildPetView>(childPetKey) ?? null;
          // m3 (QA PR #86): no thank-you card for a play the server never got.
          setPlayOverlay(null);
          if (failure.kind === 'refused') {
            if (failure.nextAllowedAt) setPlaySleepsUntil(failure.nextAllowedAt);
            showToast({ tone: 'info', message: playRefusalMessage(failure.nextAllowedAt, current?.timezone ?? null, current) });
            return;
          }
          // A lock closes the layer through the lock effect; the rest gets the usual text.
          const message = failureMessage(failure, current);
          if (message) showToast({ tone: 'info', message });
        },
      });
    },
    [play, queryClient, setPlayOverlay, showToast],
  );

  // David 2026-10-14: the child can't sign out by accident — the logout goes through the
  // parental gate (a lost session needs the parent's new PIN). M2-11: in a shared session
  // (»Igraj kot« on the parent's phone) the same button opens the parent login.
  const childExit = useChildExit();
  const handleLogout = childExit.open;

  // M5-R09c »Ali si vedel?«: never over a game, a layer or another sheet; the chip waits under a layer.
  const dockLayerOpen =
    isWalkModalVisible || isCleaningOverlayVisible || isAlbumVisible || isTrainingVisible || playOverlay !== null || catOverlay !== null || careTaskOverlay !== null || messToScrub;
  const facts = useFactMoment({
    view,
    // M5-T03: the card also waits until the message queue is empty (care confirmation first).
    overlayOpen: dockLayerOpen || isProfileVisible || isSettingsVisible || isCertificateVisible || isSocialisationVisible || careGuide.visible || birthdayCard.visible || toast !== null || preparingNow,
    dockCovered: dockLayerOpen,
  });

  // ── Loading / error without any state yet ────────────────────
  if (!view) {
    return (
      <View style={[styles.container, styles.centered]}>
        {serverUpdating ? (
          // M5-F13: no state yet and the server is updating — wait calmly, it loads by itself.
          <View style={styles.centeredBox} testID="hud-updating">
            <ActivityIndicator color={palette.mint} />
            <Text style={styles.centeredText}>{SERVER_UPDATING_STRINGS.title}</Text>
            {longOutage && (
              // QA m4: a deploy that doesn't end (or lost internet) is never a dead end.
              <View style={styles.centeredBox} testID="hud-updating-exit">
                <Pressable
                  accessibilityRole="button"
                  style={({ pressed }) => [styles.retryButton, pressed && styles.pressed]}
                  onPress={() => {
                    void petQuery.refetch();
                  }}
                >
                  <Text style={styles.retryText}>{HUD_STRINGS.retry}</Text>
                </Pressable>
                <Pressable accessibilityRole="button" onPress={handleLogout} style={styles.linkButton} testID="hud-updating-logout">
                  <Text style={styles.linkText}>{HUD_STRINGS.logout}</Text>
                </Pressable>
                <ParentalGate {...childExit.gateProps} />
              </View>
            )}
          </View>
        ) : petQuery.isError ? (
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
            <Pressable accessibilityRole="button" onPress={handleLogout} style={styles.linkButton} testID="hud-error-logout">
              <Text style={styles.linkText}>{HUD_STRINGS.logout}</Text>
            </Pressable>
            <ParentalGate {...childExit.gateProps} />
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
  const profileRows = pet.profile ? profileSheetRows(breedName(pet.breed_type, pet.species), pet.profile) : [];
  const locked = view.lock.is_locked;
  const opaqueLock = view.lock.reason === 'game_over' || view.lock.reason === 'inactive';
  // M5-T02: one rules table for every HUD action (server flags + the sleeping / lock reason).
  const rules = actionRules(view);
  const feedDisabled = !rules.feed.enabled;
  const waterDisabled = !rules.water.enabled;
  const walkDisabled = !rules.walk.enabled;
  const alreadyClean = pet.hygiene_level >= 100 && !pet.needs_cleaning;
  // M5-R02: a chewed slipper is tidied up with its own button — never scrubbed.
  // M5-R06-08b: nor the cat's scratched sofa ("Na praskalnik").
  const needsScrub = needsScrubbing(pet.needs_cleaning, view.behaviour);
  const nothingToScrub = pet.needs_cleaning && !needsScrub;
  const chewingOnly = pet.needs_cleaning && onlyChewingOpen(view.behaviour);
  const cleanDisabled = locked || alreadyClean || chewingOnly || nothingToScrub;
  const catGameOpen = (catOverlay !== null || careTaskOverlay !== null) && !locked;
  // A mess that appears during a training session / cat game waits until the game is closed.
  const showCleaning =
    !locked &&
    !(isTrainingVisible && showTrainingEntry(view.training)) &&
    !catGameOpen &&
    (needsScrub || (isCleaningOverlayVisible && !chewingOnly && !nothingToScrub));
  // GAMES_SPEC §6.2 / §7.4: on a binary with the native game layer the bag / cloth / box games
  // replace the tap overlay and the instant »Pospravi in daj igračo« (lazy, null on older binaries).
  const messGames = loadMessGames();
  // A finished / closed cleaning forgets the escape: the next mess opens its game again.
  if (!showCleaning && messEscaped) setMessEscaped(false);
  const messGameShown = showCleaning && messGames !== null && !messEscaped;
  const takeOutClock = view.behaviour.take_out;
  const countdown = !locked && takeOutClock !== null ? takeOutCountdown(takeOutClock, serverNow, view.timezone) : null;
  const takeOutDisabled = !rules.take_out.enabled;
  // M5-R06-08b: the dock per species (a dog's is unchanged; a cat's has "Pesek" + "Igra").
  const dock = dockKinds(view);
  const compactDock = dock.length >= 5;
  const scoop = cat ? scoopDock(view) : null;
  const wand = cat ? wandDock(view) : null;
  // CGP v2: the care that is due right now is the ONE solid-mint button —
  // priority: puppy must go out > a mess to scrub > the cat's litter > food.
  const takeOutDue = !takeOutDisabled && countdown?.due === true;
  const cleanDue = !takeOutDue && !cleanDisabled && needsScrub;
  const scoopDue = !takeOutDue && !cleanDue && scoop !== null && scoop.due && !scoop.disabled;
  const feedDue = !takeOutDue && !cleanDue && !scoopDue && !locked && view.feeding.can_feed;
  const dockIcon = (due: boolean) => (due ? palette.graphite : palette.white);
  const iconSize = compactDock ? 20 : 24;
  // The deploy banner replaces the amber "no connection" one (never both).
  const stale = petQuery.isError && !serverUpdating;
  const albumAvailable = hasAlbum(pet.media);
  // M5-T06: the set-up screen replaces the old »Kuža se pripravlja…« pill while it shows.
  const mediaPending = isMediaPending(pet.media) && !preparingNow;
  const showAlbum = isAlbumVisible && !locked && albumAvailable;
  // M5-R03: "Šola" only for a pet with training (legacy / older app / older server: nothing).
  // A cat never has dog school (CAT_SPEC Q10).
  const hasTraining = !cat && showTrainingEntry(view.training);
  // M5-T02 (David 2026-10-20): while the pet sleeps "Šola" and "Igra" stay visible but off,
  // with one note »💤 Kuža spi do 7:00« (other blocks: the school overlay explains them).
  // M5-T02b (David 2026-10-21): the same for cuddling ("Crkljanje") and brushing ("Počeši").
  const trainingSleeps = hasTraining && rules.training.reason === 'sleeping';
  // David 2026-10-28 (home first): the school stays locked until its clips are there — the chip
  // is off with a calm »Šola se še pripravlja …« card (never a word about pictures or videos).
  const trainingPreparing = hasTraining && rules.training.reason === 'preparing';
  const schoolPreparingNote = trainingPreparing ? rules.training.text : undefined;
  const playSleeps = rules.play.reason === 'sleeping';
  const groomingSleeps = rules.grooming.reason === 'sleeping';
  const sleepNote = trainingSleeps
    ? rules.training.text
    : playSleeps
      ? rules.play.text
      : groomingSleeps
        ? rules.grooming.text
        : undefined;
  // M5-R04: today's feed windows ("nahrani starš" for quiet hours) near the feed button.
  // Profiled pets only (a legacy pet keeps the pre-M5 HUD).
  const mealWindows = pet.profile ? buildMealWindows(view) : [];
  const showTraining = isTrainingVisible && !locked && hasTraining && !showAlbum;
  // M5-R05: the play layer (never together with the album / training / a mess to scrub / a cat game).
  const showPlay = playOverlay !== null && !locked && view.play !== null && !showAlbum && !showTraining && !showCleaning && !catGameOpen;
  // M5-R06-08b: a cat game covers the HUD (the album closes it from the dock side — never both).
  const showCatGame = catOverlay !== null && catGameOpen && !showAlbum && !showTraining;
  // M5-R14 / R15 / R19 / R20: a breed care mini-game covers the HUD like a cat game.
  const showCareTask = careTaskOverlay !== null && catGameOpen && !showAlbum && !showTraining && !showCatGame;
  const showSlipper =
    slipperOpen && messGames !== null && !locked && view.behaviour.can_resolve_chewing && !showCleaning && !catGameOpen && !showTraining && !showAlbum && playOverlay === null;
  const coveredByOverlay = isWalkModalVisible || showCleaning || showTraining || showAlbum || showPlay || showCatGame || showCareTask || showSlipper;
  // M5-R09c: the »Moja pasma« layer covers the HUD like the album (video paused, hidden from TalkBack).
  const showGuide = facts.guideOpen && !locked;
  const factChip = facts.showChip && !coveredByOverlay && !locked;
  const breedLabel = breedName(pet.breed_type, pet.species);
  const petImage = pet.media.referenceImageUrl ?? pet.reference_image_url;
  // Cat chips (Počeši / Menjava peska), the "Na praskalnik" button and the first-aid note.
  // M5-R13: a brushed dog's "Počeši" chip too (careChips).
  const chips = careChips(view);
  const chipNotes = chips.flatMap((c) => (c.note !== null ? [{ kind: c.kind, text: c.note }] : []));
  // M5-R17: "Nova izkušnja" while this puppy week's task is open (the server's block decides).
  const socialisationChip = !cat && !coveredByOverlay && showSocialisationChip(view.socialisation, locked);
  const scratcherShown = !coveredByOverlay && showScratcherButton(view);
  const firstAid = showFirstAid(view);
  // M5-R16: after a meal of a breed with the GDV advice — only a note, the walk stays enabled.
  const digestion = !cat && !coveredByOverlay ? digestionNote(view, digestionNow) : null;
  // M5-R14 / R15 / R19 / R20: the breed care chips while a period still has an open slot.
  const careTaskChips = !cat && !coveredByOverlay ? view.careTasks.filter((c) => showCareTaskChip(c, locked)) : [];
  // M5-R14: a flat-faced breed at summer midday — a calm note; the walk stays enabled.
  const heat = !cat && !coveredByOverlay && heatWarningActive(view.walkHeat, heatNow);
  const openCat = (kind: CatGameKind) => {
    openCatGame(kind);
  };
  const entry = coveredByOverlay ? ({ kind: 'hidden' } as const) : playEntry(view, playNow, dismissedInvitations);
  const moodScene = moodSceneAt(view, playNow);
  // The free tier has no `playing` video: the usual one plays and the app draws hearts.
  const showHearts = ((moodScene !== null && hudVideoState !== 'playing') || treatHearts) && !coveredByOverlay;
  // David 2026-10-25: the stack's cards besides the countdown / scene / notice / meals (BehaviourPanel adds those).
  const playBlockedShown =
    entry.kind === 'button' && entry.block !== null && !(sleepNote !== undefined && entry.block === 'sleeping');
  const playBlockedText =
    playBlockedShown && entry.kind === 'button' && entry.block !== null
      ? playBlockText(entry.block, playSleepsUntil, view.timezone, playNow)
      : null;
  // David 2026-10-26: every card also gives the collapsed pill an icon + very short text.
  const stackCards: StackCard[] = [
    ...(firstAid
      ? [{ key: 'first-aid', priority: STACK_PRIORITY.firstAid, short: t('child:hud.topStack.short.firstAid'), icon: Info, node: <FirstAidNote /> }]
      : []),
    ...(entry.kind === 'invitation'
      ? [
          {
            key: 'invitation',
            priority: STACK_PRIORITY.invitation,
            short: invitationTexts(entry.invitation.kind).line,
            icon: Heart,
            iconColour: palette.raspberry,
            node: (
              <PlayInvitationCard
                invitation={entry.invitation}
                onAccept={(kind) => setPlayOverlay(kind)}
                onDismiss={dismissInvitation}
                reduceMotion={reduceMotion}
              />
            ),
          },
        ]
      : []),
    ...(digestion !== null
      ? [{ key: 'digestion', priority: STACK_PRIORITY.advice, short: digestion.short, icon: Hourglass, node: <DigestionNote note={digestion} /> }]
      : []),
    ...(heat ? [{ key: 'heat', priority: STACK_PRIORITY.advice, short: t('careTasks:heat.title'), icon: Sun, node: <HeatNote /> }] : []),
    ...(sleepNote !== undefined
      ? [{ key: 'sleep', priority: STACK_PRIORITY.hint, short: sleepNote, icon: Moon, node: <PlayBlockedNote text={sleepNote} testID="hud-sleep-note" /> }]
      : []),
    ...(schoolPreparingNote !== undefined
      ? [{ key: 'school-preparing', priority: STACK_PRIORITY.hint, short: schoolPreparingNote, icon: Hourglass, node: <PlayBlockedNote text={schoolPreparingNote} testID="hud-school-preparing" /> }]
      : []),
    ...(playBlockedText !== null
      ? [{ key: 'play-blocked', priority: STACK_PRIORITY.hint, short: playBlockedText, icon: HandHeart, node: <PlayBlockedNote text={playBlockedText} /> }]
      : []),
    ...chipNotes.map((note) => ({
      key: `cat-note-${note.kind}`,
      priority: STACK_PRIORITY.hint,
      short: note.text,
      icon: Info,
      node: <CatHudNote text={note.text} testID={`hud-cat-note-${note.kind}`} />,
    })),
    ...(moodScene !== null
      ? [{ key: 'happy', priority: STACK_PRIORITY.happy, short: MOOD_STRINGS.happy, icon: Heart, iconColour: palette.raspberry, node: <HappyBadge /> }]
      : []),
    ...(factChip
      ? [{ key: 'fact', priority: STACK_PRIORITY.fact, short: t('facts:child.chip'), icon: BookOpen, node: <FactChip onPress={facts.openChip} /> }]
      : []),
  ];
  // M6-W04: »Priboljšek« only while the pet has treats (never over a lock).
  const treatChip = showTreatButton(view.wishes, locked);
  const hasChips = hasTraining || entry.kind === 'button' || chips.length > 0 || careTaskChips.length > 0 || socialisationChip || treatChip;
  // The chips; `compact` = the collapsed stack's icon-only buttons (same actions, full a11y labels).
  const renderChips = (compact: boolean) => (
    <>
      {hasTraining && (
        <TrainingChip
          compact={compact}
          todayDone={view.training.today_done}
          pending={!trainingSleeps && !trainingPreparing && showTrainingDot(view.training)}
          disabled={trainingSleeps || trainingPreparing}
          disabledReason={trainingSleeps || trainingPreparing ? rules.training.text : undefined}
          onPress={() => setTrainingVisible(true)} />
      )}
      {chips.map((chip) => (
        <CatHudChip
          key={chip.kind}
          compact={compact}
          chip={chip}
          disabled={chip.kind === 'grooming' && groomingSleeps}
          disabledReason={chip.kind === 'grooming' && groomingSleeps ? rules.grooming.text : undefined}
          onPress={() => openCat(chip.kind)}
        />
      ))}
      {careTaskChips.map((task) => {
        const sleeps = rules[task.task].reason === 'sleeping';
        return (
          <CareTaskChip
            key={task.task}
            compact={compact}
            task={task}
            disabled={sleeps}
            disabledReason={sleeps ? rules[task.task].text : undefined}
            onPress={() => openCareTask(task.task)}
          />
        );
      })}
      {socialisationChip && view.socialisation !== null && (
        <SocialisationChip compact={compact} pending={view.socialisation.can_record} onPress={() => setSocialisationVisible(true)} />
      )}
      {entry.kind === 'button' &&
        (cat ? (
          // A cat only cuddles (CAT_SPEC §5.5): straight to the cuddle, no ball.
          <PlayChip
            compact={compact}
            block={entry.block}
            label={CAT_HUD_STRINGS.chips.cuddle}
            accessibilityLabel={CAT_HUD_STRINGS.chips.cuddleA11y}
            onPress={() => setPlayOverlay('cuddle')}
          />
        ) : (
          <PlayChip compact={compact} block={entry.block} onPress={() => setPlayOverlay('pick')} />
        ))}
      {treatChip && view.wishes !== null && (
        <TreatChip compact={compact} left={view.wishes.treats.left} species={pet.species} busy={giveTreat.isPending} onPress={handleTreat} />
      )}
    </>
  );
  // Android: TalkBack must not reach the HUD under the album / training game (iOS: accessibilityViewIsModal).
  const hiddenUnderAlbum = showAlbum || showTraining || showPlay || showCatGame || showGuide || preparingNow || messGameShown || showSlipper
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
          mood={moodScene}
          breed={pet.breed_type}
          species={pet.species}
          // Vet visit / hard stop: the sick / sleeping video keeps playing under the
          // translucent grey lock (PRODUCT_SPEC §7). Paused under the opaque game-over /
          // inactive screen, the walk tracker, the album (one player at a time) and in the background.
          active={!opaqueLock && !isWalkModalVisible && !showAlbum && !showTraining && !showPlay && !showCatGame && !showGuide && !preparingNow && !messGameShown && !showSlipper}
          onMediaExpired={onMediaExpired}
          // The vet veil darkens when a substitute (sleeping / idle) stands in for `sick`.
          onVideoStateChange={setHudVideoState}
          // M5-T11 »Prikaz: Slika«: the key image of the current state, no video download.
          mode={mediaModeOf(view)}
          variant="hud"
          // M5-F06: the "getting ready" notice is stacked above the dock (BehaviourPanel), not on the media.
          // Under a translucent lock (vet, hard stop, payment) the panel is hidden → keep it on the media.
          pendingNotice={locked ? 'overlay' : 'none'}
          testID="hud-pet-media"
          placeholder={
            <View style={styles.fallbackViewport}>

              <Animated.View style={[styles.petAvatarWrapper, { transform: [{ translateY: bounceAnim }] }]}>
                <View style={styles.petAvatarCircle}>
                  <Text style={styles.petEmoji}>{cat ? '🐈' : '🐕'}</Text>
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

        {showHearts && <HeartsLayer reduceMotion={reduceMotion} />}

        {/* Glassmorphism top status bar */}
        <View style={[styles.topBar, { top: layout.headerTop }]} onLayout={onHeaderLayout} testID="hud-header">
          <Pressable
            style={({ pressed }) => [styles.petInfoLeft, pressed && pet.profile !== null && styles.pressed]}
            onPress={pet.profile ? () => setProfileVisible(true) : undefined}
            disabled={!pet.profile}
            accessibilityRole={pet.profile ? 'button' : undefined}
            accessibilityLabel={pet.profile ? headerA11y(pet.name, profileHeaderA11y(profileRows)) : undefined}
            accessibilityHint={pet.profile ? t('child:hud.profileSheet.openHint') : undefined}
            testID="hud-profile-open"
          >
            <View style={styles.petIconBox}>
              <PawPrint color={palette.mint} size={20} />
            </View>
            <View style={styles.petInfoText}>
              {pet.name !== null ? (
                // M5-R08: the parent's name is the title (a label only), the breed stays beside it.
                <Text style={[styles.petBreedName, styles.petNameTitle]} numberOfLines={pet.profile ? 1 : 2} testID="hud-pet-name">
                  {isolatePetName(pet.name)}
                  <Text style={styles.petNameBreed}>{` · ${breedName(pet.breed_type, pet.species)}`}</Text>
                </Text>
              ) : (
                <Text style={styles.petBreedName} numberOfLines={pet.profile ? 1 : 2}>
                  {breedName(pet.breed_type, pet.species)}
                </Text>
              )}
              {pet.profile ? (
                <>
                  <Text style={styles.petAgeText} numberOfLines={1} testID="hud-stage">
                    {stageLine(pet.profile)}
                  </Text>
                  {profileSub && (
                    <Text style={styles.petProfileText} numberOfLines={1} ellipsizeMode="tail" testID="hud-profile-sub">
                      {profileSub}
                    </Text>
                  )}
                </>
              ) : (
                // Free plan = sandbox without the 12-week programme (PAYMENTS_SPEC §2): no week label.
                pet.plan.type !== 'free' && <Text style={styles.petAgeText}>{formatChallengeWeek(pet.virtual_age_months)}</Text>
              )}
            </View>
            {pet.profile && <ChevronDown color={alpha(palette.white, 0.6)} size={16} />}
          </Pressable>

          <View style={styles.topBarRight}>
            {facts.showBook && (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={facts.unread ? t('facts:child.bookUnread') : t('facts:child.book')}
                accessibilityHint={t('facts:child.bookHint')}
                testID="hud-facts-open"
                hitSlop={4}
                style={({ pressed }) => [styles.bookButton, pressed && styles.pressed]}
                onPress={facts.openGuide}
              >
                <BookOpen color={palette.mint} size={20} />
                {facts.unread && <View style={styles.unreadDot} testID="hud-facts-unread" />}
              </Pressable>
            )}
            <WsStatusDot status={wsStatus} />
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('child:hud.settingsSheet.open')}
              testID="hud-settings-open"
              hitSlop={5}
              style={({ pressed }) => [styles.logoutButton, pressed && styles.pressed]}
              onPress={() => setSettingsVisible(true)}
            >
              <Settings color={palette.n400} size={16} />
            </Pressable>
            {childCertificateEntry(view.certificate).kind !== 'none' && (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={
                  childCertificateEntry(view.certificate).kind === 'generated'
                    ? CERTIFICATE_CHILD_STRINGS.open
                    : CERTIFICATE_CHILD_STRINGS.finishedOpen
                }
                testID="hud-certificate-open"
                style={({ pressed }) => [styles.logoutButton, styles.certificateButton, pressed && styles.pressed]}
                onPress={() => setCertificateVisible(true)}
              >
                <Award color={palette.graphite} size={16} />
              </Pressable>
            )}
            {/* David 2026-10-23: album, »?« (care guide) and the exit moved into the settings sheet —
                the bar truncated the name / stage / breed. The gate stays mounted here. */}
            <ParentalGate {...childExit.gateProps} />
          </View>
        </View>

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
              icon={
                cat ? (
                  <Feather color={palette.white} size={layout.metric.iconSize} />
                ) : (
                  <Footprints color={palette.white} size={layout.metric.iconSize} />
                )
              }
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
          {dock.map((kind: DockKind) => {
            switch (kind) {
              case 'feed':
                return (
                  <ActionButton
                    key={kind}
                    testID="action-feed"
                    icon={<Beef color={dockIcon(feedDue)} size={iconSize} />}
                    label={feedLabel(view)}
                    onPress={() => runAction('feed')}
                    disabled={feedDisabled}
                    due={feedDue}
                    busy={feed.isPending}
                    hint={feedDockHint(view)}
                    compact={compactDock}
                  />
                );
              case 'water':
                return (
                  <ActionButton
                    key={kind}
                    testID="action-water"
                    icon={<Droplet color={palette.white} size={iconSize} />}
                    label={HUD_STRINGS.water}
                    onPress={() => runAction('water')}
                    disabled={waterDisabled}
                    busy={water.isPending}
                    hint={waterDockHint(view)}
                    compact={compactDock}
                  />
                );
              case 'take_out':
                return (
                  <ActionButton
                    key={kind}
                    testID="action-take-out"
                    icon={<DoorOpen color={dockIcon(takeOutDue)} size={iconSize} />}
                    label={HUD_STRINGS.takeOut}
                    onPress={() => runAction('take_out')}
                    disabled={takeOutDisabled}
                    due={takeOutDue}
                    busy={takeOut.isPending}
                    hint={countdown?.hint ?? null}
                    accessibilityHint={countdown ? (countdown.howTo ? `${countdown.line} ${countdown.howTo}` : countdown.line) : undefined}
                    // M5-T01: the last 30 min (and overdue) pulse gently — never with reduce motion.
                    pulse={countdown?.level === 'urgent' && !reduceMotion && !takeOut.isPending}
                    compact
                  />
                );
              case 'walk':
                return (
                  <ActionButton
                    key={kind}
                    testID="action-walk"
                    icon={<Footprints color={palette.white} size={iconSize} />}
                    label={HUD_STRINGS.walk}
                    onPress={() => setWalkModalVisible(true)}
                    disabled={walkDisabled}
                    hint={`${formatSteps(view.steps.steps_today)}/${formatSteps(view.steps.goal)}`}
                    hintSingleLine
                    compact={compactDock}
                  />
                );
              case 'scoop':
                return (
                  <ActionButton
                    key={kind}
                    testID="action-scoop"
                    icon={<Shovel color={dockIcon(scoopDue)} size={iconSize} />}
                    label={CAT_HUD_STRINGS.scoop}
                    accessibilityHint={CAT_HUD_STRINGS.scoopA11y}
                    onPress={() => openCat('scoop')}
                    disabled={scoop?.disabled ?? true}
                    due={scoopDue}
                    hint={scoop?.hint ?? null}
                    compact={compactDock}
                  />
                );
              case 'wand':
                return (
                  <ActionButton
                    key={kind}
                    testID="action-wand"
                    icon={<Feather color={palette.white} size={iconSize} />}
                    label={CAT_HUD_STRINGS.wand}
                    accessibilityHint={CAT_HUD_STRINGS.wandA11y}
                    onPress={() => openCat('wand')}
                    disabled={wand?.disabled ?? true}
                    hint={wand?.hint ?? null}
                    hintSingleLine
                    compact={compactDock}
                  />
                );
              case 'clean':
                return (
                  <ActionButton
                    key={kind}
                    testID="action-clean"
                    icon={<Sparkles color={dockIcon(cleanDue)} size={iconSize} />}
                    label={HUD_STRINGS.clean}
                    onPress={() => setCleaningOverlayVisible(true)}
                    disabled={cleanDisabled}
                    due={cleanDue}
                    busy={clean.isPending}
                    hint={cleanHint(view)}
                    compact={compactDock}
                  />
                );
            }
          })}
        </View>

        {/* David 2026-10-25 »pes mora biti viden«: every message card sits in one compact stack under
            the header, left of the metric column (modules/hud/topStack: most urgent first, ≤ 2 cards,
            the rest behind "+N"); the dog's centre / lower half stays free, only the dock is at the bottom.
            David 2026-10-26: the stack collapses (× / swipe up) into one slim pill row (BehaviourPanel). */}
        <View
          pointerEvents="box-none"
          style={[styles.topStack, { top: layout.bannerTop, maxHeight: layout.stackMaxHeight }]}
          testID="hud-top-stack"
        >
          {serverUpdating && (
            <ServerUpdatingBanner tone="dark" style={styles.stackBanner} testID="hud-server-updating" />
          )}
          {stale && (
            // In the top stack, left of the metric column (PR #30 review): never covers the bars.
            <View pointerEvents="none" style={styles.stackBanner} testID="hud-stale-slot">
              <View style={styles.staleBanner} testID="hud-stale">
                <WifiOff color={palette.warnDark} size={14} />
                <Text style={styles.staleText}>{HUD_STRINGS.stale}</Text>
              </View>
            </View>
          )}

          {/* Feedback messages (M5-T03 queue): one at a time, a tap closes it and the next follows.
              An accepted care is a ✓ confirmation with a "next time" line (M5-T01). Top of the stack,
              so it pushes the cards down for a moment instead of covering them. */}
          {toast && (
            <View pointerEvents="box-none" style={styles.stackBanner} testID="hud-toast-slot">
              <View key={toast.id} pointerEvents="box-none" style={styles.feedbackWrap}>
                <Pressable
                  onPress={messages.dismiss}
                  accessibilityRole="button"
                  accessibilityLabel={toast.next ? `${toast.message} ${toast.next}` : toast.message}
                  accessibilityHint={HUD_STRINGS.toastDismissHint}
                  accessibilityLiveRegion="polite"
                  style={[
                    styles.feedbackToast,
                    toast.tone === 'info' && styles.feedbackToastInfo,
                    toast.confirm === true && styles.feedbackConfirm,
                    styles.feedbackWithClose,
                  ]}
                  testID="hud-toast"
                >
                  {toast.confirm === true ? (
                    <View style={styles.feedbackConfirmBody} testID="hud-toast-confirm">
                      <View style={styles.feedbackConfirmRow}>
                        <View style={styles.feedbackCheck}>
                          <Check color={palette.mint} size={14} />
                        </View>
                        <Text style={[styles.feedbackText, styles.feedbackConfirmText]}>{toast.message}</Text>
                      </View>
                      {toast.next ? (
                        <Text style={styles.feedbackNext} testID="hud-toast-next">
                          {toast.next}
                        </Text>
                      ) : null}
                    </View>
                  ) : (
                    <Text style={styles.feedbackText}>{toast.message}</Text>
                  )}
                </Pressable>
                {/* Visible "×" (David 2026-10-21): a sibling of the message, so screen readers reach it; 44 pt hit area. */}
                <Pressable
                  onPress={messages.dismiss}
                  accessibilityRole="button"
                  accessibilityLabel={HUD_STRINGS.toastClose}
                  style={styles.feedbackClose}
                  testID="hud-toast-close"
                >
                  <View style={styles.feedbackCloseIcon}>
                    <X color={palette.graphite} size={14} strokeWidth={2.5} />
                  </View>
                </Pressable>
              </View>
            </View>
          )}

          {/* M5-R02: puppy countdown and the scene of an open accident / chewed slipper. */}
          {!locked && (
            <BehaviourPanel
              behaviour={view.behaviour}
              countdown={countdown}
              reduceMotion={reduceMotion}
              videoState={hudVideoState}
              onResolveChewing={() => (messGames !== null ? setSlipperOpen(true) : runAction('resolve_chewing'))}
              resolveBusy={resolveChewing.isPending}
              notice={
                mediaPending
                  ? { short: PET_MEDIA_STRINGS.pending, icon: Hourglass, node: <MediaPendingNotice testID="hud-pet-media-pending" /> }
                  : null
              }
              scratcher={
                scratcherShown
                  ? {
                      label: CAT_HUD_STRINGS.scratcher,
                      a11y: CAT_HUD_STRINGS.scratcherA11y,
                      // Locked never reaches here; off while the server refuses a carry (another
                      // child's game) unless it is this child's own running carry (resumable).
                      disabled: view.cat.scratching !== null && !view.cat.scratching.can_start && view.cat.scratching.session === null,
                      onPress: () => openCat('scratching'),
                    }
                  : null
              }
              cards={stackCards}
              renderChips={hasChips ? renderChips : null}
              // Hidden by the panel while a scene card is open; one line on smaller screens.
              meals={
                mealWindows.length > 0
                  ? {
                      short: MEAL_STRINGS.title,
                      icon: Utensils,
                      node: <MealWindowsRow items={mealWindows} compact={layout.metric.variant !== 'regular'} />,
                    }
                  : null
              }
            />
          )}
          {/* M5-T13: the party hat on the pet's first birthday (drawn, no AI media), under the stack. */}
          {birthdayToday && !coveredByOverlay && <BirthdayHat onPress={birthdayCard.open} reduceMotion={reduceMotion} />}
        </View>

        {/* Conditional overlays (the lock overlay is rendered by AppNavigator above this screen) */}
        {isWalkModalVisible && !locked && !cat && (
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
        {showCleaning && (messGames === null || messEscaped) && (
          <CleaningOverlay
            onCleaned={handleCleaned}
            onClose={needsScrub ? undefined : () => setCleaningOverlayVisible(false)}
            mess={cleaningMess(view.behaviour)}
          />
        )}
      </View>
      {/* M5-T06: »Pripravljamo …« while the pet's first things are on their way (never over a lock). */}
      {preparingNow && <PetPreparationScreen name={pet.name} species={pet.species} reduceMotion={reduceMotion} />}
      {/* Outside hud-content, so hiding the HUD from TalkBack never hides the game. */}
      {showTraining && <TrainingOverlay view={view} onClose={closeTraining} />}
      {/* GAMES_SPEC §6.2: the cleaning games of the native layer (bag / cloth; slipper → box + toy). */}
      {messGameShown && messGames !== null && (
        <messGames.MessGame
          view={view}
          stages={messStages(view.behaviour.active_events)}
          reduceMotion={reduceMotion}
          onCleaned={handleCleaned}
          onClose={needsScrub ? undefined : () => setCleaningOverlayVisible(false)}
          onEscape={() => setMessEscaped(true)}
          onMediaExpired={onMediaExpired}
        />
      )}
      {showSlipper && messGames !== null && (
        <messGames.SlipperGame
          view={view}
          reduceMotion={reduceMotion}
          onResolved={() => {
            setSlipperOpen(false);
            runAction('resolve_chewing');
          }}
          onClose={() => setSlipperOpen(false)}
          onMediaExpired={onMediaExpired}
        />
      )}
      {/* M5-R06-08b: the cat mini-games (wand, scoop, litter change, grooming, scratcher). */}
      {showCatGame && <CatCareOverlay view={view} />}
      {/* M5-R14 / R15 / R19 / R20: the breed care mini-games (teeth, folds, ears, nails). */}
      {showCareTask && <CareTaskOverlayHost view={view} />}
      {showPlay && playOverlay !== null && (
        <PlayOverlay
          view={view}
          mode={playOverlay}
          onPick={(kind) => setPlayOverlay(kind)}
          onFinished={handlePlayFinished}
          onClose={() => setPlayOverlay(null)}
          onMediaExpired={onMediaExpired}
          reduceMotion={reduceMotion}
        />
      )}
      <ChildSettingsSheet
        visible={isSettingsVisible}
        onClose={() => setSettingsVisible(false)}
        mediaDisplay={view.settings.media_display}
        album={albumAvailable ? { label: ALBUM_STRINGS.open, onOpen: () => setAlbumVisible(true) } : undefined}
        careGuide={
          view.care_guide !== null ? { label: t('careGuide:open'), hint: t('careGuide:openHint'), onOpen: careGuide.open } : undefined
        }
        wishes={
          view.wishes !== null ? { label: t('wishes:child.entry'), hint: t('wishes:child.entryA11y'), onOpen: () => setWishesVisible(true) } : undefined
        }
        exit={{ label: childExit.shared ? PARENTAL_GATE_STRINGS.backToParent : HUD_STRINGS.logout, onPress: handleLogout }}
        timeZone={view.timezone}
      />
      {view.wishes !== null && (
        <WishesSheet visible={isWishesVisible} onClose={() => setWishesVisible(false)} nowMs={() => Date.now() + view.clockSkewMs} />
      )}
      {treatClip !== null && <TreatClip url={treatClip} onClose={closeTreatClip} />}
      <ChildCertificateSheet
        visible={isCertificateVisible}
        certificate={view.certificate}
        onClose={() => setCertificateVisible(false)}
      />
      <SocialisationSheet
        visible={isSocialisationVisible && !locked && !cat}
        socialisation={view.socialisation}
        nowIso={view.server_time}
        timezone={view.timezone}
        onClose={() => setSocialisationVisible(false)}
      />
      <BirthdayCard
        visible={birthdayCard.visible && birthdayToday && !coveredByOverlay && !showGuide}
        petName={pet.name}
        species={pet.species}
        onClose={birthdayCard.close}
      />
      <PetProfileSheet visible={isProfileVisible && profileRows.length > 0} rows={profileRows} onClose={() => setProfileVisible(false)} />
      {view.care_guide !== null && (
        <CareGuideSheet
          visible={careGuide.visible && !locked && !coveredByOverlay && !showGuide}
          guide={view.care_guide}
          petName={pet.name}
          pushes={view.steps.adapted_mode}
          onClose={careGuide.close}
        />
      )}
      {showGuide && (
        <BreedGuideScreen
          breedLabel={breedLabel}
          imageUrl={petImage}
          facts={facts.facts}
          readCount={facts.readCount}
          totalCount={facts.totalCount}
          isLoading={facts.list.isLoading}
          isError={facts.list.isError}
          onRetry={facts.list.refetch}
          onOpenFact={facts.openFact}
          onClose={facts.closeGuide}
        />
      )}
      <FactSheet
        fact={locked ? null : facts.sheetFact}
        breedLabel={breedLabel}
        imageUrl={petImage}
        readCount={facts.readCount}
        totalCount={facts.totalCount}
        reduceMotion={reduceMotion}
        onClose={facts.closeSheet}
        onMore={facts.openGuide}
      />
      {showAlbum && (
        <PetAlbum
          media={pet.media}
          // M5-R08: the pet's name as the album title; without one the default ("Moj kuža").
          title={pet.name ?? undefined}
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
  /** M5-R08: a name is shown as typed (no capitalize — "de Luna"). */
  petNameTitle: { textTransform: 'none' },
  petNameBreed: { fontSize: 13, fontWeight: '600', textTransform: 'capitalize', color: alpha(palette.white, 0.7) },
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
  /** M5-R09c: the book (glass, ≥ 44 pt + hitSlop → 52 pt target) with the one raspberry accent. */
  bookButton: {
    width: 44,
    height: 44,
    borderRadius: 14,
    backgroundColor: alpha(palette.white, 0.1),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
    alignItems: 'center',
    justifyContent: 'center',
  },
  unreadDot: {
    position: 'absolute',
    top: 7,
    right: 7,
    width: 9,
    height: 9,
    borderRadius: 5,
    backgroundColor: palette.raspberry,
    borderWidth: 1.5,
    borderColor: palette.graphite,
  },
  /** M2-01d: the finished challenge — the one solid mint badge in the header. */
  certificateButton: { backgroundColor: palette.mint, borderRadius: 17 },
  /**
   * David 2026-10-25: the top stack — under the header, between the screen's left edge and the
   * metric column (never over the bars); toast, banners and cards in one column, left-aligned.
   */
  topStack: {
    position: 'absolute',
    left: 16,
    right: METRICS_RESERVED_RIGHT,
    zIndex: 25,
    alignItems: 'flex-start',
    gap: 6,
    overflow: 'hidden',
  },
  /** A banner / the toast inside the top stack (as wide as its text, at most the column). */
  stackBanner: {
    maxWidth: '100%',
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
    // Top-aligned: buttons with a one- or two-line hint keep their circles in one row.
    alignItems: 'flex-start',
    // Equal slots: every ActionButton is flex: 1 / minWidth: 0.
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
  feedbackConfirm: {
    paddingHorizontal: 18,
    paddingVertical: 12,
    borderRadius: 22,
  },
  feedbackConfirmBody: {
    alignItems: 'center',
    gap: 4,
  },
  feedbackConfirmRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    maxWidth: '100%',
  },
  feedbackCheck: {
    width: 22,
    height: 22,
    borderRadius: 11,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: palette.graphite,
  },
  feedbackConfirmText: {
    flexShrink: 1,
  },
  feedbackWrap: {
    maxWidth: '100%',
    justifyContent: 'center',
  },
  feedbackWithClose: {
    paddingRight: FEEDBACK_CLOSE_SIZE,
  },
  feedbackClose: {
    position: 'absolute',
    right: 0,
    top: 0,
    bottom: 0,
    minHeight: FEEDBACK_CLOSE_SIZE,
    width: FEEDBACK_CLOSE_SIZE,
    alignItems: 'center',
    justifyContent: 'center',
  },
  feedbackCloseIcon: {
    width: 24,
    height: 24,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(palette.graphite, 0.12),
  },
  feedbackNext: {
    textAlign: 'center',
    color: palette.graphite,
    fontWeight: '600',
    fontSize: 13,
  },
});
