/**
 * M5-R09c — the HUD's »Ali si vedel?« controller (DID_YOU_KNOW_SPEC §3.1–§3.3).
 *
 * Reads the child's breed facts and the fact of the day (TanStack Query), watches the care
 * mutations for a success (the mutation cache — every care hook keeps its own key) and the step
 * goal, and opens the sheet by itself only in a calm moment (`calmMomentBlockers`, never while a
 * care request runs) after the server recorded it now (`POST …/shown` → `recorded`).
 * `already_shown` (another device used today's card) and a 422 refusal open nothing and only
 * write the server's state; nothing is shown as an error. Tapping the chip IS today's card
 * (David 2026-10-13, option A): it opens at once and records `shown` (a refusal is ignored — the
 * child asked). Which sheet / guide is open is UI state in `appStore`.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AppState } from 'react-native';
import { useIsMutating, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import type { BreedFact } from '@/api/client';
import {
  breedFactsBreed,
  calmMomentBlockers,
  calmStateOf,
  careKindOfMutation,
  familyDay,
  hasUnreadFacts,
  nextCalmCheckAt,
  readOnDay,
  showFactChip,
  stepGoalReached,
  type CalmBlocker,
} from '@/modules/breedFacts/breedFacts';
import { factOfTheDayRoot, useBreedFacts, useFactOfTheDay, useMarkFactRead, useRecordFactShown } from '@/hooks/queries/useBreedFacts';
import { currentLanguageTag } from '@/i18n';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { useServerNow } from '@/hooks/useServerNow';
import { useAppStore } from '@/store/appStore';

/** The family day is re-read every minute (midnight ends the chip; the fact of the day is refetched). */
export const FACT_DAY_TICK_MS = 60_000;

export interface FactMomentInput {
  view: ChildPetView | undefined;
  /** Any game, layer or sheet of the HUD is open (the sheet never opens over it). */
  overlayOpen: boolean;
  /** Something covers the dock (the chip waits). */
  dockCovered: boolean;
}

export interface FactMoment {
  /** Book button in the header (an approved fact exists for this breed). */
  showBook: boolean;
  /** Raspberry dot on the book. */
  unread: boolean;
  /** »Nekaj novega o tvoji pasmi« above the dock. */
  showChip: boolean;
  facts: readonly BreedFact[];
  readCount: number;
  totalCount: number;
  /** The fact in the open sheet (null = closed). */
  sheetFact: BreedFact | null;
  guideOpen: boolean;
  /** Why the sheet would not open by itself now (tests / debugging). */
  blockers: readonly CalmBlocker[];
  openChip: () => void;
  openFact: (fact: BreedFact) => void;
  closeSheet: () => void;
  openGuide: () => void;
  closeGuide: () => void;
  /** Guide list request state (the guide shows loading / error itself). */
  list: { isLoading: boolean; isError: boolean; refetch: () => void };
}

