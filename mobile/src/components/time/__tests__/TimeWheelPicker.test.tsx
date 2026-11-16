import { useState } from 'react';
import { act, fireEvent, render, screen } from '@testing-library/react-native';

import TimeWheelPicker from '@/components/time/TimeWheelPicker';
import { WHEEL_ROW_HEIGHT } from '@/components/time/WheelColumn';
import { applyTimeFormat } from '@/modules/time/clock';
import {
  hourFrom12,
  indexAtOffset,
  minuteItems,
  stepIndex,
  toHHMM,
  toParts,
  withPeriod,
} from '@/modules/time/wheel';

/*
 * Alarm-clock wheels (David 2026-11-16, choice 3): hour + minutes (15-minute steps) +
 * AM / PM in the 12 h format; one finger, no typing; screen readers adjust each column.
 */

const hidden = { includeHiddenElements: true };

function Harness({ initial, onChange }: { initial: string; onChange: (v: string) => void }) {
  const [value, setValue] = useState(initial);
  return (
    <TimeWheelPicker
      value={value}
      onChange={(v) => {
        setValue(v);
        onChange(v);
      }}
      testID="w"
    />
  );
}

describe('wheel math', () => {
  it('snaps an offset to the nearest row (clamped) and steps without wrapping', () => {
    expect(indexAtOffset(0, 44, 24)).toBe(0);
    expect(indexAtOffset(65, 44, 24)).toBe(1);
    expect(indexAtOffset(67, 44, 24)).toBe(2);
    expect(indexAtOffset(9999, 44, 24)).toBe(23);
    expect(indexAtOffset(-30, 44, 24)).toBe(0);
    expect(stepIndex(0, -1, 4)).toBe(0);
    expect(stepIndex(3, 1, 4)).toBe(3);
    expect(stepIndex(1, 1, 4)).toBe(2);
  });

  it('minutes in 15-minute steps; an old off-grid minute is kept, not moved', () => {
    expect(minuteItems().map((i) => i.value)).toEqual([0, 15, 30, 45]);
    expect(minuteItems(5).map((i) => i.value)).toEqual([0, 5, 15, 30, 45]);
  });

  it('12 h ↔ 24 h: 12 AM = 0, 12 PM = 12; AM/PM flips keep the hour', () => {
    expect(hourFrom12(12, 'am')).toBe(0);
    expect(hourFrom12(12, 'pm')).toBe(12);
    expect(hourFrom12(5, 'pm')).toBe(17);
    expect(toHHMM(withPeriod(toParts('07:30'), 'pm'))).toBe('19:30');
    expect(toHHMM(withPeriod(toParts('12:00'), 'am'))).toBe('00:00');
  });
});

describe('TimeWheelPicker', () => {
  it('24 h: scrolling the hour wheel and letting go chooses the row in the middle', () => {
    const onChange = jest.fn();
    render(<Harness initial="08:00" onChange={onChange} />);
    expect(screen.queryByTestId('w-period')).toBeNull();

    fireEvent(screen.getByTestId('w-hour-scroll', hidden), 'momentumScrollEnd', {
      nativeEvent: { contentOffset: { x: 0, y: 21 * WHEEL_ROW_HEIGHT + 10 } },
    });
    expect(onChange).toHaveBeenLastCalledWith('21:00');
  });

  it('a tap on a minute row chooses it (15-minute steps)', () => {
    const onChange = jest.fn();
    render(<Harness initial="08:00" onChange={onChange} />);
    fireEvent.press(screen.getByTestId('w-minute-item-45', hidden));
    expect(onChange).toHaveBeenLastCalledWith('08:45');
  });

  it('screen readers: each column is adjustable with its value; increment / decrement step it', () => {
    const onChange = jest.fn();
    render(<Harness initial="08:15" onChange={onChange} />);
    const hour = screen.getByTestId('w-hour');
    expect(hour.props.accessibilityRole).toBe('adjustable');
    expect(hour.props.accessibilityValue).toEqual({ text: '8' });

    fireEvent(hour, 'accessibilityAction', { nativeEvent: { actionName: 'increment' } });
    expect(onChange).toHaveBeenLastCalledWith('09:15');
    fireEvent(screen.getByTestId('w-minute'), 'accessibilityAction', { nativeEvent: { actionName: 'decrement' } });
    expect(onChange).toHaveBeenLastCalledWith('09:00');
  });

  it('12 h: hours 1–12 plus an AM / PM wheel; PM keeps the hour and minute', () => {
    act(() => applyTimeFormat('12h'));
    const onChange = jest.fn();
    render(<Harness initial="07:30" onChange={onChange} />);
    expect(screen.getByTestId('w-hour').props.accessibilityValue).toEqual({ text: '7' });
    expect(screen.getByTestId('w-period').props.accessibilityValue).toEqual({ text: 'dop.' });
    expect(screen.queryByTestId('w-hour-item-0', hidden)).toBeNull();

    fireEvent.press(screen.getByTestId('w-period-item-pm', hidden));
    expect(onChange).toHaveBeenLastCalledWith('19:30');
    fireEvent.press(screen.getByTestId('w-hour-item-12', hidden));
    expect(onChange).toHaveBeenLastCalledWith('12:30');
    fireEvent(screen.getByTestId('w-period'), 'accessibilityAction', { nativeEvent: { actionName: 'decrement' } });
    expect(onChange).toHaveBeenLastCalledWith('00:30');
  });
});
