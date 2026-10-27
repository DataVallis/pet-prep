/** M6-W04 / W05 pure rules: readers, child row states, treat errors, bundle offer, old-server hiding. */
import { ApiError } from '@/api/client';
import {
  applyWishCreated,
  childRowState,
  isFeatureMissing,
  keysOfProduct,
  parentItemState,
  productIdsOf,
  purchaseStillPossible,
  readChildWishes,
  readChildWishesState,
  readParentWishes,
  readTreatResult,
  readWishesOpenCount,
  showBundleOffer,
  showTreatButton,
  treatFailureOf,
  type ChildWishItem,
} from '@/modules/wishes/wishes';
import { normalizeChildState } from '@/modules/childPet/childPetView';
import { makeLiveChildState } from '@/test-utils/fixtures';

const CATALOGUE = {
  pet_id: 12,
  species: 'dog',
  open_count: 1,
  open_limit: 3,
  can_wish: true,
  items: [
    { key: 'bed', kind: 'durable', variant: 'dog_bed', status: 'available' },
    { key: 'bowls', kind: 'durable', variant: 'dog_bowls', status: 'requested', wish_id: 31, requested_at: '2026-10-27T08:00:00Z' },
    { key: 'toy_1', kind: 'durable', variant: 'dog_rope', status: 'declined_recently', retry_at: '2026-11-03T08:00:00Z' },
    { key: 'toy_2', kind: 'durable', variant: 'dog_squeaky', status: 'owned', media_ready: false },
    { key: 'treats', kind: 'consumable', variant: 'dog_treats', status: 'available', treats_left: 0 },
    { key: 'welcome_bundle', kind: 'bundle', variant: 'x', status: 'available' },
  ],
};

const NOW = Date.parse('2026-10-28T10:00:00Z');
const item = (key: string) => readChildWishes(CATALOGUE)!.items.find((i) => i.key === key) as ChildWishItem;

describe('readChildWishes + childRowState', () => {
  const data = readChildWishes(CATALOGUE)!;

  it('reads the contract example; never lists the bundle', () => {
    expect(data.items.map((i) => i.key)).toEqual(['bed', 'bowls', 'toy_1', 'toy_2', 'treats']);
    expect(data.can_wish).toBe(true);
    expect(item('treats').treats_left).toBe(0);
    expect(item('bed').treats_left).toBeNull();
  });

  it('maps every status to a row state', () => {
    expect(childRowState(item('bed'), data, NOW)).toBe('ask');
    expect(childRowState(item('bowls'), data, NOW)).toBe('requested');
    expect(childRowState(item('toy_1'), data, NOW)).toBe('declined');
    expect(childRowState(item('toy_2'), data, NOW)).toBe('on_the_way');
    expect(childRowState({ ...item('toy_2'), media_ready: true }, data, NOW)).toBe('owned');
    expect(childRowState(item('treats'), data, NOW)).toBe('ask');
  });

  it('declined: can ask again after retry_at', () => {
    expect(childRowState(item('toy_1'), data, Date.parse('2026-11-03T08:00:01Z'))).toBe('ask');
  });

  it('3 open wishes → limit; treats with more than 2 left → no button; blocked pet → blocked', () => {
    expect(childRowState(item('bed'), { ...data, open_count: 3 }, NOW)).toBe('limit');
    expect(childRowState({ ...item('treats'), treats_left: 3 }, data, NOW)).toBe('treats_left');
    expect(childRowState({ ...item('treats'), treats_left: 2 }, data, NOW)).toBe('ask');
    const blocked = readChildWishes({ ...CATALOGUE, can_wish: false, blocked_reason: 'game_over' })!;
    expect(blocked.blocked_reason).toBe('game_over');
    expect(childRowState(item('bed'), blocked, NOW)).toBe('blocked');
    // Owned / requested stay visible even when blocked.
    expect(childRowState(item('bowls'), blocked, NOW)).toBe('requested');
    // An unborn pet / unsigned contract (server `pet_not_started`) blocks wishing too.
    const notStarted = readChildWishes({ ...CATALOGUE, can_wish: false, blocked_reason: 'pet_not_started' })!;
    expect(notStarted.blocked_reason).toBe('pet_not_started');
    expect(notStarted.can_wish).toBe(false);
    expect(childRowState(item('bed'), notStarted, NOW)).toBe('blocked');
  });

  it('a cat gets cat variants when the server variant is unknown', () => {
    const cat = readChildWishes({ ...CATALOGUE, species: 'cat', items: [{ key: 'bed', status: 'available', variant: 'weird' }] })!;
    expect(cat.items[0].variant).toBe('cat_basket');
  });

  it('applyWishCreated marks the row and takes the server count', () => {
    const next = applyWishCreated(data, 'bed', 40, 2);
    expect(next.items.find((i) => i.key === 'bed')).toMatchObject({ status: 'requested', wish_id: 40 });
    expect(next.open_count).toBe(2);
  });
});

