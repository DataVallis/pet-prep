/**
 * M5-T12 (David 2026-10-26): the parent's child card says "Danes še ni potiskov …" in adapted
 * mode after noon with 0 steps today, and its button opens »Vpiši sprehod».
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import ChildOverviewCard from '@/components/parent/ChildOverviewCard';
import { normalizePet } from '@/modules/family/family';
import { makeDayRow, makeFamilyPet, makeLast7Days, makeScoredChild } from '@/test-utils/fixtures';

function noStepsToday() {
  const rows = makeLast7Days();
  rows[rows.length - 1] = makeDayRow('2026-10-04', { walk_steps: 0, walk_done: false });
  return rows;
}

describe('ChildOverviewCard — no pushes today (M5-T12)', () => {
  let now: jest.SpyInstance<number, []>;
  beforeEach(() => {
    now = jest.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-10-04T14:00:00+02:00'));
  });
  afterEach(() => now.mockRestore());

  it('adapted mode, 0 pushes after noon → hint with a button to the log-walk card', () => {
    const child = makeScoredChild({ adapted_mode: true, last_7_days: noStepsToday() });
    const onLogWalk = jest.fn();
    render(
      <ChildOverviewCard
        child={child}
        pet={normalizePet(makeFamilyPet())}
        timezone="Europe/Ljubljana"
        onOpen={jest.fn()}
        onChildPin={jest.fn()}
        onLogWalk={onLogWalk}
      />,
    );
    expect(screen.getByText('Danes še ni potiskov. Brez Apple Watch (ali naprave za Health Connect) vpišite minute sprehoda.')).toBeTruthy();
    fireEvent.press(screen.getByTestId(`child-no-pushes-${child.id}-log-walk`));
    expect(onLogWalk).toHaveBeenCalledWith(child);
  });

  it('steps mode → no hint (the child is never nagged; this is parent-only anyway)', () => {
    const child = makeScoredChild({ adapted_mode: false, last_7_days: noStepsToday() });
    render(<ChildOverviewCard child={child} pet={normalizePet(makeFamilyPet())} timezone="Europe/Ljubljana" onOpen={jest.fn()} onChildPin={jest.fn()} />);
    expect(screen.queryByTestId(`child-no-pushes-${child.id}`)).toBeNull();
  });

  it('morning → no hint yet', () => {
    now.mockReturnValue(Date.parse('2026-10-04T09:00:00+02:00'));
    const child = makeScoredChild({ adapted_mode: true, last_7_days: noStepsToday() });
    render(<ChildOverviewCard child={child} pet={normalizePet(makeFamilyPet())} timezone="Europe/Ljubljana" onOpen={jest.fn()} onChildPin={jest.fn()} />);
    expect(screen.queryByTestId(`child-no-pushes-${child.id}`)).toBeNull();
  });
});
