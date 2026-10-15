import { AppState, type AppStateStatus } from 'react-native';
import { focusManager } from '@tanstack/react-query';

import { ApiError } from '@/api/client';
import { bindFocusManagerToAppState, shouldRetry } from '@/api/queryClient';
import { OUTAGE_QUERY_RETRIES } from '@/modules/connectivity/serverOutage';
import { useServerStatusStore } from '@/store/serverStatusStore';

describe('focusManager ↔ AppState', () => {
  let listener: ((state: AppStateStatus) => void) | null = null;
  const remove = jest.fn();

  beforeEach(() => {
    listener = null;
    remove.mockClear();
    jest.spyOn(AppState, 'addEventListener').mockImplementation((type, handler) => {
      if (type === 'change') listener = handler as (state: AppStateStatus) => void;
      return { remove } as unknown as ReturnType<typeof AppState.addEventListener>;
    });
    bindFocusManagerToAppState();
  });

  afterEach(() => {
    jest.restoreAllMocks();
    focusManager.setFocused(undefined);
  });

  it('subscribes to AppState changes', () => {
    expect(AppState.addEventListener).toHaveBeenCalledWith('change', expect.any(Function));
    expect(listener).not.toBeNull();
  });

  it('is unfocused in the background (pauses refetchInterval polling) and focused when active', () => {
    listener?.('background');
    expect(focusManager.isFocused()).toBe(false);

    listener?.('inactive');
    expect(focusManager.isFocused()).toBe(false);

    listener?.('active');
    expect(focusManager.isFocused()).toBe(true);
  });

  it('removes the previous AppState subscription when the listener is replaced', () => {
    bindFocusManagerToAppState();
    expect(remove).toHaveBeenCalled();
  });
});

describe('shouldRetry', () => {
  it('never retries 4xx', () => {
    expect(shouldRetry(0, new ApiError('x', 401))).toBe(false);
    expect(shouldRetry(0, new ApiError('x', 429))).toBe(false);
  });

  it('retries plain network errors and other 5xx twice', () => {
    expect(shouldRetry(0, new TypeError('Network request failed'))).toBe(true);
    expect(shouldRetry(2, new TypeError('Network request failed'))).toBe(false);
    expect(shouldRetry(1, new ApiError('x', 500))).toBe(true);
    expect(shouldRetry(2, new ApiError('x', 500))).toBe(false);
  });

  it('M5-F13: a deploy (maintenance 503, 502, 504) is retried longer', () => {
    expect(shouldRetry(2, new ApiError('x', 503))).toBe(true);
    expect(shouldRetry(OUTAGE_QUERY_RETRIES - 1, new ApiError('x', 502))).toBe(true);
    expect(shouldRetry(OUTAGE_QUERY_RETRIES, new ApiError('x', 504))).toBe(false);
  });

  it('M5-F13: a 503 with a machine reason (social sign-in) is a normal 5xx', () => {
    expect(shouldRetry(2, new ApiError('x', 503, { reason: 'provider_unavailable' }))).toBe(false);
  });

  it('M5-F13: a network error while the banner shows (container restart) is retried longer', () => {
    useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000);
    expect(shouldRetry(2, new TypeError('Network request failed'))).toBe(true);
  });

  it('M5-F13: 401 / 409 / 422 / 423 are never retried, also during a deploy', () => {
    useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000);
    for (const status of [401, 409, 422, 423]) expect(shouldRetry(0, new ApiError('x', status))).toBe(false);
  });
});
