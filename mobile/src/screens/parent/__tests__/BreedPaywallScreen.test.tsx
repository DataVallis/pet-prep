/**
 * Simulated breed paywall (M3 RevenueCat later) after the NativeWind → StyleSheet
 * conversion (2026-10-05); Slovenian + English texts (M1-18).
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';
import { Alert, StyleSheet, type ViewStyle } from 'react-native';

import { i18n } from '@/i18n';
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

  afterEach(async () => {
    jest.useRealTimers();
    jest.restoreAllMocks();
    await act(async () => {
      await i18n.changeLanguage('sl');
    });
  });

  it('renders both breed cards with real styles', () => {
    render(<BreedPaywallScreen onBack={jest.fn()} />);

    expect(flat('breed-paywall')).toMatchObject({ flex: 1, backgroundColor: '#F3F5F2' });
    expect(flat('paywall-card-collie')).toMatchObject({ borderWidth: 2, borderColor: '#7FE0B4', borderRadius: 22 });
    expect(screen.getByText('Mešanček')).toBeTruthy();
    expect(screen.getByText('Border collie')).toBeTruthy();
    expect(screen.getByText('4000 korakov/dan')).toBeTruthy();
    expect(screen.getByText('10.000 korakov/dan')).toBeTruthy();
    expect(screen.getByText('Trenutna pasma')).toBeTruthy();
    expect(screen.getByText('4,99 €')).toBeTruthy();
  });

  it('renders in English', async () => {
    await act(async () => {
      await i18n.changeLanguage('en');
    });
    render(<BreedPaywallScreen onBack={jest.fn()} />);

    expect(screen.getByText('Breed selection')).toBeTruthy();
    expect(screen.getByText('Mixed breed')).toBeTruthy();
    expect(screen.getByText('4,000 steps/day')).toBeTruthy();
    expect(screen.getByText('10,000 steps/day')).toBeTruthy();
    expect(screen.getByText('Needs drop: −12 %/h')).toBeTruthy();
    expect(screen.getByText('Current breed')).toBeTruthy();
    expect(screen.getByText('€4.99')).toBeTruthy();
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

    expect(screen.getByText('Odklenjeno')).toBeTruthy();
    expect(screen.queryByTestId('paywall-unlock')).toBeNull();
    expect(Alert.alert).toHaveBeenCalledWith('Odklenjeno', 'Pasma border collie je odklenjena!');
  });

  it('shows the collie as unlocked for a collie owner', () => {
    useAppStore.setState({ pet: makePet({ breed_type: 'border_collie' }) });
    render(<BreedPaywallScreen onBack={jest.fn()} />);
    expect(screen.getByText('Odklenjeno')).toBeTruthy();
    expect(screen.getByText('Začetna pasma')).toBeTruthy();
  });
});
