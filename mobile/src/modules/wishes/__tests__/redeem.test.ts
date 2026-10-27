/** M6-W05 redeem after a purchase: 409 no_credit → 3 retries with backoff → pending. */
import { ApiError } from '@/api/client';
import { REDEEM_BACKOFF_MS, REDEEM_RETRIES, redeemWithRetry } from '@/modules/wishes/redeem';

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

  it('a 422 is not retried and keeps its reason; offline → pending', async () => {
    const sleep = jest.fn().mockResolvedValue(undefined);
    const refused = await redeemWithRetry(req, { redeem: jest.fn().mockRejectedValue(new ApiError('x', 422, { reason: 'already_owned' })), sleep });
    expect(refused).toEqual({ status: 'refused', reason: 'already_owned' });
    const offline = await redeemWithRetry(req, { redeem: jest.fn().mockRejectedValue(new TypeError('Network request failed')), sleep });
    expect(offline).toEqual({ status: 'pending' });
    expect(sleep).not.toHaveBeenCalled();
  });
});
