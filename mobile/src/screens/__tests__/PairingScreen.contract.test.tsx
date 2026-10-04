/**
 * M1-07b: after pairing, the child's signature goes to POST /api/child/contract and
 * the server's (now born) pet lands in the session store.
 */
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import PairingScreen, { CONTRACT_STRINGS } from '@/screens/PairingScreen';
import { isAwaitingContract, useAppStore } from '@/store/appStore';
import { makeChildState } from '@/test-utils/fixtures';
import type { PairingResponse } from '@/types';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, pairChild: jest.fn(), signContract: jest.fn(), getChildPet: jest.fn(), logout: jest.fn() },
  };
});

const pairChild = api.pairChild as jest.Mock;
const signContract = api.signContract as jest.Mock;

const pairing: PairingResponse = {
  message: 'Paired',
  parent_id: 1,
  pet: {
    id: 7,
    breed_type: 'mutt',
    hunger_level: 100,
    thirst_level: 100,
    energy_level: 100,
    hygiene_level: 100,
    born_at: null,
    awaiting_contract: true,
    is_active: true,
    pet_dna: null,
    current_video_url: null,
  },
};

async function pairWithPin() {
  render(<PairingScreen initialStep="pin" />);
  const boxes = screen.UNSAFE_getAllByProps({ maxLength: 1, keyboardType: 'number-pad' });
  boxes.forEach((box, i) => fireEvent.changeText(box, String(i + 1)));
  fireEvent.press(screen.getByText('Seznani se'));
  await screen.findByText(CONTRACT_STRINGS.padHint);
}

/** Draw a stroke on the signature pad: (10,10) → (40,40) → (80,20). */
function sign() {
  const pad = screen.getByTestId('signature-pad');
  fireEvent(pad, 'responderGrant', { nativeEvent: { locationX: 10, locationY: 10 } });
  fireEvent(pad, 'responderMove', { nativeEvent: { locationX: 40, locationY: 40 } });
  fireEvent(pad, 'responderMove', { nativeEvent: { locationX: 80, locationY: 20 } });
  fireEvent(pad, 'responderRelease', { nativeEvent: { locationX: 80, locationY: 20 } });
}

describe('PairingScreen contract (M1-07b)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({
      token: 'child-token',
      user: { id: 2, name: 'Otrok', email: 'c@x.si', role: 'child' },
      pet: null,
    });
    pairChild.mockResolvedValue(pairing);
  });

  it('accept is disabled until the child draws a signature', async () => {
    await pairWithPin();
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.accept));
    expect(signContract).not.toHaveBeenCalled();
  });

  it('signs → sends the svg_path → stores the born pet from the server state', async () => {
    let resolve: (value: unknown) => void = () => undefined;
    signContract.mockReturnValueOnce(new Promise((r) => (resolve = r)));
    await pairWithPin();
    sign();

    fireEvent.press(screen.getByText(CONTRACT_STRINGS.accept));
    expect(screen.getByTestId('contract-loading')).toBeTruthy();
    expect(signContract).toHaveBeenCalledWith({
      signature_format: 'svg_path',
      signature: 'M10 10 L40 40 L80 20',
    });

    await act(async () => resolve({ status: 'accepted', state: makeChildState() }));

    const { pet, pairingStatus } = useAppStore.getState();
    expect(pet?.born_at).toBe('2026-10-04T09:00:00+00:00');
    expect(pet?.awaiting_contract).toBe(false);
    expect(pet?.user_id).toBe(2);
    expect(isAwaitingContract(pet)).toBe(false);
    expect(pairingStatus).toBe('paired');
  });

  it('409 already signed → continues with the state from the body', async () => {
    signContract.mockRejectedValueOnce(
      new ApiError('The contract is already signed.', 409, { reason: 'contract_already_signed', state: makeChildState() }),
    );
    await pairWithPin();
    sign();
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.accept));

    await waitFor(() => expect(useAppStore.getState().pet?.born_at).toBe('2026-10-04T09:00:00+00:00'));
    expect(screen.queryByTestId('contract-error')).toBeNull();
  });

  it('422 → shows "Podpis ni veljaven" and clears the pad', async () => {
    signContract.mockRejectedValueOnce(new ApiError('invalid', 422, { errors: { signature: ['x'] } }));
    await pairWithPin();
    sign();
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.accept));

    expect(await screen.findByText(CONTRACT_STRINGS.invalid)).toBeTruthy();
    expect(useAppStore.getState().pet).toBeNull();
    // Pad cleared → accept disabled again.
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.accept));
    expect(signContract).toHaveBeenCalledTimes(1);
  });

  it('423 → shows the lock reason', async () => {
    signContract.mockRejectedValueOnce(new ApiError('locked', 423, { reason: 'hard_stopped', state: makeChildState() }));
    await pairWithPin();
    sign();
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.accept));

    expect(await screen.findByText(CONTRACT_STRINGS.locked.hard_stopped)).toBeTruthy();
    expect(useAppStore.getState().pet).toBeNull();
  });

  it('offline → retry button re-sends the same signature', async () => {
    signContract
      .mockRejectedValueOnce(new TypeError('Network request failed'))
      .mockResolvedValueOnce({ status: 'accepted', state: makeChildState() });
    await pairWithPin();
    sign();
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.accept));

    expect(await screen.findByText(CONTRACT_STRINGS.network)).toBeTruthy();
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.retry));

    await waitFor(() => expect(useAppStore.getState().pet?.born_at).not.toBeNull());
    expect(signContract).toHaveBeenCalledTimes(2);
    expect(signContract.mock.calls[1]).toEqual(signContract.mock.calls[0]);
  });
});
