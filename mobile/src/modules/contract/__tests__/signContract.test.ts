import { ApiError, api } from '@/api/client';
import { submitSignature } from '@/modules/contract/signContract';
import { makeChildState } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, signContract: jest.fn(), getChildPet: jest.fn() } };
});

const signContract = api.signContract as jest.Mock;
const getChildPet = api.getChildPet as jest.Mock;
const PATH = 'M10 10 L40 40';

describe('submitSignature', () => {
  beforeEach(() => jest.clearAllMocks());

  it('sends svg_path and returns signed with the state', async () => {
    const state = makeChildState();
    signContract.mockResolvedValueOnce({ status: 'accepted', state });

    await expect(submitSignature(PATH)).resolves.toEqual({ kind: 'signed', state });
    expect(signContract).toHaveBeenCalledWith({ signature_format: 'svg_path', signature: PATH });
  });

  it('409 → already_signed with the state from the body', async () => {
    const state = makeChildState();
    signContract.mockRejectedValueOnce(new ApiError('The contract is already signed.', 409, { reason: 'contract_already_signed', state }));

    await expect(submitSignature(PATH)).resolves.toEqual({ kind: 'already_signed', state });
    expect(getChildPet).not.toHaveBeenCalled();
  });

  it('409 without a state → fetches GET /api/child/pet', async () => {
    const state = makeChildState();
    signContract.mockRejectedValueOnce(new ApiError('Already signed', 409, { reason: 'contract_already_signed' }));
    getChildPet.mockResolvedValueOnce(state);

    await expect(submitSignature(PATH)).resolves.toEqual({ kind: 'already_signed', state });
  });

  it('422 → invalid', async () => {
    signContract.mockRejectedValueOnce(new ApiError('bad', 422, { errors: {} }));
    await expect(submitSignature(PATH)).resolves.toEqual({ kind: 'invalid' });
  });

  it('423 → locked with the reason', async () => {
    signContract.mockRejectedValueOnce(new ApiError('locked', 423, { reason: 'hard_stopped', state: makeChildState() }));
    await expect(submitSignature(PATH)).resolves.toEqual({ kind: 'locked', reason: 'hard_stopped' });
  });

  it('network error, 5xx and 429 → retryable', async () => {
    signContract.mockRejectedValueOnce(new TypeError('Network request failed'));
    await expect(submitSignature(PATH)).resolves.toEqual({ kind: 'retryable' });
    signContract.mockRejectedValueOnce(new ApiError('err', 503));
    await expect(submitSignature(PATH)).resolves.toEqual({ kind: 'retryable' });
    signContract.mockRejectedValueOnce(new ApiError('slow down', 429));
    await expect(submitSignature(PATH)).resolves.toEqual({ kind: 'retryable' });
  });

  it('other 4xx → failed', async () => {
    signContract.mockRejectedValueOnce(new ApiError('No pet yet.', 404, { reason: 'no_pet' }));
    await expect(submitSignature(PATH)).resolves.toEqual({ kind: 'failed', status: 404 });
  });
});
