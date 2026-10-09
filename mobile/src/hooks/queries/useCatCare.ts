/**
 * Cat care mutations (M5-R06-08a): the server-led mini-games and the litter scoop.
 *
 * - Start / finish of wand play, grooming, the weekly litter change and the scratching
 *   carry are NOT optimistic (like training): the server generates the schedule and judges
 *   the finish. Every answer with a `state` (200, 422 refusal, 423 lock) replaces the child
 *   state in the cache; without one (offline, 5xx, 429) the state is refetched. A 200 whose
 *   body isn't a playable session / a verdict throws `CatResponseError` (its state, if any,
 *   still lands in the cache — a schedule is never guessed).
 * - The scoop IS optimistic (like cleaning): the open litter uses disappear at once, then
 *   the server's state ALWAYS replaces the cache; without one only the tray is restored on
 *   top of the current cache (a broadcast may have landed meanwhile) and the state refetched.
 * Answers that arrive after the session changed (logout / another child) are ignored.
 * `networkMode: 'always'`: a start that fires later (after reconnecting) would begin a game
 * nobody watches.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query';

import { api, type ChildPetState } from '@/api/client';
import { childPetKey, writeChildState } from '@/hooks/queries/useChildPet';
import { currentUserId, sameSession, settleFromError, type SessionContext } from '@/hooks/queries/useChildActions';
import {
  readCatFinishResponse,
  readCatStartResponse,
  readChoreResult,
  readChoreSession,
  readScoopResponse,
  readScratchingResult,
  readScratchingSession,
  readWandResult,
  readWandSession,
  stateOfBody,
  type CatFinishResponse,
  type CatStartResponse,
  type ChoreKind,
  type ChoreResult,
  type ChoreSession,
  type ScoopResponse,
  type ScratchingResult,
  type ScratchingSession,
  type WandResult,
  type WandSession,
} from '@/modules/catCare/catCare';
import type { ChildPetView } from '@/modules/childPet/childPetView';

import type { WandMove } from '@/modules/catCare/catGames';
import { classifyActionError } from '@/modules/childPet/actionMessages';

/** A 200 whose body isn't what the contract promises (never guess a schedule / verdict). */
export class CatResponseError extends Error {
  constructor(public readonly state: ChildPetState | null) {
    super('Unexpected cat care response');
    this.name = 'CatResponseError';
  }
}

/** Shared settle: a malformed 200 still lands its state; everything else like the care actions. */
function settleCatError(client: ReturnType<typeof useQueryClient>, error: unknown, context: SessionContext | undefined): void {
  if (error instanceof CatResponseError) {
    if (!sameSession(context)) return;
    if (error.state) writeChildState(client, error.state);
    else void client.invalidateQueries({ queryKey: childPetKey });
    return;
  }
  settleFromError(client, error, null, context);
}

function useSessionMutation<V, T extends { state: ChildPetState }>(key: readonly string[], call: (variables: V) => Promise<unknown>, read: (raw: unknown) => T | null) {
  const client = useQueryClient();
  return useMutation<T, unknown, V, SessionContext>({
    mutationKey: ['child', 'pet', ...key],
    networkMode: 'always',
    onMutate: () => ({ userId: currentUserId() }),
    mutationFn: async (variables) => {
      const raw = await call(variables);
      const parsed = read(raw);
      if (parsed === null) throw new CatResponseError(stateOfBody(raw));
      return parsed;
    },
    onSuccess: (response, _variables, context) => {
      if (sameSession(context)) writeChildState(client, response.state);
    },
    onError: (error, _variables, context) => settleCatError(client, error, context),
  });
}

// ── Wand play ─────────────────────────────────────────────────

export const useStartWand = () =>
  useSessionMutation<void, CatStartResponse<WandSession>>(['wand', 'start'], () => api.startWand(), (raw) => readCatStartResponse(raw, readWandSession));

export interface FinishWandVariables {
  sessionId: string;
  moves: readonly WandMove[];
}

export const useFinishWand = () =>
  useSessionMutation<FinishWandVariables, CatFinishResponse<WandResult>>(
    ['wand', 'finish'],
    ({ sessionId, moves }) => api.finishWand(sessionId, moves),
    (raw) => readCatFinishResponse(raw, readWandResult),
  );