export function useFactMoment({ view, overlayOpen, dockCovered }: FactMomentInput): FactMoment {
  const client = useQueryClient();
  const breed = breedFactsBreed(view?.pet.breed_type);
  const skew = view?.clockSkewMs ?? 0;
  const serverNow = useServerNow(skew, view !== undefined && breed !== null, FACT_DAY_TICK_MS);
  const day = view ? familyDay(view, serverNow) : '';

  // Facts come localized: the language is part of the query keys (a switch refetches).
  const { i18n } = useTranslation();
  void i18n.language; // re-render on a switch
  const lang = currentLanguageTag();
  const listQuery = useBreedFacts(breed, lang);
  const facts = useMemo(() => listQuery.data?.facts ?? [], [listQuery.data]);
  const hasFacts = breed !== null && facts.length > 0;
  const factQuery = useFactOfTheDay(day, lang, hasFacts && day !== '');
  const fotd = factQuery.data ?? null;

  const sheetFactId = useAppStore((s) => s.factSheetId);
  const openFactSheet = useAppStore((s) => s.openFactSheet);
  const closeFactSheet = useAppStore((s) => s.closeFactSheet);
  const guideOpen = useAppStore((s) => s.isBreedGuideVisible);
  const setGuideVisible = useAppStore((s) => s.setBreedGuideVisible);
  const askedDay = useAppStore((s) => s.factAutoAskedDay);
  const pendingDay = useAppStore((s) => s.factPendingDay);
  const setAskedDay = useAppStore((s) => s.setFactAutoAskedDay);
  const setPendingDay = useAppStore((s) => s.setFactPendingDay);

  // ── "Care just done" (device clock; only differences matter). Every newer success restarts
  // the settle wait; a running care request blocks (`busy`). ──
  const [careDoneAt, setCareDoneAt] = useState<number | null>(null);
  const careInFlight = useIsMutating({ mutationKey: ['child', 'pet'] }) > 0;
  useEffect(
    () =>
      client.getMutationCache().subscribe((event) => {
        if (event.type !== 'updated' || event.action.type !== 'success') return;
        if (careKindOfMutation(event.mutation.options.mutationKey, event.action.data) === null) return;
        setCareDoneAt(Date.now());
        // The server's decision may change after a care (e.g. the lock lifted): ask again.
        void client.invalidateQueries({ queryKey: factOfTheDayRoot });
      }),
    [client],
  );
  // The walk counts when today's step goal is reached (not on the first look at the state).
  const goalReached = view !== undefined && stepGoalReached(view);
  const lastGoal = useRef<boolean | null>(null);
  useEffect(() => {
    if (view === undefined) return;
    if (lastGoal.current === false && goalReached) setCareDoneAt(Date.now());
    lastGoal.current = goalReached;
  }, [view, goalReached]);

  // ── App open / back from the background ──
  const [appOpenedAt, setAppOpenedAt] = useState(() => Date.now());
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active') setAppOpenedAt(Date.now());
    });
    return () => sub.remove();
  }, []);

  // Re-render when only the clock blocks (settle after a care / first 10 s).
  const [, setTick] = useState(0);
  const readToday = view !== undefined && readOnDay(facts, day, view);
  const live = view ? calmStateOf(view) : { metrics: [], openMess: false, locked: true, quietHours: false };
  const calmInput = {
    fact: hasFacts ? fotd : null,
    careDoneAtMs: careDoneAt,
    appOpenedAtMs: appOpenedAt,
    nowMs: Date.now(),
    ...live,
    overlayOpen: overlayOpen || sheetFactId !== null || guideOpen,
    handledToday: readToday || askedDay === day,
    careInFlight,
  };
  const blockers = calmMomentBlockers(calmInput);
  const calm = blockers.length === 0;
  const recheckAt = nextCalmCheckAt(calmInput);
  useEffect(() => {
    if (recheckAt === null) return;
    const id = setTimeout(() => setTick((n) => n + 1), Math.max(0, recheckAt - Date.now()) + 20);
    return () => clearTimeout(id);
  }, [recheckAt]);

  // Latest values for the async answer of `shown` (the moment may have passed meanwhile).
  const latest = useRef({ overlay: calmInput.overlayOpen, locked: live.locked, day });
  latest.current = { overlay: calmInput.overlayOpen, locked: live.locked, day };

  const recordShown = useRecordFactShown();
  const { mutate: record } = recordShown;
  useEffect(() => {
    if (!calm) return;
    // Once per family day and device; the server counts across devices.
    setAskedDay(day);
    record(undefined, {
      onSuccess: (result) => {
        // `already_shown` (another device) and `refused`: the server's state is written, nothing opens.
        if (result.outcome !== 'shown') return;
        const fact = result.state.fact;
        const now = latest.current;
        // Recorded (one weekly card used) but the moment has passed (a game opened, a lock,
        // midnight): the chip keeps today's card reachable.
        if (fact === null || now.overlay || now.locked || now.day !== day) {
          if (fact !== null && now.day === day) setPendingDay(day);
          return;
        }
        openFactSheet(fact.id);
      },
    });
  }, [calm, day, record, setAskedDay, setPendingDay, openFactSheet]);

  // ── The sheet's fact, marked read once it shows ──
  const sheetFact: BreedFact | null =
    sheetFactId === null ? null : (facts.find((f) => f.id === sheetFactId) ?? (fotd?.fact?.id === sheetFactId ? fotd.fact : null));
  const markRead = useMarkFactRead();
  const { mutate: mark } = markRead;
  const marked = useRef<Set<number>>(new Set());
  useEffect(() => {
    if (sheetFact === null || sheetFact.read || marked.current.has(sheetFact.id)) return;
    marked.current.add(sheetFact.id);
    mark(sheetFact);
  }, [sheetFact, mark]);
  // Once per opening: a mark that failed (offline undo) is tried again the next time the card
  // opens — never in a loop while it stays open.
  useEffect(() => {
    if (sheetFactId === null) marked.current.clear();
  }, [sheetFactId]);

  // A lock closes the sheet and the guide (they would reopen when it lifts).
  const locked = live.locked && view !== undefined;
  useEffect(() => {
    if (!locked) return;
    closeFactSheet();
    setGuideVisible(false);
  }, [locked, closeFactSheet, setGuideVisible]);

  const showChip =
    hasFacts &&
    showFactChip({
      fact: fotd,
      pendingToday: pendingDay === day,
      readToday,
      locked: live.locked,
      covered: dockCovered || guideOpen,
    });

  // Option A: the chip is today's card — open at once, record `shown` (refusal ignored: the
  // child asked); the automatic card is then done for today on this device.
  const pendingToday = pendingDay === day;
  const openChip = useCallback(() => {
    if (!fotd?.fact) return;
    openFactSheet(fotd.fact.id);
    setAskedDay(day);
    if (!pendingToday) record(undefined);
  }, [fotd, openFactSheet, setAskedDay, day, pendingToday, record]);
  const openFact = useCallback((fact: BreedFact) => openFactSheet(fact.id), [openFactSheet]);
  const openGuide = useCallback(() => {
    closeFactSheet();
    setGuideVisible(true);
  }, [closeFactSheet, setGuideVisible]);
  const closeGuide = useCallback(() => setGuideVisible(false), [setGuideVisible]);
  const refetchList = listQuery.refetch;

  return {
    showBook: hasFacts,
    unread: hasFacts && hasUnreadFacts(facts),
    showChip,
    facts,
    readCount: listQuery.data?.read_count ?? fotd?.read_count ?? 0,
    totalCount: listQuery.data?.total_count ?? fotd?.total_count ?? 0,
    sheetFact,
    guideOpen: guideOpen && hasFacts,
    blockers,
    openChip,
    openFact,
    closeSheet: closeFactSheet,
    openGuide,
    closeGuide,
    list: {
      isLoading: listQuery.isLoading,
      isError: listQuery.isError,
      refetch: () => {
        void refetchList();
      },
    },
  };
}
