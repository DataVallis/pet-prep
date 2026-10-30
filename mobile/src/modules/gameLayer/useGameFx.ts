/**
 * `useGameFx(enabled)` — the native side of GameFx (rules in `gameFxCore.ts`): one preloaded
 * `expo-audio` player per sound (our own synthesized files, `assets/sounds/`), `expo-haptics`
 * for the positive haptics. Reached only through the game layer's lazy load (an older binary
 * without these modules never imports this file — `capability.ts`).
 *
 * Audio session: `playsInSilentMode: false` (iOS silent switch / Android silent + vibrate mute
 * the sounds), `mixWithOthers` (never stops the family's music), no recording, no background.
 */

import { useEffect, useMemo, useRef } from 'react';
import { createAudioPlayer, setAudioModeAsync, type AudioPlayer } from 'expo-audio';
import * as Haptics from 'expo-haptics';

import { createGameFx, GAME_SOUNDS, SILENT_GAME_FX, type GameFx, type GameSound, type HapticAdapter, type SoundAdapter } from '@/modules/gameLayer/gameFxCore';

/** Effects play softly, under the dog. */
export const GAME_SOUND_VOLUME = 0.7;

const SOUND_FILES: Readonly<Record<GameSound, number>> = {
  whoosh: require('../../../assets/sounds/whoosh.wav') as number,
  bounce: require('../../../assets/sounds/bounce.wav') as number,
  whistle: require('../../../assets/sounds/whistle.wav') as number,
  chime: require('../../../assets/sounds/chime.wav') as number,
  pop: require('../../../assets/sounds/pop.wav') as number,
};

let audioModeSet = false;

function ensureAudioMode(): void {
  if (audioModeSet) return;
  audioModeSet = true;
  setAudioModeAsync({
    playsInSilentMode: false,
    interruptionMode: 'mixWithOthers',
    allowsRecording: false,
    shouldPlayInBackground: false,
  }).catch(() => {
    audioModeSet = false;
  });
}

function nativeSounds(): SoundAdapter {
  ensureAudioMode();
  const players: Partial<Record<GameSound, AudioPlayer>> = {};
  for (const sound of GAME_SOUNDS) {
    try {
      const player = createAudioPlayer(SOUND_FILES[sound]);
      player.volume = GAME_SOUND_VOLUME;
      players[sound] = player;
    } catch {
      // A sound that can't load is simply silent.
    }
  }
  return {
    play: (sound) => {
      const player = players[sound];
      if (player === undefined) return;
      // Restart from the beginning even when the same sound is still playing.
      player
        .seekTo(0)
        .then(() => player.play())
        .catch(() => undefined);
    },
    release: () => {
      for (const sound of GAME_SOUNDS) {
        try {
          players[sound]?.remove();
        } catch {
          // already released
        }
        delete players[sound];
      }
    },
  };
}

const NATIVE_HAPTICS: HapticAdapter = {
  impactLight: () => Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light),
  success: () => Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success),
  selection: () => Haptics.selectionAsync(),
};

/**
 * Sounds + haptics for one game screen: the players are created in an effect (preloaded on
 * mount, released on unmount / when the switch changes); the returned object is stable.
 */
export function useGameFx(enabled: boolean): GameFx {
  const current = useRef<GameFx>(SILENT_GAME_FX);
  useEffect(() => {
    const fx = createGameFx({ enabled, sounds: enabled ? nativeSounds() : null, haptics: enabled ? NATIVE_HAPTICS : null });
    current.current = fx;
    return () => {
      current.current = SILENT_GAME_FX;
      fx.release();
    };
  }, [enabled]);
  return useMemo<GameFx>(
    () => ({
      sound: (sound, delayMs) => current.current.sound(sound, delayMs),
      haptic: (haptic) => current.current.haptic(haptic),
      release: () => current.current.release(),
    }),
    [],
  );
}