// ── Grooming / weekly litter change ───────────────────────────

export const useStartChore = (kind: ChoreKind) =>
  useSessionMutation<void, CatStartResponse<ChoreSession>>(
    [kind, 'start'],
    () => api.startCareChore(kind),
    (raw) => readCatStartResponse(raw, (value) => readChoreSession(value, kind)),
  );

export interface FinishChoreVariables {
  sessionId: string;
  strokes: readonly number[];
}

export const useFinishChore = (kind: ChoreKind) =>
  useSessionMutation<FinishChoreVariables, CatFinishResponse<ChoreResult>>(
    [kind, 'finish'],
    ({ sessionId, strokes }) => api.finishCareChore(kind, sessionId, strokes),
    (raw) => readCatFinishResponse(raw, readChoreResult),
  );

// ── Scratching ────────────────────────────────────────────────

export const useStartScratching = () =>
  useSessionMutation<void, CatStartResponse<ScratchingSession>>(
    ['scratching', 'start'],
    () => api.startScratching(),
    (raw) => readCatStartResponse(raw, readScratchingSession),
  );

export interface FinishScratchingVariables {
  sessionId: string;
  praiseMs: number | null;
}

export const useFinishScratching = () =>
  useSessionMutation<FinishScratchingVariables, CatFinishResponse<ScratchingResult>>(
    ['scratching', 'finish'],
    ({ sessionId, praiseMs }) => api.finishScratching(sessionId, praiseMs),
    (raw) => readCatFinishResponse(raw, readScratchingResult),
  );

// ── Scoop (optimistic) ────────────────────────────────────────

interface ScoopContext extends SessionContext {
  previous: ChildPetView | undefined;
}

/** The tray right after a scoop: nothing open, nothing to scoop (an expired use's mess stays in `behaviour`). */
export function optimisticScoop(view: ChildPetView): ChildPetView {
  const litter = view.cat.litter;
  if (litter === null) return view;
  return { ...view, cat: { ...view.cat, litter: { ...litter, open_uses: [], next_due_at: null, can_scoop: false } } };
}

/** Undo only the tray on top of the current cache (other fields may have moved on). */
export function revertScoop(current: ChildPetView, previous: ChildPetView): ChildPetView {
  return { ...current, cat: { ...current.cat, litter: previous.cat.litter } };
}

export function useScoopLitter() {
  const client = useQueryClient();
  return useMutation<ScoopResponse, unknown, void, ScoopContext>({
    mutationKey: ['child', 'pet', 'litter', 'scoop'],
    networkMode: 'always',
    mutationFn: async () => {
      const raw = await api.scoopLitter();
      const parsed = readScoopResponse(raw);
      if (parsed === null) throw new CatResponseError(stateOfBody(raw));
      return parsed;
    },
    onMutate: async () => {
      // A poll landing mid-flight would overwrite the optimistic tray with the old one.
      await client.cancelQueries({ queryKey: childPetKey });
      const previous = client.getQueryData<ChildPetView>(childPetKey);
      if (previous) client.setQueryData<ChildPetView>(childPetKey, optimisticScoop(previous));
      return { previous, userId: currentUserId() };
    },
    onSuccess: (response, _variables, context) => {
      if (sameSession(context)) writeChildState(client, response.state);
    },
    onError: (error, _variables, context) => {
      if (!sameSession(context)) return;
      // The server's word wins whenever the answer carried a state (malformed 200, 422, 423).
      const failure = error instanceof CatResponseError ? null : classifyActionError(error);
      const state =
        error instanceof CatResponseError
          ? error.state
          : failure !== null && (failure.kind === 'refused' || failure.kind === 'locked')
            ? failure.state
            : null;
      if (state) {
        writeChildState(client, state);
        return;
      }
      const current = client.getQueryData<ChildPetView>(childPetKey);
      if (context?.previous && current) client.setQueryData<ChildPetView>(childPetKey, revertScoop(current, context.previous));
      void client.invalidateQueries({ queryKey: childPetKey });
    },
  });
}
