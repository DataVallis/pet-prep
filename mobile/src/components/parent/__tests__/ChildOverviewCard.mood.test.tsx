/**
 * Mood label on the parent's »Pregled družine« pet card (David 2026-11-05, option A): the
 * server's `pet_state` (thirst refinement, open mess first) — never next to a lock status line.
 */
import { render, screen } from '@testing-library/react-native';

import ChildOverviewCard from '@/components/parent/ChildOverviewCard';
import { normalizePet } from '@/modules/family/family';
import { patchDashboardPet } from '@/modules/family/live';
import { makeBroadcast, makeFamilyPet, makeScoredChild, makeScoredDashboard, type FamilyPetFixture } from '@/test-utils/fixtures';

const TZ = 'Europe/Ljubljana';

function renderCard(overrides: Partial<FamilyPetFixture>) {
  const child = makeScoredChild();
  const pet = normalizePet(makeFamilyPet(overrides));
  render(<ChildOverviewCard child={child} pet={pet} timezone={TZ} onOpen={jest.fn()} onChildPin={jest.fn()} />);
  return child.id;
}

describe('ChildOverviewCard — mood label', () => {
  it('a dog that needs a walk', () => {
    const id = renderCard({ pet_state: 'low_energy' });
    expect(screen.getByTestId(`child-pet-mood-${id}-text`)).toHaveTextContent('Utrujen — rabi sprehod');
    expect(screen.getByTestId(`child-pet-mood-${id}`).props.accessibilityLabel).toBe('Utrujen — rabi sprehod');
    expect(screen.queryByTestId(`child-pet-status-${id}`)).toBeNull();
  });

  it('a cat with low play energy, a thirsty dog', () => {
    const cat = renderCard({ pet_state: 'low_energy', species: 'cat', breed_type: 'domestic_cat' });
    expect(screen.getByTestId(`child-pet-mood-${cat}-text`)).toHaveTextContent('Utrujena — rabi igro');
    screen.unmount();
    const dog = renderCard({ pet_state: 'hungry', metrics: { hunger: 90, thirst: 15, energy: 50, hygiene: 100 } });
    expect(screen.getByTestId(`child-pet-mood-${dog}-text`)).toHaveTextContent('Žejen');
  });

  it('a locked pet keeps only its status line', () => {
    const id = renderCard({ pet_state: 'sleeping', is_hard_stopped: true });
    expect(screen.getByTestId(`child-pet-status-${id}`)).toBeTruthy();
    expect(screen.queryByTestId(`child-pet-mood-${id}`)).toBeNull();
  });

  it('an older server without pet_state: no label', () => {
    const raw = makeFamilyPet();
    delete (raw as { pet_state?: unknown }).pet_state;
    const child = makeScoredChild();
    render(<ChildOverviewCard child={child} pet={normalizePet(raw)} timezone={TZ} onOpen={jest.fn()} onChildPin={jest.fn()} />);
    expect(screen.queryByTestId(`child-pet-mood-${child.id}`)).toBeNull();
  });

  it('a broadcast updates the cached pet state (live label)', () => {
    const data = makeScoredDashboard([makeScoredChild()], [makeFamilyPet({ id: 7, pet_state: 'idle' })]);
    const patched = patchDashboardPet(data as never, makeBroadcast({ pet_id: 7, pet_state: 'sleeping' }));
    expect(normalizePet(patched!.family!.pets[0]).pet_state).toBe('sleeping');
  });
});
