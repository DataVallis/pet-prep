/**
 * M5-R02 for the parent: behaviour and new stats read with safe defaults, missed cleans
 * named by their mess, timeline labels of the new activity types, live patch.
 */
import type { ParentDashboardResponse } from '@/api/client';
import { familyFromDashboard } from '@/modules/family/family';
import { patchDashboardPet } from '@/modules/family/live';
import { activityText, missedLabel, readTimeline, readToday } from '@/modules/family/scoring';
import {
  makeBehaviourEvent,
  makeBroadcast,
  makeFamilyChild,
  makeFamilyPet,
  makeMissed,
  makeScoredDashboard,
  makeTakeOut,
} from '@/test-utils/fixtures';

function dashboard(childStats: Record<string, unknown>, petPatch: Record<string, unknown>) {
  const child = { ...makeFamilyChild({ pet_id: 7 }), stats: childStats } as unknown as ReturnType<typeof makeFamilyChild>;
  const pet = { ...makeFamilyPet(), ...petPatch } as unknown as ReturnType<typeof makeFamilyPet>;
  return makeScoredDashboard([child], [pet]) as unknown as ParentDashboardResponse;
}

describe('family normalisation (M5-R02)', () => {
  it('older server: no behaviour, no new stats → empty / 0', () => {
    const family = familyFromDashboard(dashboard({ days: 7, fed: 3 }, { behaviour: undefined }));
    expect(family?.pets[0].behaviour).toEqual({ take_out: null, active_events: [], scene: null });
    expect(family?.children[0].stats).toMatchObject({ fed: 3, taken_out: 0, chewing_resolved: 0, steps: 0 });
  });

  it('reads behaviour and the new stats', () => {
    const family = familyFromDashboard(
      dashboard(
        { days: 7, taken_out: 4, chewing_resolved: 1 },
        { behaviour: { take_out: makeTakeOut(), active_events: [makeBehaviourEvent('accident')], scene: 'accident' } },
      ),
    );
    expect(family?.pets[0].behaviour.scene).toBe('accident');
    expect(family?.pets[0].behaviour.take_out?.hold_hours).toBe(2);
    expect(family?.children[0].stats.taken_out).toBe(4);
    expect(family?.children[0].stats.chewing_resolved).toBe(1);
  });
});

describe('missed routines with a kind', () => {
  it('reads kind for clean only', () => {
    const today = readToday({
      date: '2026-10-04',
      missed: [
        makeMissed('clean', '2026-10-04T10:00:00+02:00', '2026-10-04T12:00:00+02:00', '2026-10-04', 'chewing'),
        { ...makeMissed('feed', '2026-10-04T06:00:00+02:00', '2026-10-04T10:00:00+02:00'), kind: 'chewing' },
        { ...makeMissed('clean', '2026-10-04T10:00:00+02:00', '2026-10-04T12:00:00+02:00'), kind: undefined },
      ],
    });
    expect(today.missed.map((m) => m.kind)).toEqual(['chewing', null, null]);
  });

  it('labels: the mess, else the routine type', () => {
    expect(missedLabel({ type: 'clean', kind: 'accident' })).toBe('Luža');
    expect(missedLabel({ type: 'clean', kind: 'chewing' })).toBe('Pregrizen copat');
    expect(missedLabel({ type: 'clean', kind: 'poop' })).toBe('Kakec');
    expect(missedLabel({ type: 'clean', kind: null })).toBe('Čiščenje');
    expect(missedLabel({ type: 'feed', kind: null })).toBe('Hrana');
  });
});

describe('timeline (M5-R02 activity types)', () => {
  it('child actions with the nickname, system rows without', () => {
    expect(activityText({ activity_type: 'took_out_pet', actor_nickname: 'Maja' })).toBe('Maja peljal(a) kužka ven');
    expect(activityText({ activity_type: 'resolved_chewing', actor_nickname: 'Luka' })).toBe(
      'Luka pospravil(a) copat in dal(a) igračo',
    );
    expect(activityText({ activity_type: 'pet_accident', actor_nickname: null })).toBe('Mladiček je naredil lužo');
    expect(activityText({ activity_type: 'pet_chewed', actor_nickname: null })).toBe('Kuža je pregrizel copat');
  });

  it('accident / chewing rows are negative even without is_positive', () => {
    const rows = readTimeline([
      { id: 1, activity_type: 'pet_accident', created_at: null },
      { id: 2, activity_type: 'took_out_pet', created_at: null },
      { id: 3, activity_type: 'pet_chewed', created_at: null, is_positive: false },
    ]);
    expect(rows.map((r) => r.is_positive)).toEqual([false, true, false]);
  });
});

describe('live patch', () => {
  it('copies behaviour from the broadcast, keeps it without one', () => {
    const data = dashboard({}, { behaviour: { take_out: null, active_events: [], scene: null } });
    const behaviour = { take_out: null, active_events: [makeBehaviourEvent('chewing')], scene: 'chewing' as const };
    const patched = patchDashboardPet(data, makeBroadcast({ behaviour }));
    expect(familyFromDashboard(patched)?.pets[0].behaviour.scene).toBe('chewing');
    const kept = patchDashboardPet(patched, makeBroadcast());
    expect(familyFromDashboard(kept)?.pets[0].behaviour.scene).toBe('chewing');
  });
});
