/**
 * M5-R14 / R15 / R19 / R20 — the breed care mini-games: teeth on the brush scratch card (hold
 * alternative, same strokes the server scores), nails = one tap per claw (screen-reader
 * buttons, hold button in hold mode, finishes up to 2 s early), the sleeping refusal in the intro.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { api, type ChildPetState } from '@/api/client';
import CareTaskOverlay from '@/modules/careTasks/CareTaskOverlay';
import { HOLD_START_MS } from '@/modules/catCare/brushing';
import { scoreStrokes } from '@/modules/catCare/catGames';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { useAppStore } from '@/store/appStore';
import { makeCareTask, makeCareTaskSession, makeLiveChildState, makePet, withEasyGestures } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, startCareTask: jest.fn(), finishCareTask: jest.fn(), getChildPet: jest.fn() },
  };
});

const mocked = api as unknown as Record<string, jest.Mock>;
const T0 = Date.parse('2026-10-04T12:00:00+02:00');
const clock = { mono: () => Date.now(), wall: () => Date.now() };
const NAILS = makeCareTaskSession({ task: 'nail_trim', duration_ms: 12000, min_strokes: 8, segments: 1, ends_at: '2026-10-04T12:00:12+02:00', expires_at: '2026-10-04T12:01:12+02:00' });

function state(tasks: Record<string, unknown>[]): ChildPetState {
  return makeLiveChildState({ pet: { breed_type: 'papillon' } as Partial<ChildPetState['pet']>, care_tasks: tasks });
}

function viewOf(s: ChildPetState, easyGestures = false): ChildPetView {
  return withEasyGestures(normalizeChildState(s, 0, T0), easyGestures);
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

function sentStrokes(): number[] {
  return (mocked.finishCareTask.mock.calls[0] as [string, string, number[]])[2];
}

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
  jest.setSystemTime(T0);
  const s = state([makeCareTask(), makeCareTask({ task: 'nail_trim', times: 1, period_days: 28 })]);
  mocked.startCareTask.mockImplementation((task: string) =>
    Promise.resolve({ status: 'accepted', session: task === 'nail_trim' ? NAILS : makeCareTaskSession(), state: s }),
  );
  mocked.finishCareTask.mockImplementation((_task: string, id: string, strokes: number[]) =>
    Promise.resolve({
      status: 'accepted',
      result: { session_id: id, success: true, reason: null, strokes: strokes.length, min_strokes: 8, segments_hit: 1, segments: 1 },
      state: s,
    }),
  );
  mocked.getChildPet.mockResolvedValue(s);
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({ token: 't', user: { id: 2, name: 'Maja', email: null, role: 'child' }, pet: makePet(), awaitingContract: false });
});

afterEach(() => {
  jest.useRealTimers();
  jest.restoreAllMocks();
});

describe('teeth on the brush scratch card', () => {
  it('holding the button brushes; the strokes sent are the ones the server counts', async () => {
    const view = viewOf(state([makeCareTask()]), true);
    renderWithQuery(<CareTaskOverlay task="tooth_brushing" view={view} onClose={jest.fn()} reduceMotion clock={clock} />);
    expect(screen.getByTestId('care-tooth_brushing-period').props.children).toBe('Ta teden: 0 od 7');
    fireEvent.press(screen.getByTestId('care-tooth_brushing-start'));
    await advance(0);
    expect(mocked.startCareTask).toHaveBeenCalledWith('tooth_brushing');
    expect(screen.getByTestId('care-tooth_brushing-step').props.children).toBe('Sprednji zobje');
    fireEvent(screen.getByTestId('care-tooth_brushing-stroke'), 'pressIn');
    await advance(HOLD_START_MS + 26_000);
    fireEvent(screen.getByTestId('care-tooth_brushing-stroke'), 'pressOut');
    await advance(4_000);
    expect(mocked.finishCareTask).toHaveBeenCalledTimes(1);
    expect(scoreStrokes(sentStrokes(), { duration_ms: 30_000, min_strokes: 10, segments: 3, min_stroke_interval_ms: 150 })).toMatchObject({ success: true });
    await advance(0);
    expect(screen.getByTestId('care-tooth_brushing-result-title').props.children).toBe('Bleščeči zobki!');
  }, 30_000);

  it('shows the sleeping refusal with the wake time and no start', () => {
    const view = viewOf(state([makeCareTask({ can_start: false, blocked_reason: 'care_task_quiet_hours', next_allowed_at: '2026-10-05T07:00:00+02:00' })]));
    renderWithQuery(<CareTaskOverlay task="tooth_brushing" view={view} onClose={jest.fn()} reduceMotion clock={clock} />);
    expect(screen.getByTestId('care-tooth_brushing-blocked').props.children).toBe('Kuža zdaj spi. Pusti ga počivati — lahko to narediš jutri ob 7:00.');
    expect(screen.getByTestId('care-tooth_brushing-start').props.accessibilityState).toMatchObject({ disabled: true });
  });
});

describe('nails: tap each claw', () => {
  it('each claw is a button (screen reader); 8 taps finish up to 2 s early', async () => {
    const view = viewOf(state([makeCareTask({ task: 'nail_trim', times: 1, period_days: 28 })]));
    renderWithQuery(<CareTaskOverlay task="nail_trim" view={view} onClose={jest.fn()} reduceMotion clock={clock} />);
    fireEvent.press(screen.getByTestId('care-nail_trim-start'));
    await advance(0);
    expect(screen.getByTestId('care-nail_trim-claw-0').props.accessibilityLabel).toBe('Kremplj 1 od 8');
    expect(screen.queryByTestId('care-nail_trim-hold')).toBeNull();
    for (let i = 0; i < 8; i++) {
      await advance(500 + (i % 3) * 120);
      fireEvent.press(screen.getByTestId(`care-nail_trim-claw-${i}`));
    }
    // A trimmed claw cannot be trimmed again.
    fireEvent.press(screen.getByTestId('care-nail_trim-claw-0'));
    expect(screen.getByTestId('care-nail_trim-claw-0').props.accessibilityLabel).toBe('Kremplj 1 od 8, pristrižen');
    expect(screen.getByTestId('care-nail_trim-step').props.children).toBe('Vsi kremplji so pristriženi! Še trenutek …');
    await advance(6_000);
    expect(mocked.finishCareTask).toHaveBeenCalledTimes(1);
    expect(sentStrokes()).toHaveLength(8);
    expect(Date.now() - T0).toBeLessThanOrEqual(12_000);
    expect(Date.now() - T0).toBeGreaterThanOrEqual(10_000);
  });

  it('in hold mode the button trims one claw after another while held', async () => {
    const view = viewOf(state([makeCareTask({ task: 'nail_trim', times: 1, period_days: 28 })]), true);
    renderWithQuery(<CareTaskOverlay task="nail_trim" view={view} onClose={jest.fn()} reduceMotion clock={clock} />);
    fireEvent.press(screen.getByTestId('care-nail_trim-start'));
    await advance(300);
    fireEvent(screen.getByTestId('care-nail_trim-hold'), 'pressIn');
    await advance(7_000);
    fireEvent(screen.getByTestId('care-nail_trim-hold'), 'pressOut');
    expect(screen.getByTestId('care-nail_trim-progress').props.children).toBe('8 / 8');
    await advance(5_000);
    expect(scoreStrokes(sentStrokes(), { duration_ms: 12_000, min_strokes: 8, segments: 1, min_stroke_interval_ms: 150 })).toMatchObject({ success: true });
  });
});
