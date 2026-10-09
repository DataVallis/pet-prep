/**
 * M5-R06-08c: the parent's child card speaks about the pet shown — a cat gets "Muca …"
 * (status, light reasons, label, play meter), a dog in the same family keeps "Kuža …".
 */
import { render, screen } from '@testing-library/react-native';

import ChildOverviewCard from '@/components/parent/ChildOverviewCard';
import { normalizePet } from '@/modules/family/family';
import { makeFamilyPet, makeScoredChild } from '@/test-utils/fixtures';

const TZ = 'Europe/Ljubljana';

function renderCard(species: 'dog' | 'cat') {
  const base = makeScoredChild();
  const child = { ...base, traffic_light: { color: 'red' as const, reasons: ['fell_ill_today' as const] } };
  const pet = normalizePet({
    ...makeFamilyPet(),
    species,
    breed_type: species === 'cat' ? 'domestic_cat' : 'mutt',
    is_ill: true,
  } as ReturnType<typeof makeFamilyPet>);
  render(<ChildOverviewCard child={child} pet={pet} timezone={TZ} onOpen={jest.fn()} onChildPin={jest.fn()} />);
  return child.id;
}

describe('ChildOverviewCard — cat texts (M5-R06-08c)', () => {
  it('a cat: status, reason, label and the play meter', () => {
    const id = renderCard('cat');
    expect(screen.getByTestId(`child-pet-status-${id}`).props.children).toBe('Muca je pri veterinarju');
    expect(screen.getByText('• Muca je danes zbolela in je pri veterinarju.')).toBeTruthy();
    expect(screen.getByText('Muca')).toBeTruthy();
    expect(screen.getByText('Igra')).toBeTruthy();
    expect(screen.queryByText(/Kuža/)).toBeNull();
  });

  it('a dog keeps exactly the dog texts', () => {
    const id = renderCard('dog');
    expect(screen.getByTestId(`child-pet-status-${id}`).props.children).toBe('Kuža je pri veterinarju');
    expect(screen.getByText('• Kuža je danes zbolel in je pri veterinarju.')).toBeTruthy();
    expect(screen.getByText('Kuža')).toBeTruthy();
    expect(screen.getByText('Gibanje')).toBeTruthy();
  });
});
