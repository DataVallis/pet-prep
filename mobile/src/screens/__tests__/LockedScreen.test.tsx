import { render, screen } from '@testing-library/react-native';
import { StyleSheet, type TextStyle, type ViewStyle } from 'react-native';

import LockedScreen, { LOCKED_STRINGS, isTranslucentLock, lockVeil, lockedCopy } from '@/screens/LockedScreen';
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

  describe('2026-10-06: vet veil by sick-video availability', () => {
    const bg = () =>
      ([screen.getByTestId('locked-screen').props.style].flat(3) as Array<{ backgroundColor?: string } | undefined>)
        .map((st) => st?.backgroundColor)
        .filter(Boolean);

    it('lockVeil: real sick video → grey; substitute / no video → ill; hard stop always grey; game over opaque', () => {
      expect(lockVeil('illness', 'sick')).toBe('grey');
      expect(lockVeil('illness', 'sleeping')).toBe('ill');
      expect(lockVeil('illness', 'idle')).toBe('ill');
      expect(lockVeil('illness', null)).toBe('ill');
      expect(lockVeil('hard_stop', 'sleeping')).toBe('grey');
      expect(lockVeil('hard_stop', null)).toBe('grey');
      expect(lockVeil('game_over', 'sick')).toBe('opaque');
      expect(lockVeil('inactive', null)).toBe('opaque');
    });

    it('free mutt at the vet (sleeping substitute): dark veil, card text still on the glass card', () => {
      useAppStore.getState().setLockState('illness');
      useAppStore.getState().setHudVideoState('sleeping');
      render(<LockedScreen />);
      expect(bg()).toEqual(['rgba(30, 41, 59, 0.8)']);
      expect(screen.getByTestId('locked-card-glass')).toBeTruthy();
      const title = StyleSheet.flatten(screen.getByText(LOCKED_STRINGS.illness.title).props.style) as TextStyle;
      expect(title.color).toBe('#ffffff');
    });

    it('premium at the vet (real sick video): the lighter grey veil stays', () => {
      useAppStore.getState().setLockState('illness');
      useAppStore.getState().setHudVideoState('sick');
      render(<LockedScreen />);
      expect(bg()).toEqual(['rgba(71, 85, 105, 0.55)']);
    });

    it('the HUD video state is reset with the session', () => {
      useAppStore.getState().setHudVideoState('sick');
      useAppStore.getState().reset();
      expect(useAppStore.getState().hudVideoState).toBeNull();
    });
  });

  it('2026-10-05: is a full-screen overlay styled by StyleSheet (no NativeWind)', () => {
    useAppStore.getState().setLockState('game_over');
    render(<LockedScreen />);

    const root = StyleSheet.flatten(screen.getByTestId('locked-screen').props.style) as ViewStyle;
    expect(root).toMatchObject({ position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, zIndex: 50 });
    expect(StyleSheet.flatten(screen.getByTestId('locked-glow').props.style)).toMatchObject({ width: 256, borderRadius: 128 });
    const title = StyleSheet.flatten(screen.getByText(LOCKED_STRINGS.game_over.title).props.style) as TextStyle;
    expect(title).toMatchObject({ color: '#ffffff', fontSize: 24, textAlign: 'center' });
  });

  it('translucent locks: glass card, lighter body text, no red glow', () => {
    useAppStore.getState().setLockState('hard_stop');
    render(<LockedScreen />);

    expect(StyleSheet.flatten(screen.getByTestId('locked-card-glass').props.style)).toMatchObject({ borderRadius: 28 });
    expect(screen.queryByTestId('locked-glow')).toBeNull();
    const body = StyleSheet.flatten(screen.getByText(LOCKED_STRINGS.hard_stop.body).props.style) as TextStyle;
    expect(body.color).toBe('#cbd5e1');
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
