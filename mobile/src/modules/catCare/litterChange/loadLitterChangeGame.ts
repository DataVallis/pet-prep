/**
 * The weekly litter change with its three gestures (`LitterChangeGame`, David 2026-11-15) — or
 * null on a binary without the native game layer, which keeps the card screen with the rub.
 *
 * `LitterChangeGame` imports reanimated and gesture-handler — required ONLY here, only behind
 * `gameLayerAvailable()` (same pattern as `loadCareShell`); a failed load also keeps the rub.
 */

import { gameLayerAvailable } from '@/modules/gameLayer/capability';
import type LitterChangeGameComponent from '@/modules/catCare/litterChange/LitterChangeGame';

export type LitterChangeGameType = typeof LitterChangeGameComponent;
let loaded: LitterChangeGameType | null | undefined;

export function loadLitterChangeGame(): LitterChangeGameType | null {
  if (!gameLayerAvailable()) return null;
  if (loaded === undefined) {
    try {
      loaded = (require('./LitterChangeGame') as { default: LitterChangeGameType }).default;
    } catch {
      loaded = null;
    }
  }
  return loaded;
}

/** Tests: forget the loaded module (after toggling the capability flag). */
export function resetLitterChangeGameForTests(): void {
  loaded = undefined;
}
