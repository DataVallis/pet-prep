/**
 * Pure helpers for the parent "Dodaj otroka" PIN screen (M2-02).
 */

import { ApiError } from '@/api/client';

/** "734912" → "734 912" (easier to read aloud to a child). Non-6-digit input is returned trimmed. */
export function formatPin(pin: string): string {
  const digits = pin.trim();
  return /^\d{6}$/.test(digits) ? `${digits.slice(0, 3)} ${digits.slice(3)}` : digits;
}

/** Whole seconds left until `expiresAt` (ISO 8601), never negative; 0 for an unparsable date. */
export function secondsUntil(expiresAt: string, now: number = Date.now()): number {
  const target = Date.parse(expiresAt);
  if (Number.isNaN(target)) return 0;
  return Math.max(0, Math.ceil((target - now) / 1000));
}

/** 900 → "15:00", 59 → "0:59". */
export function formatCountdown(totalSeconds: number): string {
  const safe = Math.max(0, Math.floor(totalSeconds));
  const minutes = Math.floor(safe / 60);
  const seconds = safe % 60;
  return `${minutes}:${seconds.toString().padStart(2, '0')}`;
}

export type PinErrorKind =
  | 'rate_limited'
  | 'forbidden'
  | 'unauthorized'
  | 'child_not_found'
  | 'pet_not_joinable'
  | 'already_paired'
  | 'offline'
  | 'server';

/** The machine `reason` of an API error body (`{message, reason}`), if any. */
export function reasonOf(error: ApiError): string | null {
  const data = error.data;
  if (typeof data === 'object' && data !== null && 'reason' in data) {
    const { reason } = data as { reason: unknown };
    if (typeof reason === 'string') return reason;
  }
  return null;
}

export interface PinError {
  kind: PinErrorKind;
  /**
   * Seconds to wait before "Nova koda" works again (rate_limited only).
   * null when Retry-After is 0 or negative (no wait to show).
   */
  retryAfterSeconds: number | null;
}

/** Fallback wait when a 429 has no Retry-After header (the limiter window is 1 min). */
export const DEFAULT_RETRY_AFTER_SECONDS = 60;

/** Map a generate-pin failure to something the screen can explain. */
export function classifyPinError(error: unknown): PinError {
  if (error instanceof ApiError) {
    if (error.status === 429) {
      const header = error.retryAfterSeconds;
      if (header === null) return { kind: 'rate_limited', retryAfterSeconds: DEFAULT_RETRY_AFTER_SECONDS };
      return { kind: 'rate_limited', retryAfterSeconds: header > 0 ? header : null };
    }
    if (error.status === 403) return { kind: 'forbidden', retryAfterSeconds: null };
    if (error.status === 401) return { kind: 'unauthorized', retryAfterSeconds: null };
    if (error.status === 404) return { kind: 'child_not_found', retryAfterSeconds: null };
    const reason = reasonOf(error);
    if (error.status === 422 && (reason === 'pet_not_joinable' || reason === 'already_paired')) {
      return { kind: reason, retryAfterSeconds: null };
    }
    return { kind: 'server', retryAfterSeconds: null };
  }
  // fetch() rejects with a TypeError when there is no connection.
  return { kind: 'offline', retryAfterSeconds: null };
}
