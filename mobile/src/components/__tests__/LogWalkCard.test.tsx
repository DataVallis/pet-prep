/**
 * M5-T12 »Vpiši sprehod«: the parent logs walk minutes for a child in adapted mode —
 * today only (family zone, David 2026-10-21), 1–240 min, one entry id per entry (kept on retry).
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import LogWalkCard, { entryIdFor, parseWalkMinutes, walkToday } from '@/components/parent/LogWalkCard';
import { newWalkEntryId } from '@/hooks/queries/useFamilyMutations';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, logParentWalk: jest.fn(), getParentDashboard: jest.fn() } };
});

const logParentWalk = api.logParentWalk as jest.Mock;

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

describe('LogWalkCard helpers', () => {
  it('parses 1–240 whole minutes only', () => {
    expect(parseWalkMinutes('30')).toBe(30);
    expect(parseWalkMinutes(' 240 ')).toBe(240);
    expect(parseWalkMinutes('0')).toBeNull();
    expect(parseWalkMinutes('241')).toBeNull();
    expect(parseWalkMinutes('1.5')).toBeNull();
    expect(parseWalkMinutes('')).toBeNull();
  });

  it('today is family-local (Ljubljana just after midnight is already the next day)', () => {
    const ms = Date.parse('2026-10-14T22:30:00Z'); // 00:30 on the 15th in Ljubljana
    expect(walkToday('Europe/Ljubljana', ms)).toBe('2026-10-15');
  });

  it('without a family zone today follows Europe/Ljubljana (family default), not the device zone', () => {
    const ms = Date.parse('2026-10-14T22:30:00Z');
    expect(walkToday(null, ms)).toBe('2026-10-15');
  });

  it('a retry reuses the failed entry id only for the identical entry (same minutes and day)', () => {
    const failed = { entryId: 'w-old-0001', minutes: 30, day: '2026-10-14' };
    const fresh = () => 'w-new-0001';
    expect(entryIdFor(failed, 30, '2026-10-14', fresh)).toBe('w-old-0001');
    expect(entryIdFor(failed, 31, '2026-10-14', fresh)).toBe('w-new-0001');
    // A retry after the family midnight is a new entry (new day).
    expect(entryIdFor(failed, 30, '2026-10-15', fresh)).toBe('w-new-0001');
    expect(entryIdFor(null, 30, '2026-10-14', fresh)).toBe('w-new-0001');
  });

  it('entry ids are unique and valid for the server (8–64 of [A-Za-z0-9_-])', () => {
    const a = newWalkEntryId(1, () => 0.1);
    const b = newWalkEntryId(1, () => 0.2);
    expect(a).not.toBe(b);
    expect(a).toMatch(/^[A-Za-z0-9_-]{8,64}$/);
  });
});

describe('LogWalkCard', () => {
  beforeEach(() => jest.clearAllMocks());

  it('sends minutes, today and an entry id; shows "Shranjeno"; a new entry gets a new id', async () => {
    logParentWalk.mockResolvedValue({ status: 'accepted', entry: { minutes: 30 }, steps_today: 3000 });
    renderWithQuery(<LogWalkCard childId={2} timezone="Europe/Ljubljana" />);
    expect(screen.getByText('Vpiši sprehod')).toBeTruthy();

    fireEvent.changeText(screen.getByTestId('log-walk-minutes'), '30');
    fireEvent.press(screen.getByTestId('log-walk-submit'));
    await flush();
    const [childId, body] = logParentWalk.mock.calls[0] as [number, { minutes: number; local_date: string; entry_id: string }];
    expect(childId).toBe(2);
    expect(body.minutes).toBe(30);
    expect(body.local_date).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(screen.getByTestId('log-walk-saved').props.children).toBe('Shranjeno: 30 minut sprehoda.');

    fireEvent.changeText(screen.getByTestId('log-walk-minutes'), '10');
    fireEvent.press(screen.getByTestId('log-walk-submit'));
    await flush();
    expect((logParentWalk.mock.calls[1][1] as { entry_id: string }).entry_id).not.toBe(body.entry_id);
  });

  it('a failed request keeps the entry id for the retry and shows the reason', async () => {
    logParentWalk.mockRejectedValueOnce(new ApiError('x', 422, { reason: 'adapted_mode_off' }));
    logParentWalk.mockResolvedValueOnce({ status: 'accepted', entry: { minutes: 20 }, steps_today: 2000 });
    renderWithQuery(<LogWalkCard childId={2} timezone="Europe/Ljubljana" />);
    fireEvent.changeText(screen.getByTestId('log-walk-minutes'), '20');
    fireEvent.press(screen.getByTestId('log-walk-submit'));
    await flush();
    expect(screen.getByTestId('log-walk-error').props.children).toBe('Najprej vklopite prilagojen način.');
    fireEvent.press(screen.getByTestId('log-walk-submit'));
    await flush();
    expect((logParentWalk.mock.calls[1][1] as { entry_id: string }).entry_id).toBe((logParentWalk.mock.calls[0][1] as { entry_id: string }).entry_id);
  });

  it('after a failure, changed minutes are a new entry with a new id', async () => {
    logParentWalk.mockRejectedValueOnce(new ApiError('x', 500, {}));
    logParentWalk.mockResolvedValueOnce({ status: 'accepted', entry: { minutes: 25 }, steps_today: 2500 });
    renderWithQuery(<LogWalkCard childId={2} timezone="Europe/Ljubljana" />);
    fireEvent.changeText(screen.getByTestId('log-walk-minutes'), '20');
    fireEvent.press(screen.getByTestId('log-walk-submit'));
    await flush();
    fireEvent.changeText(screen.getByTestId('log-walk-minutes'), '25');
    fireEvent.press(screen.getByTestId('log-walk-submit'));
    await flush();
    expect((logParentWalk.mock.calls[1][1] as { entry_id: string }).entry_id).not.toBe((logParentWalk.mock.calls[0][1] as { entry_id: string }).entry_id);
  });

  it('shows the minutes still allowed when the daily cap is reached', async () => {
    logParentWalk.mockRejectedValueOnce(new ApiError('x', 422, { reason: 'daily_limit_reached', remaining_minutes: 40 }));
    renderWithQuery(<LogWalkCard childId={2} timezone="Europe/Ljubljana" />);
    fireEvent.changeText(screen.getByTestId('log-walk-minutes'), '60');
    fireEvent.press(screen.getByTestId('log-walk-submit'));
    await flush();
    expect(screen.getByTestId('log-walk-error').props.children).toBe('Danes lahko vpišete še 40 minut (največ 240 minut na dan).');
  });

  it('invalid minutes disable the button', () => {
    renderWithQuery(<LogWalkCard childId={2} timezone="Europe/Ljubljana" />);
    fireEvent.changeText(screen.getByTestId('log-walk-minutes'), '300');
    expect(screen.getByTestId('log-walk-invalid')).toBeTruthy();
    fireEvent.press(screen.getByTestId('log-walk-submit'));
    expect(logParentWalk).not.toHaveBeenCalled();
  });
});
