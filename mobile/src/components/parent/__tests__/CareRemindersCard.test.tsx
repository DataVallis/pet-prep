/**
 * M5-R18 / R20 — the parent's groomer / bath reminders: shown only when the server sends one,
 * "Opravljeno" posts the confirmation once, a done reminder shows the date; never scored.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { api } from '@/api/client';
import CareRemindersCard, { reminderDate } from '@/components/parent/CareRemindersCard';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, confirmCareReminder: jest.fn() } };
});

const mocked = api as unknown as Record<string, jest.Mock>;
const TZ = 'Europe/Ljubljana';
const OPEN = { task: 'groomer_visit', period_days: 28, period_started_at: '2026-10-20T08:00:00+02:00', period_ends_at: '2026-11-17T08:00:00+01:00', done: false, done_at: null };

beforeEach(() => {
  jest.clearAllMocks();
  mocked.confirmCareReminder.mockResolvedValue({ status: 'accepted', pet_id: 7, care_reminders: [] });
});

it('renders nothing without a reminder', () => {
  renderWithQuery(<CareRemindersCard petId={7} reminders={[]} timezone={TZ} />);
  expect(screen.queryByTestId('care-reminders')).toBeNull();
});

it('shows the groomer reminder with its due date and confirms it', async () => {
  renderWithQuery(<CareRemindersCard petId={7} reminders={[OPEN]} timezone={TZ} />);
  expect(screen.getByText('Pasji frizer')).toBeTruthy();
  expect(screen.getByText('Priporočeno enkrat na mesec')).toBeTruthy();
  expect(screen.getByTestId('care-reminders-groomer_visit-status').props.children).toBe('Do 17. 11.');
  expect(screen.getByText('Opomniki za vas. Ne vplivajo na oceno skrbi.')).toBeTruthy();
  await act(async () => {
    fireEvent.press(screen.getByTestId('care-reminders-groomer_visit-confirm'));
  });
  expect(mocked.confirmCareReminder).toHaveBeenCalledWith(7, 'groomer_visit');
});

it('a done reminder has no button and shows when', () => {
  renderWithQuery(<CareRemindersCard petId={7} reminders={[{ ...OPEN, task: 'bath', done: true, done_at: '2026-10-22T18:10:00+02:00' }]} timezone={TZ} />);
  expect(screen.queryByTestId('care-reminders-bath-confirm')).toBeNull();
  expect(screen.getByTestId('care-reminders-bath-status').props.children).toBe('Opravljeno ✓ 22. 10.');
  expect(reminderDate(null, TZ)).toBe('');
});
