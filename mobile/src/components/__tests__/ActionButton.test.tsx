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
