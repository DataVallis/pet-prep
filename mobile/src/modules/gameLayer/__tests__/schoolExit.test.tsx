/**
 * School v2 on the game layer has the same mandatory exit and z-order as every game (ball v2
 * device bug, David 2026-10-31). Kept out of `training/schoolV2/` (its own tests) on purpose: the
 * × comes from `TrainingOverlay`'s `GameExitProvider` through the shared `GameTopBar`, the game
 * itself passes no `onClose`. Closing a running session leaves it on the server; the child state
 * resumes it when the school is reopened (`useTrainingGame`).
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { api } from '@/api/client';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { setGameLayerAvailableForTests } from '@/modules/gameLayer/capability';
import { normalizePetMedia } from '@/modules/petMedia/petMedia';
import TrainingOverlay, { resetSchoolGameV2ForTests } from '@/modules/training/TrainingOverlay';
import { makeEnabledTraining, makeLiveChildState, makeMedia, makeSchoolV2SessionPayload } from '@/test-utils/fixtures';
import { captureAndroidBack, expectHudAboveStage } from '@/test-utils/gameLayer';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import { resetMockVideoPlayers } from '@/test-utils/videoPlayers';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, startTraining: jest.fn(), finishTraining: jest.fn(), getChildPet: jest.fn() } };
});

const startTraining = api.startTraining as jest.Mock;
const ID = 'school-v2';
const URL = (k: string) => `https://api.petprep.si/m/${k}.mp4?v=1`;

function schoolView(): ChildPetView {
  const base = normalizeChildState(makeLiveChildState({ training: makeEnabledTraining({ treat_token: true }) }), 0, Date.parse('2026-10-04T12:00:00+02:00'));
  const media = normalizePetMedia(
    makeMedia({
      status: 'ready',
      reference_image_url: 'https://api.petprep.si/m/ref.png?v=1',
      poses: { sit: 'https://api.petprep.si/m/sit.png?v=1' },
      phases: {
        school_sit_attention_loop: URL('school_sit_attention_loop'),
        school_sit_attention: URL('school_sit_attention'),
        school_sit_performing: URL('school_sit_performing'),
        school_sit_success_loop: URL('school_sit_success_loop'),
        school_sit_success: URL('school_sit_success'),
        school_sit_release: URL('school_sit_release'),
      },
      phase_groups: ['school'],
      phase_seconds: { school_sit_performing: 3, school_sit_release: 2.5 },
    }),
  );
  return { ...base, pet: { ...base.pet, media } };
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

async function openRunningSchool() {
  startTraining.mockResolvedValueOnce({
    status: 'accepted',
    session: makeSchoolV2SessionPayload(),
    state: makeLiveChildState({ training: makeEnabledTraining({ can_start: false }) }),
  });
  const onClose = jest.fn();
  renderWithQuery(<TrainingOverlay view={schoolView()} onClose={onClose} reduceMotion={false} />);
  fireEvent.press(screen.getByTestId('training-start-sit'));
  await advance(0);
  return { onClose };
}

describe('school v2 — exit and z-order', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    startTraining.mockReset();
    resetMockVideoPlayers();
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    jest.setSystemTime(new Date('2026-10-04T12:00:00+02:00'));
    setGameLayerAvailableForTests(true);
    resetSchoolGameV2ForTests();
  });
  afterEach(() => {
    setGameLayerAvailableForTests(false);
    resetSchoolGameV2ForTests();
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it('shows the × while the deck prepares and while running; it closes the school', async () => {
    const { onClose } = await openRunningSchool();
    expect(screen.getByTestId(`${ID}-top-close`)).toBeTruthy();
    for (let i = 0; i < 3; i += 1) fireEvent(screen.getByTestId(`${ID}-deck-player-${i}`), 'firstFrameRender');
    await advance(6_000);
    expectHudAboveStage(ID, [`${ID}-top-close`, `${ID}-feedback-layer`, `${ID}-dock-layer`]);
    fireEvent.press(screen.getByTestId(`${ID}-top-close`));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('Android back closes the running school', async () => {
    const back = captureAndroidBack();
    const { onClose } = await openRunningSchool();
    expect(back.pressBack()).toBe(true);
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
