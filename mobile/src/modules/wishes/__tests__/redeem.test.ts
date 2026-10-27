/** M6-W05 redeem after a purchase: 409 no_credit → 3 retries with backoff → pending. */
import { ApiError } from '@/api/client';
import { REDEEM_BACKOFF_MS, REDEEM_RETRIES, redeemPurchase, redeemWithRetry, retryPendingPurchases } from '@/modules/wishes/redeem';
import { pendingPurchasesOf, savePendingPurchase } from '@/modules/wishes/pendingPurchases';

const ok = { status: 'assigned', items: [{ key: 'bed', variant: 'dog_bed', product_id: 'petprep_wish_bed', owned: true, media_ready: false }], open_count: 0 };
const noCredit = () => new ApiError('no credit', 409, { reason: 'no_credit' });
const req = { petId: 12, productId: 'petprep_wish_bed', wishId: 31, transactionId: 'tx-1' };

describe('redeemWithRetry', () => {
  it('sends product, wish and the transaction id as Idempotency-Key', async () => {
    const redeem = jest.fn().mockResolvedValue(ok);
    const sleep = jest.fn().mockResolvedValue(undefined);
    const out = await redeemWithRetry(req, { redeem, sleep });
    expect(redeem).toHaveBeenCalledWith(12, { product_id: 'petprep_wish_bed', wish_id: 31 }, 'tx-1');
    expect(out).toMatchObject({ status: 'assigned' });
    expect(out.status === 'assigned' && out.items[0].owned).toBe(true);
    expect(sleep).not.toHaveBeenCalled();
  });

  it('retries 409 with backoff and succeeds', async () => {
    const redeem = jest.fn().mockRejectedValueOnce(noCredit()).mockRejectedValueOnce(noCredit()).mockResolvedValue(ok);
    const sleep = jest.fn().mockResolvedValue(undefined);
    const out = await redeemWithRetry(req, { redeem, sleep });
    expect(out.status).toBe('assigned');
    expect(sleep.mock.calls.map((c) => c[0])).toEqual([REDEEM_BACKOFF_MS[0], REDEEM_BACKOFF_MS[1]]);
  });

  it('after 3 retries of 409 → pending (»Nakup je zabeležen …«)', async () => {
    const redeem = jest.fn().mockRejectedValue(noCredit());
    const sleep = jest.fn().mockResolvedValue(undefined);
    const out = await redeemWithRetry(req, { redeem, sleep });
    expect(out).toEqual({ status: 'pending' });
    expect(redeem).toHaveBeenCalledTimes(REDEEM_RETRIES + 1);
    expect(sleep).toHaveBeenCalledTimes(REDEEM_RETRIES);
  });

  it('a 422 is not retried and keeps its reason', async () => {
    const sleep = jest.fn().mockResolvedValue(undefined);
    const refused = await redeemWithRetry(req, { redeem: jest.fn().mockRejectedValue(new ApiError('x', 422, { reason: 'already_owned' })), sleep });
    expect(refused).toEqual({ status: 'refused', reason: 'already_owned' });
    expect(sleep).not.toHaveBeenCalled();
  });

  it('QA #416 M2: network errors / timeouts are retried with backoff when a transaction id makes it idempotent', async () => {
    const redeem = jest.fn().mockRejectedValueOnce(new TypeError('Network request failed')).mockRejectedValueOnce(Object.assign(new Error('t'), { name: 'TimeoutError' })).mockResolvedValue(ok);
    const sleep = jest.fn().mockResolvedValue(undefined);
    expect((await redeemWithRetry(req, { redeem, sleep })).status).toBe('assigned');
    expect(sleep.mock.calls.map((c) => c[0])).toEqual([REDEEM_BACKOFF_MS[0], REDEEM_BACKOFF_MS[1]]);
    const always = jest.fn().mockRejectedValue(new TypeError('Network request failed'));
    expect(await redeemWithRetry(req, { redeem: always, sleep: jest.fn().mockResolvedValue(undefined) })).toEqual({ status: 'pending' });
    expect(always).toHaveBeenCalledTimes(REDEEM_RETRIES + 1);
  });

  it('without a transaction id a network error is NOT retried (not idempotent)', async () => {
    const redeem = jest.fn().mockRejectedValue(new TypeError('Network request failed'));
    const sleep = jest.fn().mockResolvedValue(undefined);
    expect(await redeemWithRetry({ ...req, transactionId: null }, { redeem, sleep })).toEqual({ status: 'pending' });
    expect(redeem).toHaveBeenCalledTimes(1);
  });
});

describe('pending purchases on the device (QA #416 M2)', () => {
  const noSleep = jest.fn().mockResolvedValue(undefined);

  it('a purchase that stays pending is kept; an assigned one is forgotten', async () => {
    await redeemPurchase({ ...req, parentId: 1, species: 'dog' }, { redeem: jest.fn().mockRejectedValue(noCredit()), sleep: noSleep });
    expect(await pendingPurchasesOf(1)).toEqual([expect.objectContaining({ petId: 12, wishId: 31, productId: 'petprep_wish_bed', transactionId: 'tx-1' })]);
    await redeemPurchase({ ...req, parentId: 1, species: 'dog' }, { redeem: jest.fn().mockResolvedValue(ok), sleep: noSleep });
    expect(await pendingPurchasesOf(1)).toEqual([]);
  });

  it('retryPendingPurchases redeems for the SAME pet and wish, only for this parent', async () => {
    await savePendingPurchase({ parentId: 1, petId: 12, wishId: 31, productId: 'petprep_wish_bed', transactionId: 'tx-1', species: 'dog' });
    await savePendingPurchase({ parentId: 2, petId: 50, wishId: null, productId: 'petprep_wish_bed', transactionId: 'tx-2', species: 'cat' });
    const redeem = jest.fn().mockResolvedValue(ok);
    await expect(retryPendingPurchases(1, { redeem, sleep: noSleep })).resolves.toBe(1);
    expect(redeem).toHaveBeenCalledTimes(1);
    expect(redeem).toHaveBeenCalledWith(12, { product_id: 'petprep_wish_bed', wish_id: 31 }, 'tx-1');
    expect(await pendingPurchasesOf(1)).toEqual([]);
    expect(await pendingPurchasesOf(2)).toHaveLength(1);
  });

  it('a refused retry is forgotten (credit → »Komu?«); a still pending one stays', async () => {
    await savePendingPurchase({ parentId: 1, petId: 12, wishId: 31, productId: 'petprep_wish_bed', transactionId: 'tx-a', species: 'dog' });
    await savePendingPurchase({ parentId: 1, petId: 12, wishId: null, productId: 'petprep_wish_bowls', transactionId: 'tx-b', species: 'dog' });
    const redeem = jest.fn((_pet: number, body: { product_id: string }) =>
      body.product_id === 'petprep_wish_bed' ? Promise.reject(new ApiError('x', 422, { reason: 'already_owned' })) : Promise.reject(noCredit()),
    );
    await expect(retryPendingPurchases(1, { redeem: redeem as never, sleep: noSleep })).resolves.toBe(1);
    expect((await pendingPurchasesOf(1)).map((e) => e.transactionId)).toEqual(['tx-b']);
  });
});
