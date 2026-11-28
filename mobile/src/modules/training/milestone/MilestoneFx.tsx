/**
 * The milestone's success sound + haptic (David 2026-11-28) — the native side, reached ONLY
 * through `loadMilestoneFx()` (lazy `require` behind `gameLayerAvailable()`): `useGameFx` imports
 * expo-audio / expo-haptics, which crash an older binary on import. Positive kinds only (chime +
 * success haptic, once on mount); `enabled` = the parent's `game_feedback` (off → silent).
 */

import { useEffect } from 'react';

import { useGameFx } from '@/modules/gameLayer/useGameFx';

/** The sound waits for its preloaded player (created in `useGameFx`'s effect). */
export const MILESTONE_SOUND_DELAY_MS = 150;

const SOUNDS = ['chime'] as const;

export default function MilestoneFx({ enabled }: { enabled: boolean }) {
  const fx = useGameFx(enabled, SOUNDS);
  useEffect(() => {
    if (!enabled) return;
    fx.sound('chime', MILESTONE_SOUND_DELAY_MS);
    fx.haptic('success');
  }, [enabled, fx]);
  return null;
}
