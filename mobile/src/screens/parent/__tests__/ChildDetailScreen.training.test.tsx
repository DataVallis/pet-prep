/**
 * M5-R03 in the parent's child detail: "Šola" card ("Kuža zna: sedi ✓, pridi 60 % …",
 * today's session done / not, a session running), the training row in the routine
 * totals only for a pet with training, timeline label of a completed session. Legacy
 * pets and older payloads show nothing new.
 */
import { act, screen } from '@testing-library/react-native';

import { api } from '@/api/client';
import { familyFromDashboard, type FamilyOverview } from '@/modules/family/family';
import ChildDetailScreen from '@/screens/parent/ChildDetailScreen';
import { makeDayRow, makeFamilyPet, makeScoredChild, makeScoredDashboard, makeTrainingCommands } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getChildReport: jest.fn(), getPetActivities: jest.fn() } };
});

const getChildReport = api.getChildReport as jest.Mock;
const getPetActivities = api.getPetActivities as jest.Mock;

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

const LUKA = makeScoredChild();

function family(training: unknown): FamilyOverview {
  return familyFromDashboard(
    makeScoredDashboard([LUKA], [makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }], training: training as never })]) as never,
  ) as FamilyOverview;
}

function report(training: Record<string, number> | undefined) {
  return {
    child: { id: 2, name: 'Luka' },
    pet_id: 7,
    timezone: 'Europe/Ljubljana',
    days: 7,
    from: '2026-09-28',
    to: '2026-10-04',
    traffic_light: { color: 'green', reasons: [] },
    care_score: { score: 90, done: 9, expected: 10, routines: 10, illnesses: 0, since: null },
    period_score: { score: 90, done: 9, expected: 10, routines: 10, illnesses: 0, since: null },
    progress: null,
    by_type: {
      feed: { expected: 2, done: 2, done_by_child: 2, missed: 0, pending: 0 },
      water: { expected: 3, done: 3, done_by_child: 3, missed: 0, pending: 0 },
      clean: { expected: 0, done: 0, done_by_child: 0, missed: 0, pending: 0 },
      walk: { expected: 1, done: 1, done_by_child: 1, missed: 0, pending: 0 },
      ...(training ? { training } : {}),
    },
    daily: [makeDayRow('2026-10-04')],
    missed: [],
    illnesses: [],
  };
}

describe('ChildDetailScreen — training (M5-R03)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    getPetActivities.mockResolvedValue({
      data: [{ id: 1, activity_type: 'trained_pet', value: 4, actor_user_id: 2, actor_nickname: 'Luka', created_at: '2026-10-04T15:00:00+02:00', is_positive: true }],
      meta: { current_page: 1, last_page: 1, total: 1 },
    });
  });

  it('shows what the dog knows, today\'s session and the training row', async () => {
    getChildReport.mockResolvedValue(report({ expected: 7, done: 5, done_by_child: 5, missed: 2, pending: 0 }));
    const f = family({ enabled: true, commands: makeTrainingCommands({ sit: 100, come: 60 }), today_done: false, session_active: true });
    renderWithQuery(<ChildDetailScreen child={LUKA} family={f} onBack={jest.fn()} />);
    await flush();
    const card = screen.getByTestId('detail-pet-training');
    expect(card).toHaveTextContent(/Kuža zna: sedi ✓, pridi 60 %, prostor 0 %, lulat zunaj 0 %/);
    expect(card).toHaveTextContent(/Današnja vaja: še ne/);
    expect(card).toHaveTextContent(/Otrok zdaj vadi s kužkom\./);
    expect(screen.getByTestId('report-type-training')).toHaveTextContent(/Šola5 od 7zamujeno 2/);
    expect(screen.getByText('Luka opravil(a) vajo v šoli')).toBeTruthy();
  });

  it('today done', async () => {
    getChildReport.mockResolvedValue(report({ expected: 1, done: 1, done_by_child: 1, missed: 0, pending: 0 }));
    const f = family({ enabled: true, commands: makeTrainingCommands({ place: 30 }), today_done: true, session_active: false });
    renderWithQuery(<ChildDetailScreen child={LUKA} family={f} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('detail-pet-training')).toHaveTextContent(/Današnja vaja: opravljena ✓/);
  });

  it('legacy pet / older server: no card, no training row', async () => {
    getChildReport.mockResolvedValue(report({ expected: 0, done: 0, done_by_child: 0, missed: 0, pending: 0 }));
    const { unmount } = renderWithQuery(
      <ChildDetailScreen child={LUKA} family={family({ enabled: false, commands: [], today_done: false, session_active: false })} onBack={jest.fn()} />,
    );
    await flush();
    expect(screen.queryByTestId('detail-pet-training')).toBeNull();
    expect(screen.queryByTestId('report-type-training')).toBeNull();
    expect(screen.getByTestId('report-type-feed')).toBeTruthy();
    unmount();

    getChildReport.mockResolvedValue(report(undefined));
    renderWithQuery(<ChildDetailScreen child={LUKA} family={family(undefined)} onBack={jest.fn()} />);
    await flush();
    expect(screen.queryByTestId('detail-pet-training')).toBeNull();
    expect(screen.queryByTestId('report-type-training')).toBeNull();
  });
});
