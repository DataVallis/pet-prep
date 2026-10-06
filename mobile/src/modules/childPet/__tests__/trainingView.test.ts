/**
 * M5-R03 in the child view: `training` normalised (older server → EMPTY_TRAINING),
 * realtime summary applied (today done, a session ended / started, lock), and a
 * running session's expiry is a refresh boundary (frees "Začni vajo").
 */
import { applyBroadcast, broadcastTraining, nextRefreshDelay, normalizeChildState } from '@/modules/childPet/childPetView';
import { EMPTY_TRAINING, readChildTraining } from '@/modules/training/training';
import { makeBroadcast, makeEnabledTraining, makeLiveChildState, makeRunningSession, makeTrainingCommands } from '@/test-utils/fixtures';

const RUNNING = makeRunningSession();

describe('training in the child view', () => {
  it('normalises: older server / legacy → EMPTY_TRAINING', () => {
    expect(normalizeChildState(makeLiveChildState({ training: null })).training).toBe(EMPTY_TRAINING);
    expect(normalizeChildState(makeLiveChildState()).training).toBe(EMPTY_TRAINING);
    expect(normalizeChildState(makeLiveChildState({ training: makeEnabledTraining() })).training.can_start).toBe(true);
  });

  it('broadcast summary: today done, session ended → cleared; started → can_start off; lock → off', () => {
    const current = readChildTraining(makeEnabledTraining({ can_start: false, session: RUNNING }));
    const ended = broadcastTraining(current, { enabled: true, commands: makeTrainingCommands({ sit: 50 }), today_done: true, session_active: false }, false);
    expect(ended.session).toBeNull();
    expect(ended.today_done).toBe(true);
    expect(ended.commands[0].progress).toBe(50);

    const idle = readChildTraining(makeEnabledTraining());
    expect(broadcastTraining(idle, { enabled: true, commands: [], today_done: false, session_active: true }, false).can_start).toBe(false);
    expect(broadcastTraining(idle, undefined, true).can_start).toBe(false);
    expect(broadcastTraining(idle, undefined, false)).toBe(idle);
    // A disabled pet never gains training from a broadcast.
    expect(broadcastTraining(EMPTY_TRAINING, { enabled: true, commands: [], today_done: true, session_active: false }, false)).toBe(EMPTY_TRAINING);
  });

  it('applyBroadcast carries training through; trained_pet refetches', () => {
    const view = normalizeChildState(makeLiveChildState({ training: makeEnabledTraining() }));
    const out = applyBroadcast(
      view,
      makeBroadcast({
        pet_id: view.pet.id,
        event_type: 'trained_pet',
        emitted_at: '2026-10-04T10:00:05.000Z',
        training: { enabled: true, commands: makeTrainingCommands({ sit: 41 }), today_done: true, session_active: false },
      }),
    );
    expect(out?.view.training.today_done).toBe(true);
    expect(out?.refetch).toBe(true);
  });

  it('a running session expiry is a refresh boundary', () => {
    const view = normalizeChildState(
      makeLiveChildState({
        training: makeEnabledTraining({ can_start: false, session: RUNNING }),
        feeding: { next_feed_window: null },
        water: { next_allowed_at: null },
      }),
      0,
      Date.parse('2026-10-04T12:00:00+02:00'),
    );
    expect(nextRefreshDelay(view, Date.parse('2026-10-04T12:00:00+02:00'))).toBe(90_000);
  });
});
