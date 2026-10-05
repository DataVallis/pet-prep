import { render, screen } from '@testing-library/react-native';

import LockedScreen, { LOCKED_STRINGS, isTranslucentLock, lockedCopy } from '@/screens/LockedScreen';
import { useAppStore } from '@/store/appStore';

describe('LockedScreen (M1-16)', () => {
  beforeEach(() => useAppStore.setState(useAppStore.getInitialState(), true));

  it('M4-03 / spec §7: vet visit and hard stop are a translucent grey veil over the dog; game over / inactive opaque', () => {
    expect(isTranslucentLock('illness')).toBe(true);
    expect(isTranslucentLock('hard_stop')).toBe(true);
    expect(isTranslucentLock('game_over')).toBe(false);
    expect(isTranslucentLock('inactive')).toBe(false);

    useAppStore.getState().setLockState('hard_stop');
    const { unmount } = render(<LockedScreen />);
    const bg = () =>
      ([screen.getByTestId('locked-screen').props.style].flat(3) as Array<{ backgroundColor?: string } | undefined>)
        .map((st) => st?.backgroundColor)
        .filter(Boolean);
    expect(bg()).toEqual(['rgba(71, 85, 105, 0.55)']);
    expect(screen.getByTestId('locked-card-glass')).toBeTruthy();
    unmount();

    useAppStore.getState().setLockState('game_over');
    render(<LockedScreen />);
    expect(bg()).toEqual(['#000000']);
    expect(screen.getByTestId('locked-card')).toBeTruthy();
  });

  it('hard stop: "Starš je ustavil igro" with the spec text', () => {
    useAppStore.getState().setLockState('hard_stop');
    render(<LockedScreen />);
    expect(screen.getByText(LOCKED_STRINGS.hard_stop.title)).toBeTruthy();
    expect(screen.getByText('Simulacija je začasno ustavljena. Pogovori se s starši.')).toBeTruthy();
  });

  it('illness: vet end time in the family timezone (UTC broadcast instant)', () => {
    useAppStore.getState().setLockState('illness', { until: '2026-10-04T16:30:00+00:00', timezone: 'Europe/Ljubljana' });
    render(<LockedScreen />);
    expect(screen.getByText('Kuža je pri veterinarju do 18:30. Potrebuje počitek.')).toBeTruthy();
  });

  it('game over, inactive, illness without a time', () => {
    const none = { until: null, timezone: null };
    expect(lockedCopy('game_over', none)).toEqual(LOCKED_STRINGS.game_over);
    expect(lockedCopy('inactive', none)).toEqual(LOCKED_STRINGS.inactive);
    expect(lockedCopy('illness', none).body).toBe(LOCKED_STRINGS.illness.bodyNoTime);
  });
});
