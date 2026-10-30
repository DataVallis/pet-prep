/**
 * GameClock (GAMES_SPEC §7.1) = the school's `SessionClock` (`training/game.ts`): a monotonic
 * clock with a wall-clock resync. A game starts it when its ClipDeck is ready — never earlier,
 * so the first clip and the first timer begin together.
 */

import { useCallback, useRef } from 'react';

import { SessionClock } from '@/modules/training/game';

export { SessionClock };

export interface GameClock {
  /** Start (once): from now on `now()` counts. */
  start: () => void;
  /** ms since `start` (0 before). */
  now: () => number;
  started: () => boolean;
}

export function useGameClock(): GameClock {
  const clock = useRef<SessionClock | null>(null);
  const start = useCallback(() => {
    if (clock.current === null) clock.current = new SessionClock();
  }, []);
  const now = useCallback(() => {
    const c = clock.current;
    if (c === null) return 0;
    c.resync();
    return c.elapsed();
  }, []);
  const started = useCallback(() => clock.current !== null, []);
  return { start, now, started };
}
