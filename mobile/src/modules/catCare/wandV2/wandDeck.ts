/**
 * Wand v2 — the clips of the stage (GAMES_SPEC §5.2, §8.1), pure.
 *
 * Chain (hub = the crouch):
 *   `stalking` = `wand_stalking` once — stand → crouch (0 … `stalk_ms`);
 *   `crouch`   = `wand_crouch_loop` (server-derived ping-pong of the last ~1 s of the stalk, 0 $),
 *                looping between pounces; missing → `wand_stalking` itself loops as the hub (it
 *                then opens the hunt too — one player, no separate stalk role; GAMES_SPEC §7.3
 *                »nadomestilo: zanka iz izvirnika«);
 *   `pounce`   = `wand_pouncing` once from each pounce — crouch → ≈ crouch (sent start-only);
 *                its end goes straight back into the crouch loop (same pose);
 *   `catching` = `wand_catching` once from `catch_at_ms` — crouch → catch, holds the catch pose.
 * Lengths = the server's `phase_seconds` (`phaseClipMs`, default 3 s).
 *
 * Without the `wand` phase group (an older media set) the cat's `playing` (else `idle`) state loop
 * stands in for every role. Picture mode (M5-T11 / the 12 s deck timeout): the poses — standing
 * reference, `crouch`, `catch`.
 */

import { CLIP_END_GRACE_MS, type ClipSpec } from '@/modules/gameLayer/clipQueue';
import type { DeckClip, DeckPlan } from '@/modules/gameLayer/deck';
import type { WandRole } from '@/modules/catCare/wandV2/wandRules';
import { selectMediaSource, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import { phaseClipMs, usesPhaseClips } from '@/modules/petMedia/phasePlayer';
import type { MediaMode } from '@/modules/petMedia/statePlayer';

const POSE: Readonly<Record<WandRole, { start: string; end: string }>> = {
  stalking: { start: 'stand', end: 'crouch' },
  crouch: { start: 'crouch', end: 'crouch' },
  pounce: { start: 'crouch', end: 'crouch' },
  catching: { start: 'crouch', end: 'catch' },
};

function present(url: string | null | undefined): url is string {
  return typeof url === 'string' && url.length > 0;
}

/** The hub loop actually played: the derived crouch loop, else the stalk clip looping, else none. */
export function wandHubKey(media: Pick<PetMediaInfo, 'phases'>): 'wand_crouch_loop' | 'wand_stalking' | null {
  if (present(media.phases.wand_crouch_loop)) return 'wand_crouch_loop';
  if (present(media.phases.wand_stalking)) return 'wand_stalking';
  return null;
}

/** Stored length of the pounce clip (the stage shows it this long from each pounce). */
export function wandPounceClipMs(media: Pick<PetMediaInfo, 'phaseMs'>): number {
  return phaseClipMs(media, 'wand_pouncing');
}

/** The stage plan of the hunt for this cat and display mode. */
export function wandDeck(media: PetMediaInfo, mode: MediaMode): DeckPlan {
  const stand = present(media.referenceImageUrl) ? media.referenceImageUrl : null;
  const crouch = present(media.poses.crouch) ? media.poses.crouch : stand;
  const caught = present(media.poses.catch) ? media.poses.catch : crouch;
  const video = mode === 'video';
  const phased = usesPhaseClips(media, 'wand');
  const hub = wandHubKey(media);
  // The stalk is its own role only when the hub is the derived loop (else the stalk IS the hub).
  const separateStalk = phased && hub === 'wand_crouch_loop';
  const roles: WandRole[] = separateStalk ? ['stalking', 'crouch', 'pounce', 'catching'] : ['crouch', 'pounce', 'catching'];

  const stateLoop = (() => {
    const source = selectMediaSource(media, 'playing');
    return source.kind === 'video' ? source.url : null;
  })();

  const urlOf = (role: WandRole): { url: string | null; loop: boolean } => {
    if (!video) return { url: null, loop: false };
    if (!phased) return { url: stateLoop, loop: true };
    if (role === 'crouch') return { url: hub === null ? null : media.phases[hub], loop: true };
    const key = role === 'stalking' ? 'wand_stalking' : role === 'pounce' ? 'wand_pouncing' : 'wand_catching';
    const url = media.phases[key];
    return { url: present(url) ? url : null, loop: false };
  };

  const clips: DeckClip[] = roles.map((role) => {
    const { url, loop } = urlOf(role);
    return {
      role,
      url,
      loop,
      startStill: !phased ? stand : role === 'stalking' || (role === 'crouch' && !separateStalk) ? stand : crouch,
      picture: !phased ? stand : role === 'catching' ? caught : role === 'stalking' ? stand : crouch,
    };
  });

  const specs: ClipSpec[] = clips.map((clip) => {
    const role = clip.role as WandRole;
    const ownOncePlayer = clip.url !== null && !clip.loop;
    const key = role === 'stalking' ? 'wand_stalking' : role === 'pounce' ? 'wand_pouncing' : 'wand_catching';
    return {
      role,
      loop: role === 'crouch',
      startPose: phased ? POSE[role].start : 'stand',
      endPose: phased ? POSE[role].end : 'stand',
      durationMs: role === 'crouch' ? 0 : phaseClipMs(media, key),
      endGraceMs: ownOncePlayer ? CLIP_END_GRACE_MS : 0,
    };
  });

  return { clips, specs, initial: separateStalk ? 'stalking' : 'crouch', mode };
}
