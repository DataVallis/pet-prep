import { render, screen } from '@testing-library/react-native';

import LockedScreen, { LOCKED_STRINGS, lockedCopy } from '@/screens/LockedScreen';
import { useAppStore } from '@/store/appStore';

describe('LockedScreen (M1-16)', () => {
  beforeEach(() => useAppStore.setState(useAppStore.getInitialState(), true));

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
