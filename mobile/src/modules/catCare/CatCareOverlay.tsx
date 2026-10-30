/**
 * The cat mini-game host (M5-R06-08a): renders the overlay of `appStore.catOverlay` (wand,
 * scoop, weekly litter change, grooming, scratching) over the child HUD — one game at a
 * time (`openCatGame` refuses a second one). The cat HUD dock (R06-08b) opens a game with
 * `useAppStore.getState().openCatGame(kind)` and places `<CatCareOverlay view={view} />`
 * next to the other HUD overlays. A pet without the matching block (a dog, a domestic cat
 * for grooming, an older server) renders nothing. M5-R13: a brushed dog has the `grooming`
 * block, so its "Počeši" opens the same grooming game (with the dog's words).
 */

import { useEffect, useState } from 'react';

import { useReduceMotion } from '@/hooks/useReduceMotion';
import type { CatGameKind, ChildCatCare } from '@/modules/catCare/catCare';
import ChoreOverlay from '@/modules/catCare/ChoreOverlay';
import ScoopOverlay from '@/modules/catCare/ScoopOverlay';
import { loadMessGames } from '@/modules/cleaning/loadMessGames';
import ScratchingOverlay from '@/modules/catCare/ScratchingOverlay';
import WandOverlay from '@/modules/catCare/WandOverlay';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import type { ClockSources } from '@/modules/training/game';
import { useAppStore } from '@/store/appStore';

/** Whether this pet has the data the game needs (else the overlay can't open). */
export function catGameAvailable(cat: ChildCatCare, kind: CatGameKind): boolean {
  switch (kind) {
    case 'wand':
      return cat.wand !== null;
    case 'scoop':
      return cat.litter !== null;
    case 'litter_change':
      return cat.litter?.change != null;
    case 'grooming':
      return cat.grooming !== null;
    case 'scratching':
      return cat.scratching !== null;
  }
}

export interface CatCareOverlayProps {
  view: ChildPetView;
  /** Injected in tests (game clock). */
  clock?: ClockSources;
  /** Overrides the system setting (tests). */
  reduceMotion?: boolean;
}

/**
 * GAMES_SPEC §6.2: the scoop game on the native layer when there is something to scoop; an older
 * binary / a clean tray keeps the tap overlay (and its calm »nothing to scoop«). Decided ONCE per
 * opening — the optimistic scoop empties `open_uses`, which must not swap the game for the overlay.
 */
function ScoopEntry({ view, onClose, reduceMotion }: { view: ChildPetView; onClose: () => void; reduceMotion: boolean }) {
  const [games] = useState(() => ((view.cat.litter?.open_uses.length ?? 0) > 0 ? loadMessGames() : null));
  if (games !== null) return <games.ScoopGame view={view} onClose={onClose} reduceMotion={reduceMotion} />;
  return <ScoopOverlay view={view} onClose={onClose} />;
}

export default function CatCareOverlay({ view, clock, reduceMotion }: CatCareOverlayProps) {
  const kind = useAppStore((s) => s.catOverlay);
  const close = useAppStore((s) => s.closeCatGame);
  const systemReduceMotion = useReduceMotion();
  const reduced = reduceMotion ?? systemReduceMotion;
  const available = kind !== null && catGameAvailable(view.cat, kind);
  // QA m3: a game this pet can't play (a dog, a domestic cat for grooming) never stays "open".
  useEffect(() => {
    if (kind !== null && !available) close();
  }, [available, close, kind]);
  if (kind === null || !available) return null;
  switch (kind) {
    case 'wand':
      return <WandOverlay view={view} onClose={close} reduceMotion={reduced} clock={clock} />;
    case 'scoop':
      return <ScoopEntry view={view} onClose={close} reduceMotion={reduced} />;
    case 'grooming':
    case 'litter_change':
      return <ChoreOverlay key={kind} kind={kind} view={view} onClose={close} reduceMotion={reduced} clock={clock} />;
    case 'scratching':
      return <ScratchingOverlay view={view} onClose={close} reduceMotion={reduced} clock={clock} />;
  }
}
