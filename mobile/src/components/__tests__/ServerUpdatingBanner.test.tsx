/**
 * M5-F13: the calm "Updating PetPrep" pill appears with an outage, disappears by itself
 * when the server answers again, never takes touches, is translated and respects Reduce Motion.
 */
import { act, render, screen } from '@testing-library/react-native';
import { AccessibilityInfo, Platform } from 'react-native';

import ServerUpdatingBanner, { BANNER_FADE_MS } from '@/components/ServerUpdatingBanner';
import { i18n } from '@/i18n';
import { useServerStatusStore } from '@/store/serverStatusStore';

const SL = 'Posodabljamo PetPrep — takoj smo nazaj.';
const EN = 'Updating PetPrep — back in a moment.';

describe('ServerUpdatingBanner', () => {
  afterEach(async () => {
    jest.useRealTimers();
    jest.restoreAllMocks();
    await act(async () => {
      await i18n.changeLanguage('sl');
    });
  });

  it('hidden while the server is healthy', () => {
    render(<ServerUpdatingBanner tone="dark" />);
    expect(screen.queryByTestId('server-updating-banner')).toBeNull();
  });

  it('shows on an outage (Slovenian / English) and hides after the fade once a request succeeds', async () => {
    jest.useFakeTimers();
    render(<ServerUpdatingBanner tone="light" />);
    act(() => useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000));
    expect(screen.getByText(SL)).toBeTruthy();
    expect(screen.getByTestId('server-updating-banner').props.accessibilityLabel).toMatch(/Posodabljamo PetPrep/);

    await act(async () => {
      await i18n.changeLanguage('en');
    });
    expect(screen.getByText(EN)).toBeTruthy();

    act(() => useServerStatusStore.getState().noteServerAnswered());
    await act(async () => {
      await jest.advanceTimersByTimeAsync(BANNER_FADE_MS + 50);
    });
    expect(screen.queryByTestId('server-updating-banner')).toBeNull();
  });

  it('never blocks touches (pointerEvents none on its slot)', () => {
    act(() => useServerStatusStore.getState().noteOutageFailure('gateway', null));
    render(<ServerUpdatingBanner tone="dark" />);
    expect(screen.getByTestId('server-updating-banner-slot').props.pointerEvents).toBe('none');
  });

  it('QA n2: iOS — announced once to VoiceOver when it appears', () => {
    const announce = jest.spyOn(AccessibilityInfo, 'announceForAccessibility').mockImplementation(() => undefined);
    const os = Platform.OS;
    Object.defineProperty(Platform, 'OS', { value: 'ios', configurable: true });
    try {
      render(<ServerUpdatingBanner tone="dark" />);
      expect(screen.queryByTestId('server-updating-banner')).toBeNull();
      announce.mockClear();
      act(() => useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000));
      act(() => useServerStatusStore.getState().noteOutageFailure('network', null));
      expect(announce).toHaveBeenCalledTimes(1);
      expect(announce).toHaveBeenCalledWith('Posodabljamo PetPrep. Takoj smo nazaj, vse je shranjeno.');
    } finally {
      Object.defineProperty(Platform, 'OS', { value: os, configurable: true });
    }
  });

  it('Reduce Motion: appears and disappears without animating', async () => {
    jest.spyOn(AccessibilityInfo, 'isReduceMotionEnabled').mockResolvedValue(true);
    render(<ServerUpdatingBanner tone="dark" />);
    await act(async () => {
      await Promise.resolve();
    });
    act(() => useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000));
    expect(screen.getByTestId('server-updating-banner')).toBeTruthy();
    act(() => useServerStatusStore.getState().noteServerAnswered());
    // Gone at once — no fade timer needed.
    expect(screen.queryByTestId('server-updating-banner')).toBeNull();
  });
});
