/**
 * Play & cuddle mutation (M5-R05): `usePlay()` reports one finished mini-game
 * (`POST /api/child/pet/play {kind}`).
 *
 * Optimistic: the dog is happy at once (`optimisticPlay` — 30 min, the `playing` scene only
 * when nothing more important shows, the invitation of that kind done); then the cache is
 * ALWAYS replaced by the server's `state` (200, 422 `play_not_available`, 423 lock). A lost
 * connection is retried at most twice and only within 8 s of the first attempt (one request
 * per finished game; the server treats a repeat within 10 s as the same play). Without any server answer the optimistic `play` is undone
 * and the state refetched. Answers that arrive after the session changed are ignored.
 */

import { useRef } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { api, type ChildPetState } from '@/api/client';
import { childPetKey, writeChildState } from '@/hooks/queries/useChildPet';
import { currentUserId, sameSession, type SessionContext } from '@/hooks/queries/useChildActions';
import { classifyActionError } from '@/modules/childPet/actionMessages';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { optimisticPlay, readPlayResponse, type PlayKind, type PlayResponse } from '@/modules/play/play';

/** A 200 whose body isn't what the contract promises. */
export class PlayResponseError extends Error {
  constructor(public readonly state: ChildPetState | null) {
    super('Unexpected play response');
    this.name = 'PlayResponseError';
  }
}

interface PlayContext extends SessionContext {
  previous: ChildPetView | undefined;
}

/** Retries after a lost connection (never after a server answer). */
export const PLAY_RETRIES = 2;
export const PLAY_RETRY_DELAY_MS = 1_500;

/**
 * A retry must reach the server within this long of the first attempt, so it stays inside
 * the server's 10 s "same play" window (a later one would count a second play) — QA PR #86 m2.
 */
export const PLAY_RETRY_WINDOW_MS = 8_000;

/** `elapsedMs` = time since the first attempt started; the retry fires `PLAY_RETRY_DELAY_MS` later. */
export function shouldRetryPlay(failureCount: number, error: unknown, elapsedMs = 0): boolean {
  if (error instanceof PlayResponseError) return false;
  if (!Number.isFinite(elapsedMs) || elapsedMs + PLAY_RETRY_DELAY_MS > PLAY_RETRY_WINDOW_MS) return false;
  return failureCount < PLAY_RETRIES && classifyActionError(error).kind === 'offline';
}

function stateOfBody(raw: unknown): ChildPetState | null {
  if (typeof raw !== 'object' || raw === null) return null;
  const state = (raw as { state?: unknown }).state;
  return typeof state === 'object' && state !== null && 'pet' in state ? (state as ChildPetState) : null;
}

export function usePlay() {
  const client = useQueryClient();
  // Start of the current report (one game at a time) for the retry window.
  const startedAt = useRef(0);

  /** No server state to show: undo only the optimistic `play` on top of the cache, then refetch. */
  const revert = (context: PlayContext | undefined) => {
    const current = client.getQueryData<ChildPetView>(childPetKey);
    const previous = context?.previous;
    // A broadcast since then already carries the server's `play`.
    if (current && previous && current.lastEmittedMs <= previous.lastEmittedMs) {
      client.setQueryData<ChildPetView>(childPetKey, { ...current, play: previous.play });
    }
    void client.invalidateQueries({ queryKey: childPetKey });
  };

  return useMutation<PlayResponse, unknown, PlayKind, PlayContext>({
    mutationKey: ['child', 'pet', 'play'],
    // A finished game is reported now; a paused request that fires an hour later would confuse.
    networkMode: 'always',
    retry: (failureCount, error) => shouldRetryPlay(failureCount, error, Date.now() - startedAt.current),
    retryDelay: PLAY_RETRY_DELAY_MS,
    onMutate: async (kind) => {
      startedAt.current = Date.now();
      await client.cancelQueries({ queryKey: childPetKey });
      const previous = client.getQueryData<ChildPetView>(childPetKey);
      if (previous) {
        client.setQueryData<ChildPetView>(childPetKey, optimisticPlay(previous, kind, Date.now() + previous.clockSkewMs));
      }
      return { previous, userId: currentUserId() };
    },
    mutationFn: async (kind) => {
      const raw = await api.playWithPet(kind);
      const parsed = readPlayResponse(raw);
      if (parsed === null) throw new PlayResponseError(stateOfBody(raw));
      return parsed;
    },
    onSuccess: (response, _kind, context) => {
      if (sameSession(context)) writeChildState(client, response.state);
    },
    onError: (error, _kind, context) => {
      if (!sameSession(context)) return;
      if (error instanceof PlayResponseError) {
        if (error.state) writeChildState(client, error.state);
        else revert(context);
        return;
      }
      const failure = classifyActionError(error);
      if ((failure.kind === 'refused' || failure.kind === 'locked') && failure.state) {
        writeChildState(client, failure.state);
        return;
      }
      revert(context);
    },
  });
}
