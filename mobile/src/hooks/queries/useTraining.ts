/**
 * Training mutations (M5-R03): `useStartTraining` and `useFinishTraining`.
 * GAMES_SPEC §7.2: `rules: 2` (school v2) on start and `endedAfterRound: 1` (Š3) on finish are
 * sent only when set — a v1 call is byte for byte the old one.
 *
 * Not optimistic — the server generates the schedule and scores the taps. Every answer
 * with a `state` (200, 422 refusal, 423 lock) replaces the child state in the cache, like
 * the care actions; without one (offline, 5xx, 429) the state is refetched. The 200
 * bodies are untyped in `schema.ts`, so they are read with the narrow parsers in
 * `modules/training/training.ts`; a body that isn't a playable session / a result throws
 * `TrainingResponseError` (the state, if any, still lands in the cache). Answers that
 * arrive after the session changed (logout / another child) are ignored.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '@/api/client';
import { childPetKey, writeChildState } from '@/hooks/queries/useChildPet';
import { currentUserId, sameSession, settleFromError, type SessionContext } from '@/hooks/queries/useChildActions';
import {
  readFinishResponse,
  readStartResponse,
  type FinishTrainingResponse,
  type StartTrainingResponse,
  type TrainingCommand,
} from '@/modules/training/training';
import type { ChildPetState } from '@/api/client';

/** A 200 whose body isn't what the contract promises (never guess a schedule). */
export class TrainingResponseError extends Error {
  constructor(public readonly state: ChildPetState | null) {
    super('Unexpected training response');
    this.name = 'TrainingResponseError';
  }
}

function stateOfBody(raw: unknown): ChildPetState | null {
  if (typeof raw !== 'object' || raw === null) return null;
  const state = (raw as { state?: unknown }).state;
  return typeof state === 'object' && state !== null && 'pet' in state ? (state as ChildPetState) : null;
}

export interface StartTrainingVariables {
  command: TrainingCommand;
  /** GAMES_SPEC §7.2: 2 = school v2 (only with the game layer); omitted = v1. */
  rules?: 2;
}

export function useStartTraining() {
  const client = useQueryClient();
  return useMutation<StartTrainingResponse, unknown, StartTrainingVariables, SessionContext>({
    mutationKey: ['child', 'pet', 'training', 'start'],
    // A start that fires later (after reconnecting) would begin a game nobody watches.
    networkMode: 'always',
    onMutate: () => ({ userId: currentUserId() }),
    mutationFn: async ({ command, rules }) => {
      const raw = rules === undefined ? await api.startTraining(command) : await api.startTraining(command, rules);
      const parsed = readStartResponse(raw);
      if (parsed === null) throw new TrainingResponseError(stateOfBody(raw));
      return parsed;
    },
    onSuccess: (response, _command, context) => {
      if (sameSession(context)) writeChildState(client, response.state);
    },
    onError: (error, _command, context) => {
      if (error instanceof TrainingResponseError) {
        if (!sameSession(context)) return;
        if (error.state) writeChildState(client, error.state);
        else void client.invalidateQueries({ queryKey: childPetKey });
        return;
      }
      settleFromError(client, error, null, context);
    },
  });
}

export interface FinishTrainingVariables {
  sessionId: string;
  taps: readonly number[];
  /** Š3 (rules v2): the child pressed »Končaj« after round 1. */
  endedAfterRound?: 1;
}

export function useFinishTraining() {
  const client = useQueryClient();
  return useMutation<FinishTrainingResponse, unknown, FinishTrainingVariables, SessionContext>({
    mutationKey: ['child', 'pet', 'training', 'finish'],
    networkMode: 'always',
    onMutate: () => ({ userId: currentUserId() }),
    mutationFn: async ({ sessionId, taps, endedAfterRound }) => {
      const raw = endedAfterRound === undefined ? await api.finishTraining(sessionId, taps) : await api.finishTraining(sessionId, taps, endedAfterRound);
      const parsed = readFinishResponse(raw);
      if (parsed === null) throw new TrainingResponseError(stateOfBody(raw));
      return parsed;
    },
    onSuccess: (response, _variables, context) => {
      if (sameSession(context)) writeChildState(client, response.state);
    },
    onError: (error, _variables, context) => {
      if (error instanceof TrainingResponseError) {
        if (!sameSession(context)) return;
        if (error.state) writeChildState(client, error.state);
        else void client.invalidateQueries({ queryKey: childPetKey });
        return;
      }
      settleFromError(client, error, null, context);
    },
  });
}
