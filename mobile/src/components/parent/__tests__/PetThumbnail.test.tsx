/**
 * M4-03 app side, parent: the overview card shows the pet's reference image as a
 * thumbnail (no video player), a placeholder while pending / failed, and keeps the
 * image across re-signed URLs.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import ChildOverviewCard from '@/components/parent/ChildOverviewCard';
import PetThumbnail from '@/components/parent/PetThumbnail';
import { PET_MEDIA_STRINGS } from '@/components/PetMediaView';
import { normalizePet } from '@/modules/family/family';
import { EMPTY_PET_MEDIA, normalizePetMedia, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import { makeFamilyPet, makeMedia, makeScoredChild } from '@/test-utils/fixtures';
import { mockVideoPlayers, resetMockVideoPlayers } from '@/test-utils/videoPlayers';

const IMG = 'https://api.petprep.si/api/media/1?expires=1000&v=img&signature=a';
const IMG_RESIGNED = 'https://api.petprep.si/api/media/1?expires=2800&v=img&signature=b';
const IMG_NEW_FILE = 'https://api.petprep.si/api/media/1?expires=2800&v=img2&signature=c';

const withImage = (url: string | null, status: PetMediaInfo['status'] = 'partial'): PetMediaInfo => ({
  ...EMPTY_PET_MEDIA,
  status,
  referenceImageUrl: url,
});

beforeEach(() => resetMockVideoPlayers());

describe('PetThumbnail', () => {
  it('shows the reference image', () => {
    render(<PetThumbnail media={withImage(IMG)} />);
    expect(screen.getByTestId('pet-thumbnail-image').props.source).toEqual({ uri: IMG });
  });

  it('pending → soft placeholder labelled "Kuža se pripravlja…"; failed / disabled → paw placeholder', () => {
    const { rerender } = render(<PetThumbnail media={withImage(null, 'pending')} />);
    expect(screen.getByTestId('pet-thumbnail-pending').props.accessibilityLabel).toBe(PET_MEDIA_STRINGS.pending);
    rerender(<PetThumbnail media={withImage(null, 'failed')} />);
    expect(screen.getByTestId('pet-thumbnail-placeholder')).toBeTruthy();
    rerender(<PetThumbnail media={withImage(null, 'disabled')} />);
    expect(screen.getByTestId('pet-thumbnail-placeholder')).toBeTruthy();
  });

  it('keeps the loaded image when only the signature changes; a new file switches', () => {
    const { rerender } = render(<PetThumbnail media={withImage(IMG)} />);
    rerender(<PetThumbnail media={withImage(IMG_RESIGNED)} />);
    expect(screen.getByTestId('pet-thumbnail-image').props.source).toEqual({ uri: IMG });
    rerender(<PetThumbnail media={withImage(IMG_NEW_FILE)} />);
    expect(screen.getByTestId('pet-thumbnail-image').props.source).toEqual({ uri: IMG_NEW_FILE });
  });

  it('expired image → the newest URL; that failing too → placeholder', () => {
    const { rerender } = render(<PetThumbnail media={withImage(IMG)} />);
    rerender(<PetThumbnail media={withImage(IMG_RESIGNED)} />);
    fireEvent(screen.getByTestId('pet-thumbnail-image'), 'error');
    expect(screen.getByTestId('pet-thumbnail-image').props.source).toEqual({ uri: IMG_RESIGNED });
    fireEvent(screen.getByTestId('pet-thumbnail-image'), 'error');
    expect(screen.getByTestId('pet-thumbnail-placeholder')).toBeTruthy();
  });
});

describe('ChildOverviewCard pet thumbnail', () => {
  const child = makeScoredChild();

  it('renders the pet image in the pet block, without any video player', () => {
    const pet = normalizePet(
      makeFamilyPet({ id: 7, media: makeMedia({ status: 'ready', reference_image_url: IMG, videos: { idle: IMG_NEW_FILE } }) }),
    );
    render(<ChildOverviewCard child={child} pet={pet} timezone="Europe/Ljubljana" onOpen={jest.fn()} onChildPin={jest.fn()} />);
    expect(screen.getByTestId(`child-pet-thumb-${child.id}-image`).props.source).toEqual({ uri: IMG });
    expect(mockVideoPlayers).toHaveLength(0);
  });

  it('pending media → placeholder thumbnail', () => {
    const pet = normalizePet(makeFamilyPet({ id: 7, media: makeMedia({ status: 'pending' }) }));
    render(<ChildOverviewCard child={child} pet={pet} timezone="Europe/Ljubljana" onOpen={jest.fn()} onChildPin={jest.fn()} />);
    expect(screen.getByTestId(`child-pet-thumb-${child.id}-pending`)).toBeTruthy();
  });

  it('normalizePetMedia reads the dashboard pet media', () => {
    const raw = makeFamilyPet({ media: makeMedia({ status: 'partial', reference_image_url: IMG }) });
    expect(normalizePetMedia(raw.media).referenceImageUrl).toBe(IMG);
  });
});
