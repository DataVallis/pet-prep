/**
 * M6-W03 designs (David 2026-11-03): readers, request / redeem payloads, the parent's design
 * display, pending purchases keep the choice; the treat answer's clip; `item_ready` push routing;
 * `item_media_ready` refreshes the wish lists.
 */
import { QueryClient } from '@tanstack/react-query';

import { CHILD_WISHES_KEY, PARENT_WISHES_KEY, refreshesWishes } from '@/modules/wishes/keys';
import { memoryPendingStore, pendingPurchasesOf, savePendingPurchase, setPendingPurchaseStoreForTests } from '@/modules/wishes/pendingPurchases';
import { redeemWithRetry, type RedeemDeps } from '@/modules/wishes/redeem';
import {
  applyWishCreated,
  defaultDesigns,
  designChoiceFor,
  designKeysFor,
  designOf,
  needsDesign,
  readChildWishes,
  readDesigns,
  readParentWishes,
  treatClipUrl,
  wishDesign,
  wishRequestBody,
} from '@/modules/wishes/wishes';
import { isUrgentPush, parsePushData } from '@/modules/push/pushData';
import { routePushTap } from '@/modules/push/pushRouting';
import { childPetKey } from '@/hooks/queries/useChildPet';
import { applyParentBroadcast } from '@/modules/family/live';
import { applyBroadcastToCache } from '@/hooks/queries/useChildPet';
import { makeBroadcast } from '@/test-utils/fixtures';

const DESIGNS = [
  { key: 'bed_round', name: 'Okrogla', image: 'https://api.petprep.si/d/1.png' },
  { key: 'bed_box', name: 'Škatla', image: null },
  { key: 'bed_mat', name: '', image: 'https://api.petprep.si/d/3.png' },
];

describe('readDesigns', () => {
  it('reads designs, keeps image null (placeholder), drops rows without a key / duplicates', () => {
    expect(readDesigns([...DESIGNS, { name: 'x' }, { key: 'bed_round', name: 'dup' }, 'nope'])).toEqual([
      { key: 'bed_round', name: 'Okrogla', image: 'https://api.petprep.si/d/1.png' },
      { key: 'bed_box', name: 'Škatla', image: null },
      { key: 'bed_mat', name: '', image: 'https://api.petprep.si/d/3.png' },
    ]);
    expect(readDesigns(undefined)).toEqual([]);
  });

  it('child catalogue: designs + owned design; treats never have designs; older server → none', () => {
    const data = readChildWishes({
      pet_id: 1,
      species: 'dog',
      items: [
        { key: 'bed', variant: 'dog_bed', status: 'owned', media_ready: true, designs: DESIGNS, design: 'bed_box' },
        { key: 'treats', variant: 'dog_treats', status: 'available', treats_left: 0, designs: DESIGNS },
        { key: 'bowls', variant: 'dog_bowls', status: 'available' },
      ],
    });
    expect(data?.items[0].designs).toHaveLength(3);
    expect(data?.items[0].design).toBe('bed_box');
    expect(data?.items[1].designs).toEqual([]);
    expect(data?.items[2]).toMatchObject({ designs: [], design: null });
    expect(needsDesign(data!.items[0])).toBe(true);
    expect(needsDesign(data!.items[2])).toBe(false);
    expect(designOf(data!.items[0].designs, 'bed_box')?.name).toBe('Škatla');
    expect(designOf(data!.items[0].designs, null)).toBeNull();
  });

  it('a sent wish remembers its design', () => {
    const data = readChildWishes({ pet_id: 1, items: [{ key: 'bed', variant: 'dog_bed', status: 'available', designs: DESIGNS }] })!;
    expect(applyWishCreated(data, 'bed', 40, 2, 'bed_mat').items[0]).toMatchObject({ status: 'requested', design: 'bed_mat' });
  });
});

