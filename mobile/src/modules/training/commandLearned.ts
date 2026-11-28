/**
 * »Kuža zna ukaz!« — the milestone of a command learned for the FIRST time (David 2026-11-28:
 * the small green line under the progress »bi lahko bolj izpostavljeno«). Pure rules for the
 * result screens (`SchoolResultV2`, the v1 `ResultView`) and the badges (profile sheet).
 *
 * - The milestone shows ONLY when the finish answer carries `command_learned` (server, once per
 *   command) — never derived from `progress_after` (a repeat at 100 % is not a new milestone).
 * - Its picture = the command's own success / performing pose from the school phase media: a clip
 *   in video mode, the pose image in picture mode / without a clip, else none (the badge alone —
 *   never a blank, never a state clip).
 */

import { t } from '@/i18n';
import { mediaKey, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import type { PhasePlayback } from '@/modules/petMedia/phasePlayer';
import type { MediaMode } from '@/modules/petMedia/statePlayer';
import { isTrainingCommand, TRAINING_STRINGS, TRAINING_COMMANDS, type CommandLearned, type CommandProgress, type TrainingCommand } from '@/modules/training/training';

/** The end pose of each command (same key as the school's `COMMAND_POSE`). */
const COMMAND_POSE: Record<TrainingCommand, string> = { sit: 'sit', come: 'come', place: 'place', potty: 'door' };

/** The milestone of THIS result, or null (the result screen stays as it was). */
export function milestoneFor(learned: CommandLearned | null | undefined, resultCommand: TrainingCommand): CommandLearned | null {
  return learned != null && learned.command === resultCommand ? learned : null;
}

/** »Pika zna ukaz »Prostor«!« — no name → »Kuža zna ukaz »Prostor«!«. */
export function milestoneHeadline(command: TrainingCommand, petName: string | null): string {
  const name = TRAINING_STRINGS.commands[command].name;
  const pet = petName?.trim() ?? '';
  return pet === '' ? t('training:child.milestone.titleNoName', { name }) : t('training:child.milestone.title', { pet, name });
}

function present(url: string | null | undefined): url is string {
  return typeof url === 'string' && url.length > 0;
}

/**
 * The command's pose for the milestone: video mode + a stored clip (success → success loop →
 * performing; each ends in the pose and holds it) → that clip once; else the pose image; else null
 * (badge only). Only for a pet whose set has the school phase group.
 */
export function milestoneMedia(media: PetMediaInfo, command: TrainingCommand, mode: MediaMode): PhasePlayback | null {
  if (!media.phaseGroups.includes('school')) return null;
  const pose = media.poses[COMMAND_POSE[command]];
  const still = present(pose) ? pose : null;
  if (mode === 'video') {
    for (const suffix of ['success', 'success_loop', 'performing']) {
      const url = media.phases[`school_${command}_${suffix}`];
      if (present(url)) {
        return { current: { kind: 'clip', url, key: `${mediaKey(url)}#milestone`, state: null, loop: false, transition: null }, still };
      }
    }
  }
  return still === null ? null : { current: { kind: 'image', url: still, key: mediaKey(still), state: null }, still };
}

/**
 * Commands for a badge line: learned ones first (in the order they were learned; `learned_at`
 * missing → the fixed order), then the rest in the fixed order.
 */
export function orderedCommands(commands: readonly CommandProgress[]): CommandProgress[] {
  const fixed = (c: CommandProgress) => TRAINING_COMMANDS.indexOf(c.command);
  const learned = commands
    .filter((c) => c.learned)
    .sort((a, b) => {
      const at = a.learned_at === null ? Number.POSITIVE_INFINITY : Date.parse(a.learned_at);
      const bt = b.learned_at === null ? Number.POSITIVE_INFINITY : Date.parse(b.learned_at);
      return at !== bt ? at - bt : fixed(a) - fixed(b);
    });
  const open = commands.filter((c) => !c.learned).sort((a, b) => fixed(a) - fixed(b));
  return [...learned, ...open];
}

/** »Sedi ✓ · Prostor ✓ · Pridi 60 % · Lulat zunaj 10 %« (child profile sheet); no commands → null. */
export function commandsLine(commands: readonly CommandProgress[]): string | null {
  if (commands.length === 0) return null;
  return orderedCommands(commands)
    .map((c) => {
      const name = TRAINING_STRINGS.commands[c.command].name;
      return c.learned ? t('training:child.commandLearned', { name }) : t('training:child.commandProgress', { name, value: c.progress });
    })
    .join(' · ');
}

// ── Parent timeline ───────────────────────────────────────────

/** Timeline `activity_type` of the milestone row (server, David 2026-11-28). */
export const COMMAND_LEARNED_ACTIVITY = 'command_learned';

/**
 * The command of a timeline item: `command` or `meta.command` (defensive until the backend's shape
 * is in `schema.ts`; TODO(schema)). Anything else → null (the plain text).
 */
export function timelineCommandOf(item: Record<string, unknown>): TrainingCommand | null {
  if (isTrainingCommand(item.command)) return item.command;
  const meta = item.meta;
  if (typeof meta === 'object' && meta !== null && !Array.isArray(meta)) {
    const command = (meta as Record<string, unknown>).command;
    if (isTrainingCommand(command)) return command;
  }
  return null;
}

/** »Kuža zna ukaz »Prostor« · Mia« (parent timeline); another activity → null. */
export function commandLearnedTimelineText(activityType: string, command: TrainingCommand | null | undefined, nickname: string | null): string | null {
  if (activityType !== COMMAND_LEARNED_ACTIVITY) return null;
  const base =
    command != null
      ? t('training:parent.learnedTimeline', { name: TRAINING_STRINGS.commands[command].name })
      : t('training:parent.learnedTimelinePlain');
  return nickname ? t('training:parent.learnedTimelineBy', { text: base, child: nickname }) : base;
}