describe('child pet state `wishes`', () => {
  it('older server → null: no treat button', () => {
    const view = normalizeChildState(makeLiveChildState());
    expect(view.wishes).toBeNull();
    expect(showTreatButton(view.wishes, false)).toBe(false);
  });

  it('treats left → button (not while locked); none left → no button', () => {
    const raw = { ...makeLiveChildState(), wishes: { open_count: 1, items: [{ key: 'bed', variant: 'dog_bed', media_ready: false }], treats: { left: 8, given_today: 0, daily_limit: 2 } } };
    const view = normalizeChildState(raw as never);
    expect(view.wishes?.treats.left).toBe(8);
    expect(showTreatButton(view.wishes, false)).toBe(true);
    expect(showTreatButton(view.wishes, true)).toBe(false);
    expect(showTreatButton(readChildWishesState({ treats: { left: 0 } }), false)).toBe(false);
  });
});

describe('treat answers', () => {
  it('reads the 200 body and an optional clip', () => {
    expect(readTreatResult({ treats_left: 7, given_today: 1, daily_limit: 2, media: null })).toEqual({ treats_left: 7, given_today: 1, daily_limit: 2, clipUrl: null });
    expect(readTreatResult({ treats_left: 7, media: { video_url: 'https://x/treat.mp4' } })?.clipUrl).toBe('https://x/treat.mp4');
    expect(readTreatResult({})).toBeNull();
  });

  it('maps the 422 reasons; 423 is not a treat failure', () => {
    expect(treatFailureOf(new ApiError('x', 422, { reason: 'treat_limit_today', next_at: '2026-10-28T00:00:00+01:00' }))).toBe('treat_limit_today');
    expect(treatFailureOf(new ApiError('x', 422, { reason: 'no_treats' }))).toBe('no_treats');
    expect(treatFailureOf(new ApiError('x', 422, { reason: 'pet_sleeping' }))).toBe('pet_sleeping');
    expect(treatFailureOf(new ApiError('x', 423, { reason: 'hard_stopped' }))).toBeNull();
  });
});

describe('old-server hiding', () => {
  it('a 404 without reason = the route is missing; a 404 with a reason is a real answer', () => {
    expect(isFeatureMissing(new ApiError('Not Found', 404, { message: 'Not Found' }))).toBe(true);
    expect(isFeatureMissing(new ApiError('x', 404, { reason: 'no_pet' }))).toBe(false);
    expect(isFeatureMissing(new ApiError('x', 500, {}))).toBe(false);
    expect(isFeatureMissing(new Error('offline'))).toBe(false);
  });

  it('dashboard without wishes_open_count → null (no row)', () => {
    expect(readWishesOpenCount({ timezone: 'x' })).toBeNull();
    expect(readWishesOpenCount({ wishes_open_count: 2 })).toBe(2);
    expect(readWishesOpenCount(undefined)).toBeNull();
  });
});

const PARENT = {
  open_count: 2,
  wishes: [
    { id: 31, pet_id: 12, pet_name: 'Suki', species: 'dog', child_id: 7, child_name: 'Ana', item_key: 'bowls', variant: 'dog_bowls', product_id: 'petprep_wish_bowls', status: 'open', created_at: '2026-10-27T08:00:00Z', bundle_product_id: 'petprep_wish_bundle_welcome' },
    { id: 32, pet_id: 12, pet_name: null, species: 'dog', child_id: 7, child_name: 'Ana', item_key: 'treats', variant: 'dog_treats', product_id: 'petprep_wish_treats_10', status: 'open', created_at: '2026-10-27T09:00:00Z', bundle_product_id: null },
  ],
  pets: [
    { pet_id: 12, pet_name: 'Suki', species: 'dog', bundle_eligible: true, items: [
      { key: 'bed', variant: 'dog_bed', product_id: 'petprep_wish_bed', owned: false, media_ready: false },
      { key: 'toy_2', variant: 'dog_squeaky', product_id: 'petprep_wish_toy_2', owned: true, media_ready: false },
      { key: 'treats', variant: 'dog_treats', product_id: 'petprep_wish_treats_10', treats_left: 0 },
    ] },
  ],
  unassigned_credits: [{ product_id: 'petprep_wish_bed', count: 1, item_key: 'bed' }, { product_id: 'store_bed_v2', count: 2, item_key: 'bed' }, { product_id: 'petprep_wish_toy_1', count: 1 }, { product_id: 'other', count: 1 }],
};

