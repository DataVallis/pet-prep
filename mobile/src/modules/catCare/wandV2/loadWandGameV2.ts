/**
 * The v2 wand hunt (GAMES_SPEC §5) — or null on a binary without the native game layer, which
 * keeps the v1 `WandOverlay` (no `rules` param → the server's v1 minute, unchanged).
 *
 * `WandGameV2` imports gesture-handler, reanimated, skia, expo-audio and expo-haptics, which
 * crash an older binary on import — so it is required ONLY here, only behind
 * `gameLayerAvailable()` (same pattern as `PlayOverlay#loadBallGameV2`); `import type` is erased.
 * A failed load also keeps v1.
 */

import { gameLayerAvailable } from '@/modules/gameLayer/capability';
import type WandGameV2Component from '@/modules/catCare/wandV2/WandGameV2';

type WandGameV2Type = typeof WandGameV2Component;
let loaded: WandGameV2Type | null | undefined;

export function loadWandGameV2(): WandGameV2Type | null {
  if (!gameLayerAvailable()) return null;
  if (loaded === undefined) {
    try {
      loaded = (require('./WandGameV2') as { default: WandGameV2Type }).default;
    } catch {
      loaded = null;
    }
  }
  return loaded;
}

/** Tests: forget the loaded module (after toggling the capability flag). */
export function resetWandGameV2ForTests(): void {
  loaded = undefined;
}
