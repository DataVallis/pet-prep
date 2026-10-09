/**
 * The three server-led cat games wired to their mutations (M5-R06-08a): `useWandGame`,
 * `useChoreGame` (grooming / weekly litter change), `useScratchingGame`. Each is
 * `useCatSessionGame` with its input, its finish moment and the resume session read from
 * the child state (`view.cat`).
 */

import { useCallback, useMemo } from 'react';

import { useFinishChore, useFinishScratching, useFinishWand, useStartChore, useStartScratching, useStartWand } from '@/hooks/queries/useCatCare';
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
  return { nowIso: view.server_time, timezone: view.timezone, scratchingOnly: onlyScratchingOpen(view.cat) };
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
    finishAtMs: (session) => session.duration_ms,
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
