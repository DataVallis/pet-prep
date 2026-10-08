/**
 * M5-R05 on the parent's side: "Danes: N× igra z žogo, M× crkljanje" on the pet card (dogs
 * with play only — a count, never a score) and the timeline rows of the child detail with
 * the merged count and a heart.
 */
import { act, render, screen } from '@testing-library/react-native';

import { api } from '@/api/client';
import ChildOverviewCard from '@/components/parent/ChildOverviewCard';
import { familyFromDashboard, normalizePet, type FamilyOverview } from '@/modules/family/family';
import ChildDetailScreen from '@/screens/parent/ChildDetailScreen';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getChildReport: jest.fn(), getPetActivities: jest.fn() } };
});

const getChildReport = api.getChildReport as jest.Mock;
const getPetActivities = api.getPetActivities as jest.Mock;

function renderCard(playToday: unknown) {
  const child = makeScoredChild();
  const pet = normalizePet({ ...makeFamilyPet(), play_today: playToday } as ReturnType<typeof makeFamilyPet>);
  render(<ChildOverviewCard child={child} pet={pet} timezone="Europe/Ljubljana" onOpen={jest.fn()} onChildPin={jest.fn()} />);
  return child.id;
}

describe('parent — play & cuddle (M5-R05)', () => {
  it('pet card: today\'s counts', () => {
    const id = renderCard({ play: 3, cuddle: 2 });
    expect(screen.getByTestId(`child-pet-play-today-${id}`)).toHaveTextContent('♥ Danes: 3× igra z žogo, 2× crkljanje');
  });

  it('pet card: nothing for a pet without play (free, legacy, older server)', () => {
    const id = renderCard(null);
    expect(screen.queryByTestId(`child-pet-play-today-${id}`)).toBeNull();
  });

  it('timeline: "Igra z žogo ×3 · Luka", "Crkljanje · Luka"', async () => {
    const LUKA = makeScoredChild();
    getChildReport.mockReturnValue(new Promise(() => undefined));
    getPetActivities.mockResolvedValue({
      data: [
        { id: 1, activity_type: 'played_with_pet', value: 3, actor_user_id: 2, actor_nickname: 'Luka', created_at: '2026-10-04T15:00:00+02:00', is_positive: true },
        { id: 2, activity_type: 'cuddled_pet', value: 1, actor_user_id: 2, actor_nickname: 'Luka', created_at: '2026-10-04T16:00:00+02:00', is_positive: true },
      ],
      meta: { current_page: 1, last_page: 1, total: 2 },
    });
    const family = familyFromDashboard(
      makeScoredDashboard([LUKA], [makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] })]) as never,
    ) as FamilyOverview;
    renderWithQuery(<ChildDetailScreen child={LUKA} family={family} onBack={jest.fn()} />);
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 20));
    });
    expect(screen.getByText('Igra z žogo ×3 · Luka')).toBeTruthy();
    expect(screen.getByText('Crkljanje · Luka')).toBeTruthy();
  });
});