describe('parent list', () => {
  const data = readParentWishes(PARENT)!;

  it('reads wishes, pets and credits by the server item_key (QA #416 m13, fallback = known ids)', () => {
    expect(data.wishes.map((w) => w.id)).toEqual([31, 32]);
    expect(data.unassigned_credits).toEqual([
      { product_id: 'petprep_wish_bed', count: 1, item_key: 'bed' },
      // A product id the app doesn't know is not filtered out — the server's key labels it.
      { product_id: 'store_bed_v2', count: 2, item_key: 'bed' },
      { product_id: 'petprep_wish_toy_1', count: 1, item_key: 'toy_1' },
      { product_id: 'other', count: 1, item_key: null },
    ]);
    // Older payload without `bundle_product_id` on the pet → the known bundle id.
    expect(data.pets[0].bundle_product_id).toBe('petprep_wish_bundle_welcome');
    expect(data.pets[0].items.map(parentItemState)).toEqual(['buy', 'on_the_way', 'buy']);
  });

  it('bundle offer only with bundle_product_id AND a store product', () => {
    const has = (id: string) => id === 'petprep_wish_bundle_welcome';
    expect(showBundleOffer(data.wishes[0], has)).toBe(true);
    expect(showBundleOffer(data.wishes[0], () => false)).toBe(false);
    expect(showBundleOffer(data.wishes[1], has)).toBe(false);
  });

  it('asks the store for every product it may show', () => {
    expect(productIdsOf(data)).toEqual(['petprep_wish_bed', 'petprep_wish_bowls', 'petprep_wish_bundle_welcome', 'petprep_wish_toy_2', 'petprep_wish_treats_10']);
    expect(productIdsOf(null)).toEqual([]);
  });

  it('pets[].bundle_product_id from the server is used; not eligible → null', () => {
    const custom = readParentWishes({ ...PARENT, pets: [{ ...PARENT.pets[0], bundle_product_id: 'store_bundle_v2' }, { pet_id: 13, species: 'cat', bundle_eligible: false, bundle_product_id: 'x', items: [] }] })!;
    expect(custom.pets.map((p) => p.bundle_product_id)).toEqual(['store_bundle_v2', null]);
    expect(productIdsOf(custom)).toContain('store_bundle_v2');
  });

  it('QA #416 m4: purchaseStillPossible on fresh data', () => {
    expect(purchaseStillPossible(data, { petId: 12, productId: 'petprep_wish_bowls', wishId: 31 })).toBe('ok');
    expect(purchaseStillPossible(data, { petId: 12, productId: 'petprep_wish_bowls', wishId: 99 })).toBe('wish_gone');
    expect(purchaseStillPossible(data, { petId: 77, productId: 'petprep_wish_bed', wishId: null })).toBe('pet_gone');
    expect(purchaseStillPossible(data, { petId: 12, productId: 'petprep_wish_toy_2', wishId: null })).toBe('owned');
    expect(purchaseStillPossible(data, { petId: 12, productId: 'petprep_wish_treats_10', wishId: null })).toBe('ok');
    expect(purchaseStillPossible(data, { petId: 12, productId: 'petprep_wish_bundle_welcome', wishId: null })).toBe('ok');
    const notEligible = { ...data, pets: [{ ...data.pets[0], bundle_eligible: false }] };
    expect(purchaseStillPossible(notEligible, { petId: 12, productId: 'petprep_wish_bundle_welcome', wishId: 31 })).toBe('bundle_not_eligible');
  });

  it('the bundle covers bed + bowls + toy_1', () => {
    expect(keysOfProduct('petprep_wish_bundle_welcome')).toEqual(['bed', 'bowls', 'toy_1']);
    expect(keysOfProduct('petprep_challenge_12w')).toEqual([]);
  });
});
