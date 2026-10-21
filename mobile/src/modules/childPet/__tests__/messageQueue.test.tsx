/**
 * M5-T03 (APP_REFINEMENT_SPEC §12, David 2026-10-21): HUD messages queue FIFO, each stays
 * 2500 ms + 60 ms per character, a tap closes it, identical consecutive messages are dropped.
 */
import { act, renderHook } from '@testing-library/react-native';

import {
  advanceQueue,
  EMPTY_MESSAGE_QUEUE,
  enqueueMessage,
  messageDurationMs,
  messageText,
  useMessageQueue,
} from '@/modules/childPet/messageQueue';

describe('messageQueue (pure)', () => {
  it('duration = 2500 ms + 60 ms per character (code points)', () => {
    expect(messageDurationMs('')).toBe(2_500);
    expect(messageDurationMs('abcde')).toBe(2_800);
    expect(messageDurationMs('čšž✓')).toBe(2_500 + 4 * 60);
    expect(messageText({ tone: 'ok', message: 'Bravo!', next: 'Naslednjič čez ~2 h.' })).toBe('Bravo! Naslednjič čez ~2 h.');
  });

  it('FIFO: the first shows at once, the rest wait in order', () => {
    let s = enqueueMessage(EMPTY_MESSAGE_QUEUE, { tone: 'ok', message: 'A' });
    s = enqueueMessage(s, { tone: 'info', message: 'B' });
    s = enqueueMessage(s, { tone: 'ok', message: 'C' });
    expect(s.current?.message).toBe('A');
    expect(s.pending.map((m) => m.message)).toEqual(['B', 'C']);
    s = advanceQueue(s);
    expect(s.current?.message).toBe('B');
    s = advanceQueue(advanceQueue(s));
    expect(s.current).toBeNull();
    expect(advanceQueue(s)).toBe(s);
  });

  it('dedupes an identical message right after the same one, not a later repeat', () => {
    let s = enqueueMessage(EMPTY_MESSAGE_QUEUE, { tone: 'ok', message: 'A' });
    expect(enqueueMessage(s, { tone: 'ok', message: 'A' })).toBe(s);
    // Same text, other tone / next line → a different message.
    s = enqueueMessage(s, { tone: 'info', message: 'A' });
    expect(s.pending).toHaveLength(1);
    s = enqueueMessage(s, { tone: 'ok', message: 'B' });
    s = enqueueMessage(s, { tone: 'ok', message: 'A' });
    expect(s.pending.map((m) => `${m.tone}:${m.message}`)).toEqual(['info:A', 'ok:B', 'ok:A']);
  });
});

describe('useMessageQueue (fake timers)', () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it('shows each message for its own duration, one at a time', () => {
    const { result } = renderHook(() => useMessageQueue());
    act(() => {
      result.current.show({ tone: 'ok', message: 'abcde' }); // 2800 ms
      result.current.show({ tone: 'info', message: 'xy' }); // 2620 ms
    });
    expect(result.current.current?.message).toBe('abcde');
    act(() => jest.advanceTimersByTime(2_799));
    expect(result.current.current?.message).toBe('abcde');
    act(() => jest.advanceTimersByTime(1));
    expect(result.current.current?.message).toBe('xy');
    act(() => jest.advanceTimersByTime(2_619));
    expect(result.current.current?.message).toBe('xy');
    act(() => jest.advanceTimersByTime(1));
    expect(result.current.current).toBeNull();
  });

  it('a tap closes the shown message and the next follows with a fresh timer', () => {
    const { result } = renderHook(() => useMessageQueue());
    act(() => {
      result.current.show({ tone: 'ok', message: 'first' });
      result.current.show({ tone: 'ok', message: 'second' });
    });
    act(() => jest.advanceTimersByTime(2_000));
    act(() => result.current.dismiss());
    expect(result.current.current?.message).toBe('second');
    // The old timer is gone: 'second' keeps its full 2500 + 6×60 ms.
    act(() => jest.advanceTimersByTime(2_859));
    expect(result.current.current?.message).toBe('second');
    act(() => jest.advanceTimersByTime(1));
    expect(result.current.current).toBeNull();
    // Dismissing with nothing on screen does nothing.
    act(() => result.current.dismiss());
    expect(result.current.current).toBeNull();
  });

  it('a double tap of the same action shows the message once', () => {
    const { result } = renderHook(() => useMessageQueue());
    act(() => {
      result.current.show({ tone: 'ok', message: 'Bravo!' });
      result.current.show({ tone: 'ok', message: 'Bravo!' });
    });
    act(() => jest.advanceTimersByTime(messageDurationMs('Bravo!')));
    expect(result.current.current).toBeNull();
  });
});
