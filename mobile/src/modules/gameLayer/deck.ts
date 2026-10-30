/**
 * ClipDeck plan types (GAMES_SPEC §3.2, §7.1), pure — what each clip ROLE of a game shows.
 * A game builds its plan (`ballDeck`, later wand / school) and hands it to `ClipDeck`
 * (players) and `useClipQueue` (chain rules, via `specs`).
 */

import type { ClipSpec } from '@/modules/gameLayer/clipQueue';
import type { MediaMode } from '@/modules/petMedia/statePlayer';

export interface DeckClip {
  role: string;
  /** The clip (signed URL), or null = this role has no clip (picture / missing) → `picture`. */
  url: string | null;
  /** The PLAYER loops (a hub loop, or a state loop standing in for a missing phase clip). */
  loop: boolean;
  /** The clip's START frame — shown under the player until its first frame renders (§3.2 rule 7). */
  startStill: string | null;
  /** What the role shows without a clip (picture mode, missing / failed clip). */
  picture: string | null;
}

export interface DeckPlan {
  clips: DeckClip[];
  /** Chain rules for `useClipQueue` (same roles). */
  specs: ClipSpec[];
  /** The role the game opens on (its hub loop). */
  initial: string;
  mode: MediaMode;
}

/** Players the deck mounts: one per distinct URL (roles sharing a stand-in loop share it). */
export function deckPlayerUrls(clips: readonly DeckClip[]): string[] {
  const urls: string[] = [];
  for (const clip of clips) if (clip.url !== null && !urls.includes(clip.url)) urls.push(clip.url);
  return urls;
}

/** At most this many players per game (memory on older phones; the school stage used 4). */
export const MAX_DECK_PLAYERS = 4;

/** The deck waits this long for every player's first frame before it plays in picture mode (§3.2 rule 3). */
export const DECK_READY_TIMEOUT_MS = 12_000;
/** A hidden player may never report its first frame: `readyToPlay` this long counts as ready. */
export const DECK_READY_FALLBACK_MS = 600;
