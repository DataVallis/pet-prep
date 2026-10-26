/**
 * M5-T12 (David 2026-10-26): the child page shows "Danes še ni potiskov …" above »Vpiši
 * sprehod« in adapted mode after noon with 0 steps today; opened from a »Vpiši sprehod«
 * button (`focusLogWalk`) it scrolls to the card once laid out.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { api } from '@/api/client';
import { familyFromDashboard, type FamilyOverview } from '@/modules/family/family';
import ChildDetailScreen from '@/screens/parent/ChildDetailScreen';
import { makeDayRow, makeFamilyPet, makeLast7Days, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, getChildReport: jest.fn(), getPetActivities: jest.fn(), getParentPetGrowth: jest.fn() },
  };
});

function family(adapted: boolean, todaySteps: number): FamilyOverview {
  const days = makeLast7Days();
  days[days.length - 1] = makeDayRow('2026-10-04', { walk_steps: todaySteps, walk_done: todaySteps > 0 });
  return familyFromDashboard(
    makeScoredDashboard(
      [makeScoredChild({ adapted_mode: adapted, last_7_days: days })],
      [makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] })],
    ) as never,
  ) as FamilyOverview;
}

describe('ChildDetailScreen — no pushes today (M5-T12)', () => {
  let now: jest.SpyInstance<number, []>;
  beforeEach(() => {
    (api.getChildReport as jest.Mock).mockReturnValue(new Promise(() => undefined));
    (api.getPetActivities as jest.Mock).mockReturnValue(new Promise(() => undefined));
    now = jest.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-10-04T15:00:00+02:00'));
  });
  afterEach(() => now.mockRestore());

  it('hint above the log-walk card; its button and `focusLogWalk` scroll to the card', async () => {
    const fam = family(true, 0);
    renderWithQuery(<ChildDetailScreen child={fam.children[0]} family={fam} onBack={jest.fn()} focusLogWalk />);
    expect(screen.getByTestId('detail-no-pushes')).toBeTruthy();
    expect(screen.getByTestId('detail-log-walk')).toBeTruthy();
    await act(async () => {
      fireEvent(screen.getByTestId('detail-log-walk-anchor'), 'layout', { nativeEvent: { layout: { x: 0, y: 420, width: 343, height: 160 } } });
    });
    fireEvent.press(screen.getByTestId('detail-no-pushes-log-walk'));
  });

  it('pushes counted today → no hint, the card stays', () => {
    const fam = family(true, 1200);
    renderWithQuery(<ChildDetailScreen child={fam.children[0]} family={fam} onBack={jest.fn()} />);
    expect(screen.queryByTestId('detail-no-pushes')).toBeNull();
    expect(screen.getByTestId('detail-log-walk')).toBeTruthy();
  });

  it('steps mode → neither hint nor card', () => {
    const fam = family(false, 0);
    renderWithQuery(<ChildDetailScreen child={fam.children[0]} family={fam} onBack={jest.fn()} />);
    expect(screen.queryByTestId('detail-no-pushes')).toBeNull();
    expect(screen.queryByTestId('detail-log-walk')).toBeNull();
  });
});
