/**
 * School v2 — the clips of the stage (GAMES_SPEC §4.1, §3.2, §8.1), pure.
 *
 * Chain (hub = standing, the reference image):
 *   `attention` = `school_<cmd>_attention_loop` (server ping-pong, 0 $) — else `_attention` looping —
 *     stand → stand, may be left at any moment (crossfade ≤ 200 ms into `performing`, which starts standing);
 *   `performing` = `_performing` ONCE from frame 0 (perform_at → obey_at) — stand → pose; never cut;
 *   `success` = `_success_loop` (else `_success`, holding its last frame) — pose → pose; every frame ≈ the
 *     pose, so the chain may leave it at any moment — but back to `attention` is a pose change = a CUT,
 *     which ClipQueue does only under the FeedbackLayer mask (Š2).
 * `_release` (the reversed performing clip, »rewinding the tape«) is NEVER mounted in v2.
 *
 * Without school clips (an older pet) the state loops stand in: `idle` for attention, `playing` for
 * performing / success (one pose, so no cut). Picture mode / a missing clip: the reference image for
 * attention AND performing (the end pose must not show before the dog obeys — that would hint the
 * praise moment), the command's pose image for success.
 */

import { CLIP_END_GRACE_MS, type ClipSpec } from '@/modules/gameLayer/clipQueue';
import type { DeckClip, DeckPlan } from '@/modules/gameLayer/deck';
import { selectMediaSource, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import { phaseClipMs, schoolStageClips, schoolStageStill, usesPhaseClips } from '@/modules/petMedia/phasePlayer';
import type { MediaMode } from '@/modules/petMedia/statePlayer';
import type { SchoolRole } from '@/modules/training/schoolV2/schoolV2';
import type { TrainingCommand, TrainingSession } from '@/modules/training/training';

export const SCHOOL_DECK_ROLES: readonly SchoolRole[] = ['attention', 'performing', 'success'];

/** The performing clip's length as the SERVER timed it (obey_at − perform_at), else the stored length. */
export function performingMs(session: Pick<TrainingSession, 'trials'>, media: Pick<PetMediaInfo, 'phaseMs'>, command: TrainingCommand): number {
  for (const t of session.trials) {
    if (t.obeys && t.obey_at_ms !== null && typeof t.perform_at_ms === 'number' && t.obey_at_ms > t.perform_at_ms) return t.obey_at_ms - t.perform_at_ms;
  }
  return phaseClipMs(media, `school_${command}_performing`);
}

/** The stage plan of the school v2 for this pet, command and display mode. */
export function schoolDeck(media: PetMediaInfo, command: TrainingCommand, mode: MediaMode, session: Pick<TrainingSession, 'trials'>): DeckPlan {
  const video = mode === 'video';
  const phased = usesPhaseClips(media, 'school');
  const stand = schoolStageStill(media, command, 'attention', false);
  const pose = schoolStageStill(media, command, 'success', false) ?? stand;
  const own = phased ? schoolStageClips(media, command).filter((c) => c.role !== 'release') : [];

  const urlOf = (role: SchoolRole): { url: string | null; loop: boolean } => {
    if (!video) return { url: null, loop: false };
    if (!phased) {
      const source = selectMediaSource(media, role === 'attention' ? 'idle' : 'playing');
      return { url: source.kind === 'video' ? source.url : null, loop: true };
    }
    const clip = own.find((c) => c.role === role);
    return clip === undefined ? { url: null, loop: false } : { url: clip.url, loop: clip.loop };
  };

  const clips: DeckClip[] = SCHOOL_DECK_ROLES.map((role) => {
    const { url, loop } = urlOf(role);
    return {
      role,
      url,
      loop,
      // §3.2 rule 7: under a clip its START frame — standing for attention / performing, the pose for success.
      startStill: role === 'success' && phased ? pose : stand,
      picture: role === 'success' ? pose : stand,
    };
  });

  const specs: ClipSpec[] = clips.map((clip) => {
    const role = clip.role as SchoolRole;
    const posed = phased || !video;
    return {
      role,
      // Chain logic: attention and success may be left at any moment; performing always plays to its end.
      loop: role !== 'performing',
      startPose: role === 'success' && posed ? 'pose' : 'stand',
      endPose: role !== 'attention' && posed ? 'pose' : 'stand',
      durationMs: role === 'performing' ? performingMs(session, media, command) : 0,
      endGraceMs: role === 'performing' && clip.url !== null && !clip.loop ? CLIP_END_GRACE_MS : 0,
    };
  });

  return { clips, specs, initial: 'attention', mode };
}
