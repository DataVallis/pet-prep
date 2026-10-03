/**
 * Seconds remaining until an ISO deadline, re-rendering once per second.
 * Computed from the wall clock on every tick (not decremented), so a
 * backgrounded app shows the right value as soon as it ticks again.
 */

import { useEffect, useState } from 'react';

import { secondsUntil } from '@/modules/pairing/pin';

export function useCountdown(deadline: string | null): number {
  const [remaining, setRemaining] = useState(() => (deadline ? secondsUntil(deadline) : 0));

  useEffect(() => {
    if (!deadline) {
      setRemaining(0);
      return;
    }
    setRemaining(secondsUntil(deadline));
    const id = setInterval(() => {
      const next = secondsUntil(deadline);
      setRemaining(next);
      if (next <= 0) clearInterval(id);
    }, 1000);
    return () => clearInterval(id);
  }, [deadline]);

  return remaining;
}
