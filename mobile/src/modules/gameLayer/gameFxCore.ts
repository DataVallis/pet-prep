/**
 * GameFx rules (GAMES_SPEC §3.1, §7.1, §7.6), pure — the native adapters (`useGameFx.ts`:
 * expo-audio, expo-haptics) are injected, so Jest checks the rules without native modules.
 *
 * - Everything is off when the parent's »Zvoki in vibracija v igrah« is off
 *   (`settings.game_feedback`, default on).
 * - Haptics are POSITIVE only: `tap` (light), `success`, `cue` (selection). There is no kind
 *   for a miss — a miss never vibrates and never makes a sound.
 * - A sound / haptic never throws into the game: every native call is guarded.
 * - Sounds follow the phone's silent mode (the adapter sets `playsInSilentMode: false`).
 */

export type GameSound = 'whoosh' | 'bounce' | 'whistle' | 'chime' | 'pop' | 'cue' | 'swish' | 'purr' | 'heart';
export const GAME_SOUNDS: readonly GameSound[] = ['whoosh', 'bounce', 'whistle', 'chime', 'pop', 'cue', 'swish', 'purr', 'heart'];
/** What `useGameFx` preloads when a game names nothing (the phase 1 set: ball, messes). */
export const DEFAULT_GAME_SOUNDS: readonly GameSound[] = ['whoosh', 'bounce', 'whistle', 'chime', 'pop'];
export type GameHaptic = 'tap' | 'success' | 'cue';

export interface SoundAdapter {
  play: (sound: GameSound) => void;
  release: () => void;
}

export interface HapticAdapter {
  impactLight: () => Promise<void> | void;
  success: () => Promise<void> | void;
  selection: () => Promise<void> | void;
}

export interface GameFx {
  sound: (sound: GameSound, delayMs?: number) => void;
  haptic: (haptic: GameHaptic) => void;
  /** Stops pending delayed sounds and releases the players (unmount). */
  release: () => void;
}

export const SILENT_GAME_FX: GameFx = { sound: () => undefined, haptic: () => undefined, release: () => undefined };

function guard(call: () => Promise<void> | void): void {
  try {
    const result = call();
    if (result && typeof (result as Promise<void>).catch === 'function') (result as Promise<void>).catch(() => undefined);
  } catch {
    // A missing / failing haptic engine or audio session never breaks the game.
  }
}

export function createGameFx(options: { enabled: boolean; sounds: SoundAdapter | null; haptics: HapticAdapter | null }): GameFx {
  const { sounds, haptics } = options;
  if (!options.enabled) {
    return { ...SILENT_GAME_FX, release: () => guard(() => sounds?.release()) };
  }
  const timers = new Set<ReturnType<typeof setTimeout>>();
  return {
    sound: (sound, delayMs = 0) => {
      if (sounds === null) return;
      if (delayMs <= 0) {
        guard(() => sounds.play(sound));
        return;
      }
      const timer = setTimeout(() => {
        timers.delete(timer);
        guard(() => sounds.play(sound));
      }, delayMs);
      timers.add(timer);
    },
    haptic: (haptic) => {
      if (haptics === null) return;
      if (haptic === 'success') guard(haptics.success);
      else if (haptic === 'cue') guard(haptics.selection);
      else guard(haptics.impactLight);
    },
    release: () => {
      timers.forEach(clearTimeout);
      timers.clear();
      guard(() => sounds?.release());
    },
  };
}
