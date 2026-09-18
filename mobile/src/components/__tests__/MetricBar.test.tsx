/**
 * Tests for the MetricBar component.
 */

import React from 'react';
import { render } from '@testing-library/react-native';
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
});
