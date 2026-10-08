/**
 * M5-R05 `PlayOverlay`: the choice, the ball game through its accessible button (3 throws,
 * one at a time while the dog fetches), cuddles by holding 3 s or the screen reader's
 * activate action, one report per finished game, the "thank you" card closing by itself,
 * and closing mid-game reporting nothing. QA PR #86: the PanResponder rules (upward
 * release / flick throws, strokes ≥ 60 pt) through the captured responder callbacks, the
 * hold fill, Android back and VoiceOver announcements.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';
import {
  AccessibilityInfo,
  BackHandler,
  PanResponder,
  Platform,
  type GestureResponderEvent,
  type PanResponderCallbacks,
  type PanResponderGestureState,
} from 'react-native';

import PlayOverlay from '@/modules/play/PlayOverlay';
import { normalizeChildState } from '@/modules/childPet/childPetView';
import { DONE_AUTO_CLOSE_MS, FETCH_MS, FETCH_MS_REDUCED, HOLD_MS } from '@/modules/play/play';
import type { PlayOverlayMode } from '@/store/appStore';
import { makeLiveChildState, makePlayState } from '@/test-utils/fixtures';

const view = normalizeChildState(makeLiveChildState({ play: makePlayState() }), 0, Date.parse('2026-10-04T12:00:00+02:00'));

function renderOverlay(mode: PlayOverlayMode, reduceMotion = false) {
  const props = { onPick: jest.fn(), onFinished: jest.fn(), onClose: jest.fn() };
  const utils = render(<PlayOverlay view={view} mode={mode} reduceMotion={reduceMotion} {...props} />);
  return { ...props, ...utils };
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

describe('PlayOverlay', () => {
  beforeEach(() => {
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
  });
  afterEach(() => {
    jest.useRealTimers();
  });

  it('pick: Žoga / Crkljanje', () => {
    const { onPick } = renderOverlay('pick');
    expect(screen.getByText('Kaj bosta počela?')).toBeTruthy();
    fireEvent.press(screen.getByTestId('play-pick-ball'));
    expect(onPick).toHaveBeenLastCalledWith('play');
    fireEvent.press(screen.getByTestId('play-pick-cuddle'));
    expect(onPick).toHaveBeenLastCalledWith('cuddle');
  });

  it('ball: three throws with "Vrzi žogo", one at a time; reported once; the card closes itself', async () => {
    const { onFinished, onClose } = renderOverlay('play');
    expect(screen.getByText('Povleci žogo navzgor, da jo vržeš.')).toBeTruthy();
    expect(screen.getByLabelText('Vrzi žogo kužku')).toBeTruthy();

    fireEvent.press(screen.getByTestId('play-throw'));
    expect(screen.getByText('Kuža teče po žogo …')).toBeTruthy();
    // While the dog is out, the button is off and a second throw doesn't count.
    expect(screen.getByTestId('play-throw').props.accessibilityState?.disabled).toBe(true);
    fireEvent.press(screen.getByTestId('play-throw'));
    await advance(FETCH_MS);
    expect(screen.getByText('Met 1 od 3')).toBeTruthy();

    fireEvent.press(screen.getByTestId('play-throw'));
    await advance(FETCH_MS);
    fireEvent.press(screen.getByTestId('play-throw'));
    expect(onFinished).not.toHaveBeenCalled();
    await advance(FETCH_MS);

    expect(onFinished).toHaveBeenCalledTimes(1);
    expect(onFinished).toHaveBeenCalledWith('play');
    expect(screen.getByText('Hvala za igro! Kuža je ves vesel.')).toBeTruthy();
    expect(onClose).not.toHaveBeenCalled();
    await advance(DONE_AUTO_CLOSE_MS);
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('reduce motion: the dog is back sooner (fade only)', async () => {
    renderOverlay('play', true);
    fireEvent.press(screen.getByTestId('play-throw'));
    await advance(FETCH_MS_REDUCED);
    expect(screen.getByText('Met 1 od 3')).toBeTruthy();
  });

  it('closing mid-game reports nothing', async () => {
    const { onFinished, onClose } = renderOverlay('play');
    fireEvent.press(screen.getByTestId('play-throw'));
    fireEvent.press(screen.getByTestId('play-close'));
    expect(onClose).toHaveBeenCalledTimes(1);
    await advance(FETCH_MS);
    expect(onFinished).not.toHaveBeenCalled();
  });

  it('cuddles: holding "Drži in pobožaj" 3 s finishes; letting go earlier does not', async () => {
    const { onFinished } = renderOverlay('cuddle');
    expect(screen.getByText('S prstom nežno pobožaj kužka.')).toBeTruthy();
    const hold = screen.getByTestId('play-hold');
    fireEvent(hold, 'pressIn');
    await advance(HOLD_MS - 500);
    fireEvent(hold, 'pressOut');
    await advance(1_000);
    expect(onFinished).not.toHaveBeenCalled();

    fireEvent(hold, 'pressIn');
    await advance(HOLD_MS);
    expect(onFinished).toHaveBeenCalledTimes(1);
    expect(onFinished).toHaveBeenCalledWith('cuddle');
    expect(screen.getByText('Kuža uživa. Hvala za crkljanje!')).toBeTruthy();
    fireEvent.press(screen.getByTestId('play-done-close'));
  });

  it('cuddles: a screen reader activates "Pobožaj" at once', () => {
    const { onFinished } = renderOverlay('cuddle');
    const hold = screen.getByTestId('play-hold');
    expect(hold.props.accessibilityActions).toEqual([{ name: 'activate', label: 'Pobožaj' }]);
    fireEvent(hold, 'accessibilityAction', { nativeEvent: { actionName: 'activate' } });
    fireEvent(hold, 'accessibilityAction', { nativeEvent: { actionName: 'activate' } });
    expect(onFinished).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('play-done')).toBeTruthy();
  });

  it('the stroke area is labelled for screen readers', () => {
    renderOverlay('cuddle');
    expect(screen.getByLabelText('Tvoj kuža. Pobožaj ga s prstom ali uporabi gumb spodaj.')).toBeTruthy();
    expect(screen.getByTestId('play-dog-illustration')).toBeTruthy();
  });

  describe('gestures (PanResponder callbacks)', () => {
    const realCreate = PanResponder.create.bind(PanResponder);
    let configs: PanResponderCallbacks[] = [];
    beforeEach(() => {
      configs = [];
      jest.spyOn(PanResponder, 'create').mockImplementation((config) => {
        configs.push(config);
        return realCreate(config);
      });
    });
    afterEach(() => {
      jest.restoreAllMocks();
    });

    const gesture = (g: Partial<PanResponderGestureState>): PanResponderGestureState => ({
      stateID: 1,
      moveX: 0,
      moveY: 0,
      x0: 0,
      y0: 0,
      dx: 0,
      dy: 0,
      vx: 0,
      vy: 0,
      numberActiveTouches: 1,
      _accountsForMovesUpTo: 0,
      ...g,
    });
    const event = { nativeEvent: { locationX: 80, locationY: 90 } } as unknown as GestureResponderEvent;
    const last = () => {
      const config = configs[configs.length - 1];
      if (!config) throw new Error('no responder');
      return config;
    };

    it('ball: a short drag springs back; an upward release ≥ 60 pt or a quick flick throws', async () => {
      renderOverlay('play');
      act(() => last().onPanResponderRelease?.(event, gesture({ dy: -20, vy: -0.1 })));
      expect(screen.getByText('Povleci žogo navzgor, da jo vržeš.')).toBeTruthy();
      act(() => last().onPanResponderRelease?.(event, gesture({ dy: 80, vy: 1 })));
      expect(screen.getByText('Povleci žogo navzgor, da jo vržeš.')).toBeTruthy();

      act(() => last().onPanResponderRelease?.(event, gesture({ dy: -80, vy: -0.2 })));
      expect(screen.getByText('Kuža teče po žogo …')).toBeTruthy();
      // While the dog is out the ball can't be grabbed.
      expect(last().onStartShouldSetPanResponder?.(event, gesture({}))).toBe(false);
      await advance(FETCH_MS);
      expect(screen.getByText('Met 1 od 3')).toBeTruthy();

      act(() => last().onPanResponderRelease?.(event, gesture({ dy: -20, vy: -0.8 })));
      expect(screen.getByText('Kuža teče po žogo …')).toBeTruthy();
    });

    it('cuddles: a stroke needs ≥ 60 pt of finger path (back and forth counts); 5 strokes finish', () => {
      const { onFinished } = renderOverlay('cuddle');
      const stroke = (moves: Array<[number, number]>) =>
        act(() => {
          const config = last();
          config.onPanResponderGrant?.(event, gesture({}));
          moves.forEach(([dx, dy]) => config.onPanResponderMove?.(event, gesture({ dx, dy })));
          const [dx, dy] = moves[moves.length - 1] ?? [0, 0];
          config.onPanResponderRelease?.(event, gesture({ dx, dy }));
        });

      stroke([[20, 0], [30, 0]]);
      expect(screen.getByText('S prstom nežno pobožaj kužka.')).toBeTruthy();
      // 35 pt right, back to 5: 65 pt of path, 5 pt of displacement.
      stroke([[35, 0], [5, 0]]);
      expect(screen.getByText('Poteg 1 od 5')).toBeTruthy();
      expect(screen.getAllByTestId('play-cuddle-heart')).toHaveLength(1);
      for (let i = 0; i < 4; i += 1) stroke([[0, 40], [0, 80]]);
      expect(onFinished).toHaveBeenCalledTimes(1);
      expect(onFinished).toHaveBeenCalledWith('cuddle');
    });
  });

  it('"Drži in pobožaj" fills while held; with reduce motion only a static tint', async () => {
    const { unmount } = renderOverlay('cuddle');
    const hold = screen.getByTestId('play-hold');
    expect(screen.queryByTestId('play-hold-fill')).toBeNull();
    fireEvent(hold, 'pressIn');
    expect(screen.getByTestId('play-hold-fill')).toBeTruthy();
    fireEvent(hold, 'pressOut');
    expect(screen.queryByTestId('play-hold-fill')).toBeNull();
    unmount();

    renderOverlay('cuddle', true);
    fireEvent(screen.getByTestId('play-hold'), 'pressIn');
    expect(screen.getByTestId('play-hold-fill-static')).toBeTruthy();
    expect(screen.queryByTestId('play-hold-fill')).toBeNull();
  });

  it('Android back closes the layer', () => {
    type BackPressHandler = Parameters<typeof BackHandler.addEventListener>[1];
    let handler: BackPressHandler | null = null;
    jest.spyOn(BackHandler, 'addEventListener').mockImplementation((_event, h) => {
      handler = h;
      return { remove: jest.fn() };
    });
    const { onClose } = renderOverlay('play');
    let consumed: boolean | null | undefined = false;
    act(() => {
      const h: BackPressHandler | null = handler;
      consumed = h ? h({} as Parameters<BackPressHandler>[0]) : false;
    });
    expect(consumed).toBe(true);
    expect(onClose).toHaveBeenCalledTimes(1);
    jest.restoreAllMocks();
  });

  it('VoiceOver hears the progress and the thank-you line (iOS), not the first hint', async () => {
    jest.replaceProperty(Platform, 'OS', 'ios');
    const announce = jest.spyOn(AccessibilityInfo, 'announceForAccessibility').mockImplementation(() => undefined);
    // The RN jest setup's mock keeps calls from earlier tests.
    announce.mockClear();
    renderOverlay('play');
    expect(announce).not.toHaveBeenCalled();
    fireEvent.press(screen.getByTestId('play-throw'));
    expect(announce).toHaveBeenLastCalledWith('Kuža teče po žogo …');
    await advance(FETCH_MS);
    expect(announce).toHaveBeenLastCalledWith('Met 1 od 3');
    for (let i = 0; i < 2; i += 1) {
      fireEvent.press(screen.getByTestId('play-throw'));
      await advance(FETCH_MS);
    }
    expect(announce).toHaveBeenLastCalledWith('Hvala za igro! Kuža je ves vesel.');
    jest.restoreAllMocks();
  });
});
