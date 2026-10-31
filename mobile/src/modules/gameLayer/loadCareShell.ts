/**
 * The care games' game-layer shell (GAMES_SPEC §5.5 / §7.7) — or null on a binary without the
 * native layer, which keeps the old card screens of grooming, the litter change, the breed care
 * tasks and the scratching post (same rules either way).
 *
 * `CareGameShell` imports the native layer (gesture-handler via `GameScreen`, reanimated,
 * expo-audio, expo-haptics) — required ONLY here, only behind `gameLayerAvailable()`.
 */

import { gameLayerAvailable } from '@/modules/gameLayer/capability';
import type CareGameShellComponent from '@/modules/gameLayer/CareGameShell';

export type CareGameShellType = typeof CareGameShellComponent;
let loaded: CareGameShellType | null | undefined;

export function loadCareShell(): CareGameShellType | null {
  if (!gameLayerAvailable()) return null;
  if (loaded === undefined) {
    try {
      loaded = (require('./CareGameShell') as { default: CareGameShellType }).default;
    } catch {
      loaded = null;
    }
  }
  return loaded;
}

/** Tests: forget the loaded module (after toggling the capability flag). */
export function resetCareShellForTests(): void {
  loaded = undefined;
}
