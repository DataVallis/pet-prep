/**
 * Cleaning mini-game after the NativeWind → StyleSheet conversion (2026-10-05).
 */
import { fireEvent, render, screen } from '@testing-library/react-native';
import { StyleSheet, type ViewStyle } from 'react-native';

import CleaningOverlay, { CLEANING_STRINGS } from '@/components/CleaningOverlay';

const flat = (testID: string): ViewStyle => StyleSheet.flatten(screen.getByTestId(testID).props.style) as ViewStyle;

function cleanRest(from: number) {
  for (let i = from; i < 5; i += 1) fireEvent.press(screen.getByTestId(`dirt-spot-${i}`));
}

describe('CleaningOverlay', () => {
  it('covers the HUD with a styled veil and a glass title card', () => {
    render(<CleaningOverlay onCleaned={jest.fn()} />);

    const root = flat('cleaning-overlay');
    expect(root).toMatchObject({ position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, zIndex: 20 });
    expect(root.backgroundColor).toMatch(/^rgba\(/);
    expect(screen.getByText(CLEANING_STRINGS.title)).toBeTruthy();
    expect(screen.getByText('0 / 5 madežev')).toBeTruthy();
    expect(screen.getByText(CLEANING_STRINGS.hint)).toBeTruthy();
  });

  it('renders five round, absolutely positioned dirt spots', () => {
    render(<CleaningOverlay onCleaned={jest.fn()} />);

    for (let i = 0; i < 5; i += 1) {
      const style = StyleSheet.flatten(
        (screen.getByTestId(`dirt-spot-${i}`).props.style as ViewStyle | undefined) ?? {},
      );
      expect(style.position).toBe('absolute');
      expect(typeof style.width).toBe('number');
      expect(style.borderRadius).toBe((style.width as number) / 2);
    }
  });

  it('counts progress and reports once when every spot is gone', () => {
    const onCleaned = jest.fn();
    render(<CleaningOverlay onCleaned={onCleaned} />);

    fireEvent.press(screen.getByTestId('dirt-spot-0'));
    expect(screen.getByText('1 / 5 madežev')).toBeTruthy();
    expect(screen.queryByText(CLEANING_STRINGS.hint)).toBeNull();
    const fill = StyleSheet.flatten(screen.getByTestId('cleaning-progress').props.children.props.style) as ViewStyle;
    expect(fill.width).toBe('20%');

    cleanRest(1);
    expect(screen.queryByTestId('dirt-spot-4')).toBeNull();
    expect(onCleaned).toHaveBeenCalledTimes(1);
  });

  it('offers "Kasneje" only when it can be closed', () => {
    const onClose = jest.fn();
    const { rerender } = render(<CleaningOverlay onCleaned={jest.fn()} onClose={onClose} />);
    fireEvent.press(screen.getByText(CLEANING_STRINGS.close));
    expect(onClose).toHaveBeenCalledTimes(1);

    rerender(<CleaningOverlay onCleaned={jest.fn()} />);
    expect(screen.queryByText(CLEANING_STRINGS.close)).toBeNull();
  });
});