describe('request payloads', () => {
  it('»Prosi starša« sends the design only when one was chosen', () => {
    expect(wishRequestBody('bed', 'bed_round')).toEqual({ item_key: 'bed', design: 'bed_round' });
    expect(wishRequestBody('treats', null)).toEqual({ item_key: 'treats' });
  });

  it('redeem: one item → design; the bundle → designs per item; nothing chosen → nothing', () => {
    expect(designChoiceFor('petprep_wish_bed', { bed: 'bed_round' })).toEqual({ design: 'bed_round' });
    expect(designChoiceFor('petprep_wish_bundle_welcome', { bed: 'bed_round', bowls: 'bowls_steel', toy_1: 'rope_red', toy_2: 'x' })).toEqual({
      designs: { bed: 'bed_round', bowls: 'bowls_steel', toy_1: 'rope_red' },
    });
    expect(designChoiceFor('petprep_wish_bed', {})).toEqual({});
    expect(designChoiceFor('petprep_wish_treats_10', { treats: 'x' })).toEqual({ design: 'x' });
  });

  it('design keys + defaults: only items with designs; a wish design wins, else the first', () => {
    const items = [
      { key: 'bed' as const, designs: readDesigns(DESIGNS) },
      { key: 'bowls' as const, designs: readDesigns([{ key: 'b1', name: 'B1' }, { key: 'b2', name: 'B2' }]) },
      { key: 'toy_1' as const, designs: [] },
    ];
    expect(designKeysFor('petprep_wish_bundle_welcome', items)).toEqual(['bed', 'bowls']);
    expect(designKeysFor('petprep_wish_toy_1', items)).toEqual([]);
    expect(defaultDesigns(['bed', 'bowls'], items, { bowls: 'b2' })).toEqual({ bed: 'bed_round', bowls: 'b2' });
    expect(defaultDesigns(['bowls'], items, { bowls: 'gone' })).toEqual({ bowls: 'b1' });
  });

  it('redeemWithRetry sends the choice with every attempt', async () => {
    const redeem = jest.fn().mockResolvedValue({ status: 'assigned', items: [] });
    const deps: RedeemDeps = { redeem, sleep: () => Promise.resolve() };
    await redeemWithRetry({ petId: 12, productId: 'petprep_wish_bed', wishId: 31, transactionId: 'tx', choice: { design: 'bed_box' } }, deps);
    expect(redeem).toHaveBeenCalledWith(12, { product_id: 'petprep_wish_bed', wish_id: 31, design: 'bed_box' }, 'tx');
  });

  it('a pending purchase keeps its (valid) design choice', async () => {
    const store = memoryPendingStore();
    setPendingPurchaseStoreForTests(store);
    await savePendingPurchase({ parentId: 1, petId: 12, wishId: null, productId: 'petprep_wish_bundle_welcome', transactionId: 'tx-1', species: 'dog', choice: { designs: { bed: 'bed_round' } } });
    expect((await pendingPurchasesOf(1))[0].choice).toEqual({ designs: { bed: 'bed_round' } });
    // A tampered entry keeps only well-formed parts.
    store.data.set('petprep.wish_pending_purchases', JSON.stringify([{ parentId: 1, petId: 12, wishId: null, productId: 'p', transactionId: 'tx-2', species: 'dog', savedAt: Date.now(), choice: { design: 5, designs: { bed: 'ok', nope: 'x' } } }]));
    expect((await pendingPurchasesOf(1))[0].choice).toEqual({ designs: { bed: 'ok' } });
    setPendingPurchaseStoreForTests(null);
  });
});

describe('parent display', () => {
  const raw = {
    open_count: 1,
    wishes: [{ id: 31, pet_id: 12, species: 'dog', child_id: 7, child_name: 'Ana', item_key: 'bed', variant: 'dog_bed', product_id: 'petprep_wish_bed', design: 'bed_box' }],
    pets: [{ pet_id: 12, species: 'dog', items: [{ key: 'bed', variant: 'dog_bed', product_id: 'petprep_wish_bed', owned: false, designs: DESIGNS }] }],
  };

  it('a wish shows its design from the pet\'s item designs; unknown key → nameless placeholder; none → null', () => {
    const data = readParentWishes(raw)!;
    expect(wishDesign(data.wishes[0], data.pets)).toEqual({ key: 'bed_box', name: 'Škatla', image: null });
    expect(wishDesign({ ...data.wishes[0], design: 'gone' }, data.pets)).toEqual({ key: 'gone', name: '', image: null });
    expect(wishDesign({ ...data.wishes[0], design: null }, data.pets)).toBeNull();
    // An older server: no design, no designs.
    const old = readParentWishes({ ...raw, wishes: [{ ...raw.wishes[0], design: undefined }] })!;
    expect(old.wishes[0]).toMatchObject({ design: null, designs: [] });
  });
});

describe('treat clip + push + realtime', () => {
  it('the treat answer media: items.treats.video first, older shapes still work', () => {
    expect(treatClipUrl({ items: { treats: { video: 'https://x/t.mp4' } }, video_url: 'https://x/old.mp4' })).toBe('https://x/t.mp4');
    expect(treatClipUrl({ video_url: 'https://x/old.mp4' })).toBe('https://x/old.mp4');
    expect(treatClipUrl(null)).toBeNull();
  });

  it('item_ready: a child push with the pet id, never urgent; the tap refetches the pet and the catalogue', () => {
    expect(parsePushData({ type: 'item_ready', pet_id: 12 })).toEqual({ type: 'item_ready', petId: 12 });
    expect(parsePushData({ type: 'item_ready' })).toBeNull();
    expect(isUrgentPush('item_ready')).toBe(false);
    const client = new QueryClient();
    const spy = jest.spyOn(client, 'invalidateQueries');
    expect(routePushTap({ type: 'item_ready', pet_id: 12 }, 'child', client)).toEqual({ type: 'item_ready', petId: 12 });
    expect(spy).toHaveBeenCalledWith({ queryKey: childPetKey });
    expect(spy).toHaveBeenCalledWith({ queryKey: CHILD_WISHES_KEY });
  });

  it('item_media_ready refetches the wish lists (child + parent); other events do not', () => {
    expect(refreshesWishes('item_media_ready')).toBe(true);
    expect(refreshesWishes('metric_changed')).toBe(false);
    expect(refreshesWishes(null)).toBe(false);
    const child = new QueryClient();
    const childSpy = jest.spyOn(child, 'invalidateQueries');
    applyBroadcastToCache(child, makeBroadcast({ event_type: 'item_media_ready' }));
    expect(childSpy).toHaveBeenCalledWith({ queryKey: CHILD_WISHES_KEY });
    const parent = new QueryClient();
    const parentSpy = jest.spyOn(parent, 'invalidateQueries');
    applyParentBroadcast(parent, makeBroadcast({ event_type: 'item_media_ready' }));
    expect(parentSpy).toHaveBeenCalledWith({ queryKey: PARENT_WISHES_KEY });
    parentSpy.mockClear();
    applyParentBroadcast(parent, makeBroadcast({ event_type: 'metric_changed' }));
    expect(parentSpy).not.toHaveBeenCalledWith({ queryKey: PARENT_WISHES_KEY });
  });
});
