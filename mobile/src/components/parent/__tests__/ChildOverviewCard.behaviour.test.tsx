/**
 * M5-R02 on the parent's child card: open messes and the puppy clock, the 7-day
 * take-outs / tidied slippers, missed cleans named by their mess; nothing for a legacy pet.
 */
import { render, screen } from '@testing-library/react-native';

import ChildOverviewCard from '@/components/parent/ChildOverviewCard';
import { normalizePet } from '@/modules/family/family';
import { makeBehaviourEvent, makeFamilyPet, makeMissed, makeScoredChild, makeTakeOut } from '@/test-utils/fixtures';

const TZ = 'Europe/Ljubljana';

function renderCard(petBehaviour: unknown, stats: { taken_out: number; chewing_resolved: number }) {
  const base = makeScoredChild();
  const child = {
    ...base,
    stats: { ...base.stats, ...stats },
    today: {
      ...base.today,
      missed_count: 1,
      missed: [makeMissed('clean', '2026-10-04T10:00:00+02:00', '2026-10-04T12:00:00+02:00', '2026-10-04', 'accident')],
    },
  };
  const pet = normalizePet({ ...makeFamilyPet(), behaviour: petBehaviour } as ReturnType<typeof makeFamilyPet>);
  render(<ChildOverviewCard child={child} pet={pet} timezone={TZ} onOpen={jest.fn()} onChildPin={jest.fn()} />);
  return child.id;
}

describe('ChildOverviewCard — behaviour (M5-R02)', () => {
  it('shows the clock, open messes, stats and the missed mess', () => {
    const id = renderCard(
      { take_out: makeTakeOut(), active_events: [makeBehaviourEvent('chewing')], scene: 'chewing' },
      { taken_out: 4, chewing_resolved: 1 },
    );
    expect(screen.getByTestId(`child-pet-behaviour-${id}`)).toBeTruthy();
    expect(screen.getByText('• Mladiček mora ven ob 13:00 (zdrži ~2 h)')).toBeTruthy();
    expect(screen.getByText('• Pregrizen copat — počistiti do 13:30')).toBeTruthy();
    expect(screen.getByText('Zadnjih 7 dni: 4× peljal(a) ven · 1× pospravil(a) copat')).toBeTruthy();
    expect(screen.getByText('Luža')).toBeTruthy();
  });

  it('legacy pet / older server: nothing new', () => {
    const id = renderCard(undefined, { taken_out: 0, chewing_resolved: 0 });
    expect(screen.queryByTestId(`child-pet-behaviour-${id}`)).toBeNull();
    expect(screen.queryByTestId(`child-behaviour-stats-${id}`)).toBeNull();
  });
});
