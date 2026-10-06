/**
 * M5-R03 for the parent: `training` read with safe defaults (legacy / older server →
 * disabled), live patch from `pet.updated`, the training routine in reports and missed
 * lists ("Šola"), timeline labels of the new activity types.
 */
import type { ParentDashboardResponse } from '@/api/client';
import { familyFromDashboard } from '@/modules/family/family';
import { patchDashboardPet } from '@/modules/family/live';
import { activityText, missedLabel, missedWhenText, readChildReport, readToday, ROUTINE_LABELS } from '@/modules/family/scoring';
import { EMPTY_TRAINING_SUMMARY } from '@/modules/training/training';
import {
  makeBroadcast,
  makeFamilyChild,
  makeFamilyPet,
  makeMissed,
  makeScoredDashboard,
  makeTrainingCommands,
} from '@/test-utils/fixtures';

function dashboard(petPatch: Record<string, unknown>) {
  const pet = { ...makeFamilyPet(), ...petPatch } as unknown as ReturnType<typeof makeFamilyPet>;
  return makeScoredDashboard([makeFamilyChild({ pet_id: 7 })], [pet]) as unknown as ParentDashboardResponse;
}

const ENABLED = { enabled: true, commands: makeTrainingCommands({ sit: 100, come: 60 }), today_done: true, session_active: false };

describe('family training (M5-R03)', () => {
  it('older server (no key) and legacy pet (disabled) → nothing', () => {
    expect(familyFromDashboard(dashboard({ training: undefined }))?.pets[0].training).toBe(EMPTY_TRAINING_SUMMARY);
    expect(familyFromDashboard(dashboard({}))?.pets[0].training).toBe(EMPTY_TRAINING_SUMMARY);
  });

  it('reads an enabled summary', () => {
    const t = familyFromDashboard(dashboard({ training: ENABLED }))?.pets[0].training;
    expect(t?.enabled).toBe(true);
    expect(t?.commands.map((c) => [c.command, c.progress, c.learned])).toEqual([
      ['sit', 100, true],
      ['come', 60, false],
      ['place', 0, false],
      ['potty', 0, false],
    ]);
  });

  it('live patch takes the broadcast summary; an older broadcast keeps the cached one', () => {
    const data = dashboard({ training: ENABLED });
    const running = { ...ENABLED, today_done: false, session_active: true };
    const patched = patchDashboardPet(data, makeBroadcast({ pet_id: 7, training: running }));
    expect(familyFromDashboard(patched)?.pets[0].training.session_active).toBe(true);
    const kept = patchDashboardPet(data, makeBroadcast({ pet_id: 7 }));
    expect(familyFromDashboard(kept)?.pets[0].training.today_done).toBe(true);
  });

  it('the training routine in today / missed / report', () => {
    const missed = makeMissed('training', '2026-10-04T00:00:00+02:00', '2026-10-05T00:00:00+02:00');
    const today = readToday({ date: '2026-10-04', expected: 5, done: 3, pending: 1, missed_count: 1, missed: [missed] });
    expect(today.missed[0].type).toBe('training');
    expect(missedLabel(today.missed[0])).toBe('Šola');
    expect(missedWhenText(today.missed[0], 'Europe/Ljubljana')).toBe('do konca dneva');
    expect(ROUTINE_LABELS.training).toBe('Šola');

    const report = readChildReport({
      child: { id: 2, name: 'Luka' },
      by_type: { training: { expected: 7, done: 5, done_by_child: 4, missed: 2, pending: 0 } },
    });
    expect(report?.by_type.training).toEqual({ expected: 7, done: 5, done_by_child: 4, missed: 2, pending: 0 });
    // Older server: zeros.
    expect(readChildReport({ child: { id: 2, name: 'Luka' } })?.by_type.training.expected).toBe(0);
  });

  it('timeline labels', () => {
    expect(activityText({ activity_type: 'trained_pet', actor_nickname: 'Maja' })).toBe('Maja opravil(a) vajo v šoli');
    expect(activityText({ activity_type: 'training_started', actor_nickname: 'Maja' })).toBe('Maja začel(a) vajo v šoli');
    expect(activityText({ activity_type: 'trained_pet', actor_nickname: null })).toBe('Opravil(a) vajo v šoli');
  });
});
