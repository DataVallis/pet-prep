/**
 * GAMES_SPEC §7.4: an older store binary that receives this JS by OTA has no gesture-handler /
 * skia / expo-audio / expo-haptics. The v2 module would crash on import there — so it must
 * never be evaluated without the capability flag, and the v1 ball game must stay.
 * The v2 module is replaced by one that THROWS when evaluated (like a missing native module).
 */
import { render, screen } from '@testing-library/react-native';

import { normalizeChildState } from '@/modules/childPet/childPetView';
import { setGameLayerAvailableForTests } from '@/modules/gameLayer/capability';
import PlayOverlay, { loadBallGameV2, resetBallGameV2ForTests } from '@/modules/play/PlayOverlay';
import { makeLiveChildState, makePlayState } from '@/test-utils/fixtures';

const mockEvaluated = jest.fn();
jest.mock('@/modules/play/ballV2/BallGameV2', () => {
  mockEvaluated();
  throw new Error("TurboModuleRegistry.getEnforcing(...): 'RNGestureHandlerModule' could not be found");
});

const view = normalizeChildState(makeLiveChildState({ play: makePlayState() }), 0, Date.parse('2026-10-04T12:00:00+02:00'));

function renderBall() {
  return render(<PlayOverlay view={view} mode="play" reduceMotion={false} onPick={jest.fn()} onFinished={jest.fn()} onClose={jest.fn()} />);
}

describe('PlayOverlay on an older binary (OTA JS, no native game layer)', () => {
  beforeEach(() => {
    resetBallGameV2ForTests();
    mockEvaluated.mockClear();
  });

  it('keeps the v1 ball game and never evaluates the v2 module', () => {
    setGameLayerAvailableForTests(false);
    renderBall();
    expect(screen.getByTestId('play-ball')).toBeTruthy();
    expect(screen.queryByTestId('play-ball-v2')).toBeNull();
    expect(loadBallGameV2()).toBeNull();
    expect(mockEvaluated).not.toHaveBeenCalled();
  });

  it('the pick and cuddle screens never load it either', () => {
    setGameLayerAvailableForTests(true);
    render(<PlayOverlay view={view} mode="pick" reduceMotion={false} onPick={jest.fn()} onFinished={jest.fn()} onClose={jest.fn()} />);
    expect(mockEvaluated).not.toHaveBeenCalled();
  });

  it('a v2 module that fails to load also keeps v1 — never a crash', () => {
    setGameLayerAvailableForTests(true);
    expect(() => renderBall()).not.toThrow();
    expect(mockEvaluated).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('play-ball')).toBeTruthy();
  });
});
