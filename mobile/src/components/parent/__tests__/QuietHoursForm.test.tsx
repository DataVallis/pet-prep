/**
 * »Brez nočnega spanja« warning (David 2026-11-24): a tester's family had quiet hours on but
 * no bedtime, so the pet needed care at 1–2 am. The editor says so where the switch is.
 */

import { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react-native';

import QuietHoursForm from '@/components/parent/QuietHoursForm';
import { DEFAULT_QUIET_TIMES, type QuietTimes } from '@/modules/quietHours/quietHours';

function Harness({ initial }: { initial: QuietTimes }) {
  const [value, setValue] = useState<QuietTimes>(initial);
  return <QuietHoursForm value={value} onChange={setValue} testID="qh" showActive />;
}

describe('QuietHoursForm — no night window', () => {
  it('shows no warning with the default bedtime', () => {
    render(<Harness initial={DEFAULT_QUIET_TIMES} />);
    expect(screen.queryByTestId('qh-no-night')).toBeNull();
  });

  it('warns as soon as bedtime is switched off, and hides it again when it is back on', () => {
    render(<Harness initial={DEFAULT_QUIET_TIMES} />);
    fireEvent(screen.getByTestId('qh-bed-switch'), 'valueChange', false);
    expect(screen.getByTestId('qh-no-night')).toHaveTextContent(/never sleeps|nikoli ne spi/);

    fireEvent(screen.getByTestId('qh-bed-switch'), 'valueChange', true);
    expect(screen.queryByTestId('qh-no-night')).toBeNull();
  });

  it('warns for a stored schedule that is on but has no bedtime', () => {
    render(<Harness initial={{ ...DEFAULT_QUIET_TIMES, bedtime_start: null, bedtime_end: null }} />);
    expect(screen.getByTestId('qh-no-night')).toBeTruthy();
  });

  it('does not warn while quiet hours are switched off altogether', () => {
    render(<Harness initial={{ ...DEFAULT_QUIET_TIMES, bedtime_start: null, bedtime_end: null, is_active: false }} />);
    expect(screen.queryByTestId('qh-no-night')).toBeNull();
  });
});
