/**
 * The cleaning games of the native game layer (GAMES_SPEC §6.2, §7.4) — or null on a binary
 * without the layer, which keeps today's tap overlays (`CleaningOverlay`, `ScoopOverlay`, the
 * »Pospravi in daj igračo« button).
 *
 * The game modules import gesture-handler, reanimated, skia, expo-audio and expo-haptics, which
 * crash an older binary on import — so they are required ONLY here, only behind
 * `gameLayerAvailable()` (same pattern as `PlayOverlay#loadBallGameV2`); `import type` is erased
 * at build time. A failed load also keeps the old overlays.
 */

import { gameLayerAvailable } from '@/modules/gameLayer/capability';
import type MessGameComponent from '@/modules/cleaning/MessGame';
import type ScoopGameComponent from '@/modules/cleaning/ScoopGame';
import type SlipperGameComponent from '@/modules/cleaning/SlipperGame';

export interface MessGames {
  MessGame: typeof MessGameComponent;
  SlipperGame: typeof SlipperGameComponent;
  ScoopGame: typeof ScoopGameComponent;
}

let loaded: MessGames | null | undefined;

export function loadMessGames(): MessGames | null {
  if (!gameLayerAvailable()) return null;
  if (loaded === undefined) {
    try {
      loaded = {
        MessGame: (require('./MessGame') as { default: typeof MessGameComponent }).default,
        SlipperGame: (require('./SlipperGame') as { default: typeof SlipperGameComponent }).default,
        ScoopGame: (require('./ScoopGame') as { default: typeof ScoopGameComponent }).default,
      };
    } catch {
      loaded = null;
    }
  }
  return loaded;
}

/** Tests: forget the loaded modules (after toggling the capability flag). */
export function resetMessGamesForTests(): void {
  loaded = undefined;
}
