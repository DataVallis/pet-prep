/**
 * Bound any promise in time (boot-hang fix 2026-10-19). A native call (Keychain, URLSession)
 * that never settles must never leave a screen waiting forever: the race rejects with a
 * {@link TimeoutError} after `ms`, `onTimeout` can cancel the underlying work (abort a fetch).
 * The timer is always cleared, so Jest fake timers never see a stray one.
 */

export class TimeoutError extends Error {
  constructor(message = 'Timed out') {
    super(message);
    this.name = 'TimeoutError';
  }
}

export function isTimeoutError(error: unknown): error is TimeoutError {
  return error instanceof TimeoutError;
}

export function withTimeout<T>(promise: Promise<T>, ms: number, onTimeout?: () => void, label = 'operation'): Promise<T> {
  let timer: ReturnType<typeof setTimeout> | null = null;
  const expiry = new Promise<never>((_, reject) => {
    timer = setTimeout(() => {
      timer = null;
      try {
        onTimeout?.();
      } finally {
        reject(new TimeoutError(`${label} timed out after ${ms} ms`));
      }
    }, ms);
  });
  return Promise.race([promise, expiry]).finally(() => {
    if (timer !== null) clearTimeout(timer);
  });
}

/** Like {@link withTimeout}, but a timeout or a rejection resolves to `fallback` (never throws). */
export async function withTimeoutOr<T>(promise: Promise<T>, ms: number, fallback: T): Promise<T> {
  try {
    return await withTimeout(promise, ms);
  } catch {
    return fallback;
  }
}
