/**
 * Ball v2 — the clips of the stage (GAMES_SPEC §4.3, §8.1), pure.
 *
 * Chain (hub = standing, the reference image):
 *   `waiting` = `ball_waiting_loop` (server-derived ping-pong loop, 0 $) — else `ball_waiting`
 *     looping — stand → stand, may be left any time (crossfade ≤ 200 ms);
 *   `running` = `ball_running` once — stand → empty room (the dog leaves to `exit_side`);
 *     it HOLDS its last frame (the empty room) while the child may call;
 *   `fetching` = `ball_fetching` once — empty room → stand (follows the run on the same frame,
 *     no cut); its end goes straight back into the waiting loop (same pose, instant).
 * Lengths = the server's `phase_seconds` (`phaseClipMs`, default 3 s).
 *
 * Without the `ball` phase group (an older media set) the pet's `playing` (else `idle`) state
 * loop stands in for every role — the dog stays in frame, the ball still flies, the run / return
 * take the default 3 s. Picture mode (M5-T11 / the 12 s deck timeout): no clip at all — the
 * reference image, the empty room (pose `empty`) while the dog is out.
 */

import { CLIP_END_GRACE_MS, type ClipSpec } from '@/modules/gameLayer/clipQueue';
import type { DeckClip, DeckPlan } from '@/modules/gameLayer/deck';
import { selectMediaSource, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import { phaseClipMs, usesPhaseClips } from '@/modules/petMedia/phasePlayer';
import type { MediaMode } from '@/modules/petMedia/statePlayer';
import type { BallRole } from '@/modules/play/ballV2/ballMachine';

const POSE: Readonly<Record<BallRole, { start: string; end: string }>> = {
  waiting: { start: 'stand', end: 'stand' },
  running: { start: 'stand', end: 'empty' },
  fetching: { start: 'empty', end: 'stand' },
};

function present(url: string | null | undefined): url is string {
  return typeof url === 'string' && url.length > 0;
}

/** The key of the waiting loop actually played (the derived loop, else the source clip). */
export function ballWaitingKey(media: Pick<PetMediaInfo, 'phases'>): 'ball_waiting_loop' | 'ball_waiting' | null {
  if (present(media.phases.ball_waiting_loop)) return 'ball_waiting_loop';
  if (present(media.phases.ball_waiting)) return 'ball_waiting';
  return null;
}

/** The stage plan of the ball game for this pet and display mode. */
export function ballDeck(media: PetMediaInfo, mode: MediaMode): DeckPlan {
  const stand = present(media.referenceImageUrl) ? media.referenceImageUrl : null;
  const empty = present(media.poses.empty) ? media.poses.empty : stand;
  const video = mode === 'video';
  const phased = usesPhaseClips(media, 'ball');

  const urlOf = (role: BallRole): { url: string | null; loop: boolean } => {
    if (!video) return { url: null, loop: false };
    if (!phased) {
      // Older set: the state loop stands in for every role (one shared player).
      const source = selectMediaSource(media, 'playing');
      const hub = source.kind === 'video' ? source.url : null;
      return { url: hub, loop: true };
    }
    if (role === 'waiting') {
      const key = ballWaitingKey(media);
      return { url: key === null ? null : media.phases[key], loop: true };
    }
    const url = media.phases[`ball_${role}`];
    return { url: present(url) ? url : null, loop: false };
  };

  const clips: DeckClip[] = (['waiting', 'running', 'fetching'] as const).map((role) => {
    const { url, loop } = urlOf(role);
    return {
      role,
      url,
      loop,
      // Start frames: waiting / running start standing, fetching starts in the empty room.
      startStill: role === 'fetching' ? empty : stand,
      // Without a clip: standing, or the empty room while the dog is out after the ball.
      picture: role === 'running' ? (phased ? empty : stand) : stand,
    };
  });

  const specs: ClipSpec[] = clips.map((clip) => {
    const role = clip.role as BallRole;
    const ownOncePlayer = clip.url !== null && !clip.loop;
    return {
      role,
      // Chain logic: only the hub may be left at any moment; run / return always play their length.
      loop: role === 'waiting',
      startPose: phased ? POSE[role].start : 'stand',
      endPose: phased ? POSE[role].end : 'stand',
      durationMs: role === 'waiting' ? 0 : phaseClipMs(media, `ball_${role}`),
      endGraceMs: ownOncePlayer ? CLIP_END_GRACE_MS : 0,
    };
  });

  return { clips, specs, initial: 'waiting', mode };
}
