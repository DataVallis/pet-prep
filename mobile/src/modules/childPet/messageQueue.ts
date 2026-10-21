/**
 * Child HUD messages (M5-T03, APP_REFINEMENT_SPEC §12, David 2026-10-21): every message stays
 * at least 2.5 s + 60 ms per character, messages wait in a FIFO queue (one at a time, never
 * overlapping), a tap closes the shown one (the next follows), and an identical message right
 * after the same one is dropped (a double tap never shows the same text twice in a row).
 * No "text speed" setting for now (David 2026-10-21).
 *
 * Pure state + one small hook (`useMessageQueue`) that owns the single timer.
 */

import { useCallback, useEffect, useReducer } from 'react';

export const MESSAGE_MIN_MS = 2_500;
export const MESSAGE_MS_PER_CHAR = 60;

export interface HudMessageInput {
  tone: 'ok' | 'info';
  message: string;
  /**
   * Optional "next time" line of a confirmation (M5-T01, spec §4): "Naslednjič čez ~2 h."
   * Only when the server state already carries the time — never invented.
   */
  next?: string | null;
  /** A care confirmation (✓ card) rather than a plain note. */
  confirm?: boolean;
}

export interface HudMessage extends HudMessageInput {
  /** Monotonic id (a repeated text later in the queue is a new message). */
  id: number;
}

export interface MessageQueueState {
  current: HudMessage | null;
  pending: HudMessage[];
  seq: number;
}

export const EMPTY_MESSAGE_QUEUE: MessageQueueState = { current: null, pending: [], seq: 0 };

/** Everything the child reads in the message (the duration counts all of it). */
export function messageText(m: HudMessageInput): string {
  return m.next ? `${m.message} ${m.next}` : m.message;
}

/** 2500 ms + 60 ms per character (code points, so "č" / "✓" count once). */
export function messageDurationMs(text: string): number {
  return MESSAGE_MIN_MS + MESSAGE_MS_PER_CHAR * Array.from(text).length;
}

export function sameMessage(a: HudMessageInput, b: HudMessageInput): boolean {
  return a.tone === b.tone && a.message === b.message && (a.next ?? null) === (b.next ?? null) && (a.confirm ?? false) === (b.confirm ?? false);
}

/** Adds a message; shown at once when nothing shows. Identical to the one just before it → dropped. */
export function enqueueMessage(state: MessageQueueState, input: HudMessageInput): MessageQueueState {
  const last = state.pending.length > 0 ? state.pending[state.pending.length - 1] : state.current;
  if (last !== null && sameMessage(last, input)) return state;
  const message: HudMessage = { ...input, id: state.seq + 1 };
  if (state.current === null) return { current: message, pending: state.pending, seq: message.id };
  return { current: state.current, pending: [...state.pending, message], seq: message.id };
}

/** The shown message is done (time up or tapped): the next one, if any, shows. */
export function advanceQueue(state: MessageQueueState): MessageQueueState {
  if (state.current === null) return state;
  const [next = null, ...rest] = state.pending;
  return { current: next, pending: rest, seq: state.seq };
}

type Action = { type: 'show'; input: HudMessageInput } | { type: 'advance'; id: number };

function reducer(state: MessageQueueState, action: Action): MessageQueueState {
  switch (action.type) {
    case 'show':
      return enqueueMessage(state, action.input);
    case 'advance':
      // A stale timer / double tap for a message that is already gone does nothing.
      return state.current !== null && state.current.id === action.id ? advanceQueue(state) : state;
  }
}

export interface MessageQueue {
  /** The message on screen (null = none). */
  current: HudMessage | null;
  /** Queue a message. */
  show: (input: HudMessageInput) => void;
  /** Close the shown message (tap); the next one follows. */
  dismiss: () => void;
}

export function useMessageQueue(): MessageQueue {
  const [state, dispatch] = useReducer(reducer, EMPTY_MESSAGE_QUEUE);
  const current = state.current;
  useEffect(() => {
    if (current === null) return;
    const id = setTimeout(() => dispatch({ type: 'advance', id: current.id }), messageDurationMs(messageText(current)));
    return () => clearTimeout(id);
  }, [current]);
  const show = useCallback((input: HudMessageInput) => dispatch({ type: 'show', input }), []);
  const currentId = current?.id ?? null;
  const dismiss = useCallback(() => {
    if (currentId !== null) dispatch({ type: 'advance', id: currentId });
  }, [currentId]);
  return { current, show, dismiss };
}
