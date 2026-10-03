/**
 * M2-02 (partial): parent "Dodaj otroka" PIN screen.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { ApiError, api, NO_CHILD_PAIRED_MESSAGE } from '@/api/client';
import AddChildScreen, { ADD_CHILD_STRINGS as S } from '@/screens/parent/AddChildScreen';
import { useAppStore } from '@/store/appStore';
import { makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, generatePin: jest.fn(), getParentDashboard: jest.fn(), getUser: jest.fn() },
  };
});

const generatePin = api.generatePin as jest.Mock;
const getParentDashboard = api.getParentDashboard as jest.Mock;
const getUser = api.getUser as jest.Mock;

const NOW = new Date('2026-10-03T10:00:00Z');
const NO_CHILD = {
  message: NO_CHILD_PAIRED_MESSAGE,
  pet: null,
  traffic_light: 'green',
  quiet_hours: null,
  recent_activities: [],
};

function pinResponse(pin: string, minutes = 15) {
  return {
    pin,
    expires_at: new Date(Date.now() + minutes * 60_000).toISOString(),
    expires_in_minutes: 15,
  };
}

/**
 * Let pending promises settle under fake timers. TanStack Query batches its
 * notifications with setTimeout(0), so a zero-length timer advance is needed too.
 */
async function flush() {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(0);
  });
}

describe('AddChildScreen', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    jest.useFakeTimers();
    jest.setSystemTime(NOW);
    getParentDashboard.mockResolvedValue(NO_CHILD);
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('generates a PIN on open and shows it large and grouped', async () => {
    let resolvePin: (value: ReturnType<typeof pinResponse>) => void = () => undefined;
    generatePin.mockReturnValueOnce(new Promise((resolve) => (resolvePin = resolve)));
    renderWithQuery(<AddChildScreen onBack={jest.fn()} />);

    expect(await screen.findByText(S.generating)).toBeTruthy();
    resolvePin(pinResponse('734912'));
    await flush();
    expect(screen.getByText('734 912')).toBeTruthy();
    expect(generatePin).toHaveBeenCalledTimes(1);
    expect(screen.getByText(S.instructions)).toBeTruthy();
  });

  it('counts down live and switches to "expired" after 15 minutes', async () => {
    generatePin.mockResolvedValueOnce(pinResponse('734912'));
    renderWithQuery(<AddChildScreen onBack={jest.fn()} />);
    await screen.findByText('734 912');

    expect(screen.getByTestId('pin-countdown')).toHaveTextContent(S.validFor('15:00'));
    act(() => {
      jest.advanceTimersByTime(1_000);
    });
    expect(screen.getByTestId('pin-countdown')).toHaveTextContent(S.validFor('14:59'));
    act(() => {
      jest.advanceTimersByTime(14 * 60_000);
    });
    expect(screen.getByTestId('pin-countdown')).toHaveTextContent(S.validFor('0:59'));
    act(() => {
      jest.advanceTimersByTime(59_000);
    });
    expect(screen.queryByTestId('pin-countdown')).toBeNull();
    expect(screen.getByText(S.expired)).toBeTruthy();
  });

  it('"Nova koda" replaces the PIN and restarts the countdown', async () => {
    generatePin.mockResolvedValueOnce(pinResponse('734912'));
    renderWithQuery(<AddChildScreen onBack={jest.fn()} />);
    await screen.findByText('734 912');
    act(() => {
      jest.advanceTimersByTime(5 * 60_000);
    });

    generatePin.mockResolvedValueOnce(pinResponse('004501'));
    fireEvent.press(screen.getByText(S.newCode));
    expect(await screen.findByText('004 501')).toBeTruthy();
    expect(screen.getByTestId('pin-countdown')).toHaveTextContent(S.validFor('15:00'));
    expect(generatePin).toHaveBeenCalledTimes(2);
  });

  it('429 keeps the still-valid PIN, explains the wait and blocks "Nova koda" until Retry-After', async () => {
    generatePin.mockResolvedValueOnce(pinResponse('734912'));
    renderWithQuery(<AddChildScreen onBack={jest.fn()} />);
    await screen.findByText('734 912');

    generatePin.mockRejectedValueOnce(new ApiError('Too Many Attempts.', 429, null, 30));
    fireEvent.press(screen.getByText(S.newCode));
    await flush();

    expect(screen.getByTestId('pin-error')).toHaveTextContent(S.errors.rate_limited(30));
    expect(screen.getByText('734 912')).toBeTruthy();

    fireEvent.press(screen.getByText(S.newCode));
    expect(generatePin).toHaveBeenCalledTimes(2); // disabled during cooldown

    act(() => {
      jest.advanceTimersByTime(10_000);
    });
    expect(screen.getByTestId('pin-error')).toHaveTextContent(S.errors.rate_limited(20));

    act(() => {
      jest.advanceTimersByTime(20_000);
    });
    expect(screen.queryByTestId('pin-error')).toBeNull(); // wait is over
    generatePin.mockResolvedValueOnce(pinResponse('111222'));
    fireEvent.press(screen.getByText(S.newCode));
    expect(await screen.findByText('111 222')).toBeTruthy();
    expect(screen.queryByTestId('pin-error')).toBeNull();
  });

  it('shows the offline message and a retry when the first PIN fails', async () => {
    generatePin.mockRejectedValueOnce(new TypeError('Network request failed'));
    renderWithQuery(<AddChildScreen onBack={jest.fn()} />);
    await flush();

    expect(screen.getByTestId('pin-error')).toHaveTextContent(S.errors.offline);
    expect(screen.queryByTestId('pairing-pin')).toBeNull();

    generatePin.mockResolvedValueOnce(pinResponse('734912'));
    fireEvent.press(screen.getByText(S.retry));
    expect(await screen.findByText('734 912')).toBeTruthy();
  });

  it.each([
    [403, S.errors.forbidden],
    [500, S.errors.server],
  ])('maps HTTP %i to a parent-friendly message', async (status, message) => {
    generatePin.mockRejectedValueOnce(new ApiError('x', status));
    renderWithQuery(<AddChildScreen onBack={jest.fn()} />);
    await flush();

    expect(screen.getByTestId('pin-error')).toHaveTextContent(message);
  });

  it('polls the dashboard while the PIN is valid and confirms when the child has paired', async () => {
    const pet = makePet();
    generatePin.mockResolvedValueOnce(pinResponse('734912'));
    getUser.mockResolvedValue({ id: 1, name: 'Starš', email: 'p@x.si', role: 'parent', pet });
    const onBack = jest.fn();
    renderWithQuery(<AddChildScreen onBack={onBack} />);
    await screen.findByText('734 912');
    const callsBefore = getParentDashboard.mock.calls.length;

    getParentDashboard.mockResolvedValue({ ...NO_CHILD, message: undefined, pet: { id: pet.id } });
    await act(async () => {
      jest.advanceTimersByTime(5_000);
    });

    expect(getParentDashboard.mock.calls.length).toBeGreaterThan(callsBefore);
    expect(await screen.findByTestId('add-child-paired')).toBeTruthy();
    await flush();
    expect(useAppStore.getState().pet).toEqual(pet);

    fireEvent.press(screen.getByText(S.toDashboard));
    expect(onBack).toHaveBeenCalled();
  });
});
