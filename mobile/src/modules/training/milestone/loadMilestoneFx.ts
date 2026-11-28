/**
 * `loadMilestoneFx()` — the milestone's sound / haptic component, or null on a binary without the
 * native game layer (pure; safe to import anywhere). Pattern of `TrainingOverlay#loadSchoolGameV2`:
 * never import `MilestoneFx` statically. A failed load = no sound (the screen is unchanged).
 */

import type { ComponentType } from 'react';

import { gameLayerAvailable } from '@/modules/gameLayer/capability';

type MilestoneFxType = ComponentType<{ enabled: boolean }>;
let loaded: MilestoneFxType | null | undefined;

export function loadMilestoneFx(): MilestoneFxType | null {
  if (!gameLayerAvailable()) return null;
  if (loaded === undefined) {
    try {
      loaded = (require('./MilestoneFx') as { default: MilestoneFxType }).default;
    } catch {
      loaded = null;
    }
  }
  return loaded;
}

/** Tests: forget the loaded module (after toggling the capability flag). */
export function resetMilestoneFxForTests(): void {
  loaded = undefined;
}
