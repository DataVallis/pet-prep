/**
 * M5-R05 `PlayOverlay`: the choice, the ball game through its accessible button (3 throws,
 * one at a time while the dog fetches), cuddles by holding 3 s or the screen reader's
 * activate action, one report per finished game, the "thank you" card closing by itself,
 * and closing mid-game reporting nothing.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';

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
    expect(screen.getByText('Kaj bi rad počel?')).toBeTruthy();
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
});
