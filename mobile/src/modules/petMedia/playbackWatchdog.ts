/**
 * Stalled-loop watchdog of the pet's state / item loops (2026-11-27, David's iPhone: after a play
 * session the home toy loop stood still on one frame, no error) — pure, no player.
 *
 * Why a player can stop without an error: every game sound (expo-audio) that finished used to
 * deactivate the shared iOS `AVAudioSession` (`keepAudioSessionActive` false), and iOS stops every
 * running audio object of the app with it — also the muted expo-video players. expo-video then
 * reports `playing = false`, but `PetMediaView` had already called `play()` and never calls it
 * again, so the layer keeps its last frame forever (`useGameFx` now keeps the session active;
 * this watchdog catches any other cause: interruptions, a lost resume, a decoder that stops).
 *
 * Rule: while a LOOP layer should play (`wanted`) and its player is `readyToPlay`, its
 * `currentTime` is sampled every `STALL_CHECK_MS`. Not advanced for `STALL_AFTER_MS` →
 * `resume` (the player says it is paused: `play()`) or `restart` (it says it plays but the time
 * stands: `replay()`). The clock restarts after each kick, so a dead player is kicked every
 * `STALL_AFTER_MS`, never in a tight loop. Once clips (transitions, moments) are never kicked:
 * they hold their last frame by design and have their own end timers.
 */

/** How often the player's time is sampled (ms). */
export const STALL_CHECK_MS = 1_000;
/** A loop whose time stood still this long (ms) is kicked. */
export const STALL_AFTER_MS = 3_000;
/** Smaller moves of `currentTime` (s) count as standing still. */
export const STALL_EPSILON_S = 0.01;

export interface WatchdogSample {
  /** The layer should be playing now (loop, app active, screen not covered, not preloading / errored). */
  wanted: boolean;
  /** expo-video `status`. */
  status: string;
  /** expo-video `playing`. */
  playing: boolean;
  /** expo-video `currentTime` (s). */
  currentTime: number;
}

export interface WatchdogState {
  /** Last sampled time (s); null = not watching. */
  lastTime: number | null;
  /** Since when (device ms) the time has stood at `lastTime`. */
  sinceMs: number;
}

export type WatchdogAction = 'none' | 'resume' | 'restart';

export const IDLE_WATCHDOG: WatchdogState = { lastTime: null, sinceMs: 0 };

/** One sample → the next state and what to do with the player. */
export function watchdogStep(state: WatchdogState, sample: WatchdogSample, nowMs: number): { state: WatchdogState; action: WatchdogAction } {
  if (!sample.wanted || sample.status !== 'readyToPlay' || !Number.isFinite(sample.currentTime)) {
    return { state: IDLE_WATCHDOG, action: 'none' };
  }
  if (state.lastTime === null || Math.abs(sample.currentTime - state.lastTime) > STALL_EPSILON_S) {
    return { state: { lastTime: sample.currentTime, sinceMs: nowMs }, action: 'none' };
  }
  if (nowMs - state.sinceMs < STALL_AFTER_MS) return { state, action: 'none' };
  return { state: { lastTime: sample.currentTime, sinceMs: nowMs }, action: sample.playing ? 'restart' : 'resume' };
}
