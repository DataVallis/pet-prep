/**
 * GAMES_SPEC §4.4 (C1) — cuddles v2 through `PlayOverlay`: the capability fallback (v1 five
 * strokes on an older binary), three hearts with the pause in between via the »Pobožaj« hold
 * button, the 🐢 for a fast stroke, one report per finished cuddle, dog and cat alike.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';

import { i18n } from '@/i18n';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { setGameLayerAvailableForTests } from '@/modules/gameLayer/capability';
import { GAME_LINE_MAX_CHARS } from '@/modules/gameLayer/gameLine';
import PlayOverlay, { loadCuddleGameV2, resetBallGameV2ForTests } from '@/modules/play/PlayOverlay';
import { CUDDLE_V2_STRINGS } from '@/modules/play/play';
import { makeLiveChildState, makePlayState, withEasyGestures } from '@/test-utils/fixtures';
import { captureAndroidBack, expectHudAboveStage, fireGamePan } from '@/test-utils/gameLayer';

const ID = 'play-cuddle-v2';
const T0 = Date.parse('2026-10-31T12:00:00+01:00');

function view(o: { cat?: boolean; easy?: boolean } = {}): ChildPetView {
  const raw = makeLiveChildState({ play: makePlayState(), ...(o.cat ? { pet: { breed_type: 'maine_coon', species: 'cat' } } : {}) } as Parameters<typeof makeLiveChildState>[0]);
  return normalizeChildState(o.easy ? withEasyGestures(raw) : raw, 0, T0);
}

function renderCuddle(v: ChildPetView) {
  const onFinished = jest.fn();
  const onClose = jest.fn();
  render(<PlayOverlay view={v} mode="cuddle" reduceMotion={false} onPick={jest.fn()} onFinished={onFinished} onClose={onClose} />);
  return { onFinished, onClose };
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

beforeEach(async () => {
  jest.useFakeTimers({ now: T0 });
  await i18n.changeLanguage('sl');
  setGameLayerAvailableForTests(true);
  resetBallGameV2ForTests();
});

afterEach(() => {
  setGameLayerAvailableForTests(false);
  resetBallGameV2ForTests();
  jest.useRealTimers();
});

describe('cuddles v2', () => {
  it('an older binary keeps the v1 five strokes', () => {
    setGameLayerAvailableForTests(false);
    resetBallGameV2ForTests();
    expect(loadCuddleGameV2()).toBeNull();
    renderCuddle(view());
    expect(screen.getByTestId('play-cuddle')).toBeTruthy();
    expect(screen.queryByTestId(ID)).toBeNull();
  });

  it.each([false, true])('three held strokes with pauses → done, one report (cat: %s)', async (cat) => {
    const { onFinished } = renderCuddle(view({ cat, easy: true }));
    expect(screen.getByTestId(`${ID}-top-line`).props.children).toBe('Boži počasi, 3 sekunde.');
    const hold = screen.getByTestId(`${ID}-hold`);
    for (let heart = 1; heart <= 3; heart++) {
      fireEvent(hold, 'pressIn');
      await advance(3_100);
      if (heart < 3) {
        expect(screen.getByTestId(`${ID}-wait`, { includeHiddenElements: true })).toBeTruthy();
        fireEvent(hold, 'pressOut');
        fireEvent.press(hold);
        await advance(1_000);
        expect(screen.getByTestId(`${ID}-more`, { includeHiddenElements: true })).toBeTruthy();
        // The ✋ line keeps its 2 s (GameLine), then »Še!«.
        await advance(1_500);
        expect(screen.getByTestId(`${ID}-top-line`).props.children).toBe('Še!');
      }
    }
    expect(screen.getByTestId(`${ID}-done`)).toBeTruthy();
    expect(screen.getAllByTestId(`${ID}-done-hearts-full`)).toHaveLength(3);
    expect(onFinished).toHaveBeenCalledTimes(1);
    expect(onFinished).toHaveBeenCalledWith('cuddle');
  });

  it('a screen reader activate fills one heart and pauses', async () => {
    renderCuddle(view({ easy: true }));
    fireEvent(screen.getByTestId(`${ID}-hold`), 'accessibilityAction', { nativeEvent: { actionName: 'activate' } });
    await advance(0);
    expect(screen.getAllByTestId(`${ID}-hearts-full`)).toHaveLength(1);
  });

  it('a fast stroke fills nothing and shows the 🐢', async () => {
    renderCuddle(view());
    await act(async () => {
      fireGamePan(`${ID}-field`, [{ x: 100, y: 300 }, { x: 300, y: 300, velocityX: 2500 }]);
      await jest.advanceTimersByTimeAsync(0);
    });
    expect(screen.getByTestId(`${ID}-slow`, { includeHiddenElements: true })).toBeTruthy();
    expect(screen.queryAllByTestId(`${ID}-hearts-full`)).toHaveLength(0);
    await advance(2_100);
    expect(screen.queryByTestId(`${ID}-slow`, { includeHiddenElements: true })).toBeNull();
  });

  it.each(['sl', 'en'])('every game-line text fits the line (%s)', async (lang) => {
    await i18n.changeLanguage(lang);
    for (const text of [CUDDLE_V2_STRINGS.slow, CUDDLE_V2_STRINGS.wait, CUDDLE_V2_STRINGS.more, CUDDLE_V2_STRINGS.doneLine]) {
      expect(Array.from(text).length).toBeLessThanOrEqual(GAME_LINE_MAX_CHARS);
    }
  });

  // Ball v2 device bug (David 2026-10-31): the HUD must sit above the video, with a way out.
  it('the top bar, ×, field and dock are painted above the video; × and Android back close', () => {
    const back = captureAndroidBack();
    const { onClose } = renderCuddle(view({ easy: true }));
    expectHudAboveStage(ID, [`${ID}-top-close`, `${ID}-field-layer`, `${ID}-feedback-layer`]);
    fireEvent.press(screen.getByTestId(`${ID}-top-close`));
    expect(onClose).toHaveBeenCalledTimes(1);
    expect(back.pressBack()).toBe(true);
    expect(onClose).toHaveBeenCalledTimes(2);
    jest.restoreAllMocks();
  });
});
