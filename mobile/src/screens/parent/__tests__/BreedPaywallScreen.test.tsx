/**
 * Simulated breed paywall (M3 RevenueCat later) after the NativeWind → StyleSheet
 * conversion (2026-10-05).
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';
import { Alert, StyleSheet, type ViewStyle } from 'react-native';

import BreedPaywallScreen from '@/screens/parent/BreedPaywallScreen';
import { useAppStore } from '@/store/appStore';
import { makePet } from '@/test-utils/fixtures';

const flat = (testID: string): ViewStyle => StyleSheet.flatten(screen.getByTestId(testID).props.style) as ViewStyle;

describe('BreedPaywallScreen', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.setState({ pet: makePet({ breed_type: 'mutt' }) });
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
  });

  afterEach(() => {
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it('renders both breed cards with real styles', () => {
    render(<BreedPaywallScreen onBack={jest.fn()} />);

    expect(flat('breed-paywall')).toMatchObject({ flex: 1, backgroundColor: '#020617' });
    expect(flat('paywall-card-collie')).toMatchObject({ borderWidth: 2, borderColor: '#6366f1', borderRadius: 16 });
    expect(screen.getByText('Mutt')).toBeTruthy();
    expect(screen.getByText('Border Collie')).toBeTruthy();
    expect(screen.getByText('4,000 steps/day')).toBeTruthy();
    expect(screen.getByText('10,000 steps/day')).toBeTruthy();
    expect(screen.getByText('Current Breed')).toBeTruthy();
  });

  it('goes back', () => {
    const onBack = jest.fn();
    render(<BreedPaywallScreen onBack={onBack} />);
    fireEvent.press(screen.getByTestId('paywall-back'));
    expect(onBack).toHaveBeenCalledTimes(1);
  });

  it('simulates the unlock', async () => {
    render(<BreedPaywallScreen onBack={jest.fn()} />);

    fireEvent.press(screen.getByTestId('paywall-unlock'));
    expect(screen.getByTestId('paywall-unlock').props.accessibilityState).toMatchObject({ disabled: true });
    await act(async () => {
      await jest.advanceTimersByTimeAsync(1_500);
    });

    expect(screen.getByText('Unlocked')).toBeTruthy();
    expect(screen.queryByTestId('paywall-unlock')).toBeNull();
  });

  it('shows the collie as unlocked for a collie owner', () => {
    useAppStore.setState({ pet: makePet({ breed_type: 'border_collie' }) });
    render(<BreedPaywallScreen onBack={jest.fn()} />);
    expect(screen.getByText('Unlocked')).toBeTruthy();
    expect(screen.getByText('Starter breed')).toBeTruthy();
  });
});
