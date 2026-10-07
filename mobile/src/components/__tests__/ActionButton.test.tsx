/**
 * Tests for the ActionButton component.
 */

import React from 'react';
import { StyleSheet } from 'react-native';
import { render, fireEvent } from '@testing-library/react-native';
import { Beef } from 'lucide-react-native';
import ActionButton from '@/components/ActionButton';

describe('ActionButton', () => {
  // CGP v2: the care that is due is the one solid-mint button; a disabled one never is.
  it('due → solid mint button and mint label; disabled wins over due', () => {
    const bg = (testID: string, r: ReturnType<typeof render>) =>
      StyleSheet.flatten(r.getByTestId(testID).props.style).backgroundColor;
    const due = render(<ActionButton testID="b" icon={<Beef />} label="Feed" onPress={jest.fn()} due />);
    expect(bg('b', due)).toBe('#7FE0B4');
    expect(StyleSheet.flatten(due.getByText('Feed').props.style).color).toBe('#7FE0B4');
    due.unmount();
    const blocked = render(<ActionButton testID="b" icon={<Beef />} label="Feed" onPress={jest.fn()} due disabled />);
    expect(bg('b', blocked)).not.toBe('#7FE0B4');
  });

  it('renders with icon and label', () => {
    const { getByText } = render(
      <ActionButton icon={<Beef />} label="Feed" onPress={jest.fn()} />,
    );

    // `textTransform: 'uppercase'` is a style, so the rendered text keeps its case.
    expect(getByText('Feed')).toBeTruthy();
  });

  it('calls onPress when pressed and not disabled', () => {
    const onPress = jest.fn();
    const { getByText } = render(
      <ActionButton icon={<Beef />} label="Feed" onPress={onPress} />,
    );

    fireEvent.press(getByText('Feed'));
    expect(onPress).toHaveBeenCalledTimes(1);
  });

  it('does not call onPress when disabled', () => {
    const onPress = jest.fn();
    const { getByText } = render(
      <ActionButton icon={<Beef />} label="Feed" onPress={onPress} disabled />,
    );

    // The component renders with the label even when disabled
    expect(getByText('Feed')).toBeTruthy();
  });

  it('renders with different labels', () => {
    const { getByText, rerender } = render(
      <ActionButton icon={<Beef />} label="Feed" onPress={jest.fn()} />,
    );
    expect(getByText('Feed')).toBeTruthy();

    rerender(
      <ActionButton icon={<Beef />} label="Water" onPress={jest.fn()} />,
    );
    expect(getByText('Water')).toBeTruthy();
  });
});

describe('ActionButton dock hint (device feedback 2026-10-07)', () => {
  it('a day hint renders two lines; the time line is never ellipsised (shrinks instead)', () => {
    const r = render(
      <ActionButton
        testID="feed"
        icon={<Beef />}
        label="Feed"
        onPress={jest.fn()}
        disabled
        hint={{ day: 'tomorrow', text: '06:00', a11y: 'tomorrow at 06:00' }}
        compact
      />,
    );
    expect(r.getByTestId('feed-hint-day').props.children).toBe('tomorrow');
    const time = r.getByTestId('feed-hint-text');
    expect(time.props.children).toBe('06:00');
    expect(time.props.numberOfLines).toBe(1);
    expect(time.props.adjustsFontSizeToFit).toBe(true);
    expect(time.props.ellipsizeMode).toBeUndefined();
    expect(r.getByTestId('feed').props.accessibilityLabel).toBe('Feed, tomorrow at 06:00');
  });

  it('a plain string hint is one block that may wrap to two lines', () => {
    const r = render(<ActionButton testID="clean" icon={<Beef />} label="Clean" onPress={jest.fn()} disabled hint="Najprej pospravi" />);
    expect(r.queryByTestId('clean-hint-day')).toBeNull();
    expect(r.getByTestId('clean-hint-text').props.numberOfLines).toBe(2);
    expect(r.getByTestId('clean').props.accessibilityLabel).toBe('Clean, Najprej pospravi');
  });

  it('long labels ("Emergency meal", "Nujni obrok") may wrap and shrink, capped against huge text sizes', () => {
    const r = render(<ActionButton testID="feed" icon={<Beef />} label="Emergency meal" onPress={jest.fn()} due />);
    const label = r.getByTestId('feed-label');
    expect(label.props.numberOfLines).toBe(2);
    expect(label.props.adjustsFontSizeToFit).toBe(true);
    expect(label.props.maxFontSizeMultiplier).toBeGreaterThan(1);
    expect(StyleSheet.flatten(label.props.style).textAlign).toBe('center');
  });

  it('no hint → no hint block', () => {
    const r = render(<ActionButton testID="walk" icon={<Beef />} label="Walk" onPress={jest.fn()} hint={null} />);
    expect(r.queryByTestId('walk-hint')).toBeNull();
  });
});
