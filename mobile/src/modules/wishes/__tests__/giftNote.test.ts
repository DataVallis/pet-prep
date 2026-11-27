import { giftOnTheWayText } from '@/modules/wishes/giftNote';
import { wishResultText } from '@/modules/wishes/useWishPurchase';
import { anyOnTheWay, giftsOnTheWay, readChildWishesState, readParentPetItems } from '@/modules/wishes/wishes';
import { t } from '@/i18n';

describe('»Darilo je na poti« (David 2026-11-27)', () => {
  const state = (items: unknown[]) => readChildWishesState({ open_count: 0, items, treats: { left: 0 } });

  it('lists only the bought things that are not there yet', () => {
    expect(giftsOnTheWay(null)).toEqual([]);
    expect(giftsOnTheWay(state([]))).toEqual([]);
    expect(giftsOnTheWay(state([{ key: 'bed', variant: 'dog_bed', media_ready: true }]))).toEqual([]);
    expect(
      giftsOnTheWay(
        state([
          { key: 'bed', variant: 'dog_bed', media_ready: false },
          { key: 'bowls', variant: 'dog_bowls', media_ready: true },
        ]),
      ),
    ).toEqual(['dog_bed']);
  });

  it('names one thing, not several, and never an unknown one', () => {
    expect(giftOnTheWayText([])).toBeNull();
    const one = giftOnTheWayText(['dog_bed']);
    expect(one).toBe(t('wishes:child.giftOnTheWay', { item: t('wishes:items.dog_bed') }));
    expect(one).toContain(t('wishes:items.dog_bed'));
    expect(giftOnTheWayText(['dog_bed', 'dog_rope'])).toBe(t('wishes:child.giftsOnTheWay'));
    expect(giftOnTheWayText(['hamster_wheel'])).toBe(t('wishes:child.giftsOnTheWay'));
  });

  it('the parent is told after the purchase that the thing takes a few minutes', () => {
    const items = (ready: boolean) => readParentPetItems([{ key: 'bed', variant: 'dog_bed', product_id: 'petprep_wish_bed', owned: true, media_ready: ready }], 'dog');
    expect(anyOnTheWay(items(false))).toBe(true);
    expect(anyOnTheWay(items(true))).toBe(false);
    // Not owned (catalogue row) is never »on its way«; treats count while they are preparing.
    expect(anyOnTheWay(readParentPetItems([{ key: 'bed', variant: 'dog_bed', owned: false, media_ready: false }], 'dog'))).toBe(false);
    expect(anyOnTheWay(readParentPetItems([{ key: 'treats', variant: 'dog_treats', owned: false, treats_left: 10, media_ready: false }], 'dog'))).toBe(true);

    expect(wishResultText({ kind: 'redeem', outcome: { status: 'assigned', items: items(false) } })).toBe(t('wishes:parent.result.assignedOnTheWay'));
    expect(wishResultText({ kind: 'redeem', outcome: { status: 'assigned', items: items(true) } })).toBe(t('wishes:parent.result.assigned'));
  });
});
