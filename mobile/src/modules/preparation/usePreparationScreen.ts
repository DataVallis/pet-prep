/**
 * M5-T06: whether the HUD shows the set-up screen now. The server decides (`preparing`, and
 * it stops saying so 15 min after the birth / purchase); this hook adds the same cap on the
 * device from the moment the screen first showed for the pet, so a clip that never arrives
 * (lost answer — the server gives up much later) can't keep the child from the pet. After
 * the cap the HUD shows with the pose and »… se še pripravlja« where something is missing.
 */

import { useEffect, useState } from 'react';

export const PREPARATION_SCREEN_MAX_MS = 15 * 60 * 1000;

export function usePreparationScreen(petId: number | null, serverSaysPreparing: boolean): boolean {
  const [expiredFor, setExpiredFor] = useState<number | null>(null);
  const active = serverSaysPreparing && petId !== null && expiredFor !== petId;

  useEffect(() => {
    if (!active || petId === null) return;
    const id = setTimeout(() => setExpiredFor(petId), PREPARATION_SCREEN_MAX_MS);
    return () => clearTimeout(id);
  }, [active, petId]);

  return active;
}
