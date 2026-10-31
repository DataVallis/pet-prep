/**
 * The three server-led cat games wired to their mutations (M5-R06-08a): `useWandGame`,
 * `useChoreGame` (grooming / weekly litter change), `useScratchingGame`. Each is
 * `useCatSessionGame` with its input, its finish moment and the resume session read from
 * the child state (`view.cat`).
 */

import { useCallback, useMemo } from 'react';

import {
  useFinishChore,
  useFinishScratching,
  useFinishWand,
  useFinishWandV2,
  useStartChore,
  useStartScratching,
  useStartWand,
  useStartWandV2,
} from '@/hooks/queries/useCatCare';
import { canWandV2StillCount, wandV2FinishAt, type WandV2Move, type WandV2Result, type WandV2Session } from '@/modules/catCare/wandV2/wandRules';
import {
  onlyScratchingOpen,
  ownSession,
  type ChoreKind,
  type ChoreResult,
  type ChoreSession,
  type RefusalContext,
  type ScratchingResult,
  type ScratchingSession,
  type WandResult,
  type WandSession,
} from '@/modules/catCare/catCare';
import { brushFinishAt } from '@/modules/catCare/brushing';
import { canChoreStillCount, canScratchingStillCount, canWandStillCount, scratchingFinishAt, type WandMove } from '@/modules/catCare/catGames';
import { useCatSessionGame, type CatSessionGame } from '@/modules/catCare/useCatSessionGame';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import type { ClockSources } from '@/modules/training/game';
import { useAppStore } from '@/store/appStore';

const NO_MOVES: readonly WandMove[] = [];
const NO_STROKES: readonly number[] = [];

export interface CatGameHookOptions {
  /** Injected in tests (game clock). */
  clock?: ClockSources;
}

function refusalContext(view: ChildPetView): RefusalContext {
  // M5-R13: a dog brushes too — its refusal texts name the dog.
  return { nowIso: view.server_time, timezone: view.timezone, scratchingOnly: onlyScratchingOpen(view.cat), dog: view.pet.species !== 'cat' };
}

function useAbandoned() {
  const abandoned = useAppStore((s) => s.abandonedCatSessions);
  const onAbandon = useAppStore((s) => s.abandonCatSession);
  return { abandoned, onAbandon };
}

export type WandGame = CatSessionGame<WandSession, readonly WandMove[], WandResult>;

export function useWandGame(view: ChildPetView, { clock }: CatGameHookOptions = {}): WandGame {
  const { mutate: startMutate } = useStartWand();
  const { mutate: finish } = useFinishWand();
  const finishMutate = useCallback(
    (v: { session: WandSession; input: readonly WandMove[] }, callbacks: Parameters<typeof finish>[1]) =>
      finish({ sessionId: v.session.id, moves: v.input }, callbacks),
    [finish],
  );
  const refusal = useMemo(() => refusalContext(view), [view]);
  return useCatSessionGame<WandSession, readonly WandMove[], WandResult>({
    clockSkewMs: view.clockSkewMs,
    refusal,
    clock,
    resume: ownSession(view.cat, 'wand'),
    ...useAbandoned(),
    emptyInput: NO_MOVES,
    finishAtMs: (session) => session.duration_ms,
    canStillCount: canWandStillCount,
    startMutate,
    finishMutate,
  });
}

const NO_V2_MOVES: readonly WandV2Move[] = [];

export type WandGameV2 = CatSessionGame<WandV2Session, readonly WandV2Move[], WandV2Result>;

/**
 * GAMES_SPEC §5 — the v2 hunt (`rules: 2`), game-layer builds only (`WandGameV2`). Same session
 * flow as v1; the finish is sent when the catch clip has played out (`wandV2FinishAt`). A running
 * v2 hunt is never resumed after an app restart (the deck must be ready before the hunt clock
 * starts — `canWandV2StillCount`); a new start replaces it on the server without penalty.
 */
export function useWandGameV2(view: ChildPetView, { clock }: CatGameHookOptions = {}): WandGameV2 {
  const { mutate: startMutate } = useStartWandV2();
  const { mutate: finish } = useFinishWandV2();
  const finishMutate = useCallback(
    (v: { session: WandV2Session; input: readonly WandV2Move[] }, callbacks: Parameters<typeof finish>[1]) =>
      finish({ sessionId: v.session.id, moves: v.input }, callbacks),
    [finish],
  );
  const refusal = useMemo(() => refusalContext(view), [view]);
  return useCatSessionGame<WandV2Session, readonly WandV2Move[], WandV2Result>({
    clockSkewMs: view.clockSkewMs,
    refusal,
    clock,
    resume: null,
    ...useAbandoned(),
    emptyInput: NO_V2_MOVES,
    finishAtMs: wandV2FinishAt,
    canStillCount: canWandV2StillCount,
    startMutate,
    finishMutate,
  });
}

export type ChoreGame = CatSessionGame<ChoreSession, readonly number[], ChoreResult>;

export function useChoreGame(kind: ChoreKind, view: ChildPetView, { clock }: CatGameHookOptions = {}): ChoreGame {
  const { mutate: startMutate } = useStartChore(kind);
  const { mutate: finish } = useFinishChore(kind);
  const finishMutate = useCallback(
    (v: { session: ChoreSession; input: readonly number[] }, callbacks: Parameters<typeof finish>[1]) =>
      finish({ sessionId: v.session.id, strokes: v.input }, callbacks),
    [finish],
  );
  const refusal = useMemo(() => refusalContext(view), [view]);
  return useCatSessionGame<ChoreSession, readonly number[], ChoreResult>({
    clockSkewMs: view.clockSkewMs,
    refusal,
    clock,
    resume: ownSession(view.cat, kind),
    ...useAbandoned(),
    emptyInput: NO_STROKES,
    // M5-T14: a brushed-clean coat finishes up to 2 s early (inside the server's tolerance).
    finishAtMs: kind === 'grooming' ? brushFinishAt : (session) => session.duration_ms,
    canStillCount: canChoreStillCount,
    startMutate,
    finishMutate,
  });
}

/** The first praise tap (ms on the game clock); null = none yet. */
export type PraiseInput = number | null;

export type ScratchingGame = CatSessionGame<ScratchingSession, PraiseInput, ScratchingResult>;

export function useScratchingGame(view: ChildPetView, { clock }: CatGameHookOptions = {}): ScratchingGame {
  const { mutate: startMutate } = useStartScratching();
  const { mutate: finish } = useFinishScratching();
  const finishMutate = useCallback(
    (v: { session: ScratchingSession; input: PraiseInput }, callbacks: Parameters<typeof finish>[1]) =>
      finish({ sessionId: v.session.id, praiseMs: v.input }, callbacks),
    [finish],
  );
  const refusal = useMemo(() => refusalContext(view), [view]);
  return useCatSessionGame<ScratchingSession, PraiseInput, ScratchingResult>({
    clockSkewMs: view.clockSkewMs,
    refusal,
    clock,
    resume: ownSession(view.cat, 'scratching'),
    ...useAbandoned(),
    emptyInput: null,
    finishAtMs: (session, praise) => scratchingFinishAt(session, praise),
    canStillCount: canScratchingStillCount,
    startMutate,
    finishMutate,
  });
}
