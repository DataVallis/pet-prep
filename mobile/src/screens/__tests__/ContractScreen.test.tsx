/**
 * M1-07b: the child's signature goes to POST /api/child/contract and the server's
 * (now born / unlocked) pet lands in the session store.
 */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';
import type { QueryClient } from '@tanstack/react-query';

import { ApiError, api } from '@/api/client';
import ContractScreen, { CONTRACT_STRINGS } from '@/screens/ContractScreen';
import { isAwaitingContract, useAppStore } from '@/store/appStore';
import { makeChildState, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import { childPetKey } from '@/hooks/queries/useChildPet';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { maybeAskForPush } from '@/modules/push/pushPrompt';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, signContract: jest.fn(), getChildPet: jest.fn(), logout: jest.fn() },
  };
});

// M3-02: the push pre-prompt follows a successful signature.
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

const signContract = api.signContract as jest.Mock;

/** Signed-in child whose pet waits for the contract (state right after the PIN login). */
let queryClient: QueryClient;
async function openContract() {
  queryClient = renderWithQuery(<ContractScreen />).client;
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

describe('ContractScreen (M1-07b)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({
      token: 'child-token',
      user: { id: 2, name: 'Otrok', email: null, role: 'child' },
      pet: makePet({ born_at: null, awaiting_contract: true }),
    });
  });

  it('accept is disabled until the child draws a signature', async () => {
    await openContract();
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.accept));
    expect(signContract).not.toHaveBeenCalled();
  });

  it('signs → sends the svg_path → stores the born pet from the server state', async () => {
    let resolve: (value: unknown) => void = () => undefined;
    signContract.mockReturnValueOnce(new Promise((r) => (resolve = r)));
    await openContract();
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
    // M1-13: the HUD starts from the server's state (no extra GET).
    const cached = queryClient.getQueryData<ChildPetView>(childPetKey);
    expect(cached?.pet.awaiting_contract).toBe(false);
    expect(cached?.pet.id).toBe(7);
    // M3-02: the pet is born → ask (child wording) whether it may call the child.
    expect(maybeAskForPush).toHaveBeenCalledWith('child');
  });

  it('409 already signed → continues with the state from the body', async () => {
    signContract.mockRejectedValueOnce(
      new ApiError('The contract is already signed.', 409, { reason: 'contract_already_signed', state: makeChildState() }),
    );
    await openContract();
    sign();
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.accept));

    await waitFor(() => expect(useAppStore.getState().pet?.born_at).toBe('2026-10-04T09:00:00+00:00'));
    expect(screen.queryByTestId('contract-error')).toBeNull();
  });

  it('422 → shows "Podpis ni veljaven" and clears the pad', async () => {
    signContract.mockRejectedValueOnce(new ApiError('invalid', 422, { errors: { signature: ['x'] } }));
    await openContract();
    sign();
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.accept));

    expect(await screen.findByText(CONTRACT_STRINGS.invalid)).toBeTruthy();
    expect(isAwaitingContract(useAppStore.getState().pet)).toBe(true);
    expect(maybeAskForPush).not.toHaveBeenCalled();
    // Pad cleared → accept disabled again.
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.accept));
    expect(signContract).toHaveBeenCalledTimes(1);
  });

  it('423 → shows the lock reason', async () => {
    signContract.mockRejectedValueOnce(new ApiError('locked', 423, { reason: 'hard_stopped', state: makeChildState() }));
    await openContract();
    sign();
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.accept));

    expect(await screen.findByText(CONTRACT_STRINGS.locked.hard_stopped)).toBeTruthy();
    expect(isAwaitingContract(useAppStore.getState().pet)).toBe(true);
  });

  it('offline → retry button re-sends the same signature', async () => {
    signContract
      .mockRejectedValueOnce(new TypeError('Network request failed'))
      .mockResolvedValueOnce({ status: 'accepted', state: makeChildState() });
    await openContract();
    sign();
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.accept));

    expect(await screen.findByText(CONTRACT_STRINGS.network)).toBeTruthy();
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.retry));

    await waitFor(() => expect(isAwaitingContract(useAppStore.getState().pet)).toBe(false));
    expect(signContract).toHaveBeenCalledTimes(2);
    expect(signContract.mock.calls[1]).toEqual(signContract.mock.calls[0]);
  });

  it('a child who joined a born shared pet still signs; success unlocks it (M2-01)', async () => {
    useAppStore.getState().setPet(makePet({ born_at: '2026-10-01T08:00:00Z', awaiting_contract: true }));
    signContract.mockResolvedValueOnce({ status: 'accepted', state: makeChildState({ born_at: '2026-10-01T08:00:00+00:00' }) });
    await openContract();
    sign();
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.accept));

    await waitFor(() => expect(isAwaitingContract(useAppStore.getState().pet)).toBe(false));
    expect(useAppStore.getState().pet?.born_at).toBe('2026-10-01T08:00:00+00:00');
  });
});
