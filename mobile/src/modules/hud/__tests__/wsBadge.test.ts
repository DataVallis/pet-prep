import { act, renderHook } from '@testing-library/react-native';

import type { WebSocketStatus } from '@/store/appStore';
import { WS_BADGE_STRINGS, WS_OFFLINE_DEBOUNCE_MS, wsStatusLine, wsTone } from '@/modules/hud/wsBadge';
import { useWsOfflineIndicator } from '@/modules/hud/useWsOfflineIndicator';

describe('wsStatusLine (settings sheet, David 2026-11-28)', () => {
  it('reads as a system line, not as »the dog is alive«', () => {
    expect(wsStatusLine('connected')).toEqual({ tone: 'live', text: 'Povezava s strežnikom: v živo' });
    expect(wsStatusLine('connecting')).toEqual({ tone: 'connecting', text: 'Povezava s strežnikom: povezujem …' });
  });

  it.each(['disconnected', 'reconnecting'] as const)('%s → calm »samodejno osveževanje« (the HUD keeps polling)', (status) => {
    expect(wsTone(status)).toBe('polling');
    expect(wsStatusLine(status).text).toBe('Povezava s strežnikom: samodejno osveževanje');
  });

  it('never says "BREZ POVEZAVE" to the child', () => {
    expect(JSON.stringify(WS_BADGE_STRINGS)).not.toContain('BREZ POVEZAVE');
  });
});

describe('useWsOfflineIndicator', () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it('shows only after the socket stayed not connected for the debounce', () => {
    const { result, rerender } = renderHook(({ s }: { s: WebSocketStatus }) => useWsOfflineIndicator(s), {
      initialProps: { s: 'connected' as WebSocketStatus },
    });
    expect(result.current).toBe(false);
    rerender({ s: 'reconnecting' });
    act(() => jest.advanceTimersByTime(WS_OFFLINE_DEBOUNCE_MS - 1));
    expect(result.current).toBe(false);
    act(() => jest.advanceTimersByTime(1));
    expect(result.current).toBe(true);
    rerender({ s: 'connected' });
    expect(result.current).toBe(false);
  });

  it('a brief reconnect never flashes; connecting → disconnected keeps counting', () => {
    const { result, rerender } = renderHook(({ s }: { s: WebSocketStatus }) => useWsOfflineIndicator(s), {
      initialProps: { s: 'connected' as WebSocketStatus },
    });
    rerender({ s: 'reconnecting' });
    act(() => jest.advanceTimersByTime(2_000));
    rerender({ s: 'connected' });
    act(() => jest.advanceTimersByTime(WS_OFFLINE_DEBOUNCE_MS));
    expect(result.current).toBe(false);

    rerender({ s: 'connecting' });
    act(() => jest.advanceTimersByTime(3_000));
    rerender({ s: 'disconnected' });
    act(() => jest.advanceTimersByTime(WS_OFFLINE_DEBOUNCE_MS - 3_000));
    expect(result.current).toBe(true);
  });
});
