/**
 * Tests for the MetricBar component.
 */

import React from 'react';
import { render, screen } from '@testing-library/react-native';
import { StyleSheet } from 'react-native';
import { Beef } from 'lucide-react-native';
import MetricBar from '@/components/MetricBar';

describe('MetricBar', () => {
  it('renders with label and percentage', () => {
    const { getByText } = render(
      <MetricBar level={75} label="Hunger" icon={<Beef />} />,
    );

    expect(getByText('75%')).toBeTruthy();
  });

  it('renders 0% when level is 0', () => {
    const { getByText } = render(
      <MetricBar level={0} label="Hunger" icon={<Beef />} />,
    );

    expect(getByText('0%')).toBeTruthy();
  });

  it('renders 100% when level is 100', () => {
    const { getByText } = render(
      <MetricBar level={100} label="Hunger" icon={<Beef />} />,
    );

    expect(getByText('100%')).toBeTruthy();
  });

  it('clamps level above 100', () => {
    const { getByText } = render(
      <MetricBar level={150} label="Hunger" icon={<Beef />} />,
    );

    expect(getByText('100%')).toBeTruthy();
  });

  it('clamps level below 0', () => {
    const { getByText } = render(
      <MetricBar level={-20} label="Hunger" icon={<Beef />} />,
    );

    expect(getByText('0%')).toBeTruthy();
  });

  it('defaults to a 100 pt track and shows the label on one line', () => {
    render(<MetricBar testID="m" level={50} label="Energija" icon={<Beef />} />);
    expect(StyleSheet.flatten(screen.getByTestId('m-track').props.style).height).toBe(100);
    expect(screen.getByText('Energija').props.numberOfLines).toBe(1);
    expect(screen.getByLabelText('Energija 50%')).toBeTruthy();
  });

  it('uses the HUD sizing and can hide the label in the tiny variant', () => {
    render(
      <MetricBar
        testID="m"
        level={50}
        label="Energija"
        icon={<Beef />}
        sizing={{ badge: 24, innerGap: 3, trackHeight: 31, showLabel: false }}
      />,
    );
    expect(StyleSheet.flatten(screen.getByTestId('m-track').props.style).height).toBe(31);
    expect(screen.queryByText('Energija')).toBeNull();
    expect(screen.getByText('50%')).toBeTruthy();
  });
});
