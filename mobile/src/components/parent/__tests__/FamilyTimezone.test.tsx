/**
 * Family time zone setting UI (David 2026-11-08): the Nadzor card + sheet (pick → warning →
 * PUT /api/parent/settings), the dashboard suggestion banner (dismissed per phone zone) and
 * the quiet hours »po času družine« note.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import FamilyTimezoneCard from '@/components/parent/FamilyTimezoneCard';
import QuietHoursCard from '@/components/parent/QuietHoursCard';
import TimezoneSuggestionBanner from '@/components/parent/TimezoneSuggestionBanner';
import { i18n } from '@/i18n';
import { setPhoneZoneForTests } from '@/modules/familyZone/familyZone';
import { resetZoneSuggestionForTests, setZoneSuggestionStoreForTests, ZONE_SUGGESTION_KEY } from '@/modules/familyZone/zoneSuggestionPrefs';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, updateFamilyTimezone: jest.fn(), getQuietHours: jest.fn() },
  };
});

const updateFamilyTimezone = api.updateFamilyTimezone as jest.Mock;
const getQuietHours = api.getQuietHours as jest.Mock;

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

beforeEach(async () => {
  await i18n.changeLanguage('sl');
  updateFamilyTimezone.mockReset();
  getQuietHours.mockResolvedValue({ quiet_hours: null, timezone: 'Europe/Ljubljana' });
});

describe('FamilyTimezoneCard', () => {
  it('shows the family zone as city + offset', () => {
    renderWithQuery(<FamilyTimezoneCard familyZone="Europe/Ljubljana" />);
    expect(screen.getByTestId('family-zone-value').props.children).toMatch(/^Ljubljana · UTC\+[12]$/);
  });

  it('offers the phone zone first, warns, and saves through PUT /api/parent/settings', async () => {
    setPhoneZoneForTests('Europe/Lisbon');
    updateFamilyTimezone.mockResolvedValue({ message: 'Settings updated successfully.', settings: { timezone: 'Europe/Lisbon' } });
    renderWithQuery(<FamilyTimezoneCard familyZone="Europe/Ljubljana" />);

    fireEvent.press(screen.getByTestId('family-zone-change'));
    expect(screen.getByText(/^Ta telefon · Europe\/Lisbon$/)).toBeTruthy();

    fireEvent.press(screen.getByTestId('family-zone-option-Europe/Lisbon'));
    expect(screen.getByTestId('family-zone-warning').props.children).toContain('12-tedenskim izzivom');
    expect(updateFamilyTimezone).not.toHaveBeenCalled();

    fireEvent.press(screen.getByTestId('family-zone-confirm'));
    await flush();
    expect(updateFamilyTimezone).toHaveBeenCalledWith('Europe/Lisbon');
    expect(screen.getByTestId('family-zone-saved')).toBeTruthy();
    expect(screen.queryByTestId('family-zone-sheet')).toBeNull();
  });

  it('explains the 24 h limit and keeps the sheet open', async () => {
    setPhoneZoneForTests('Europe/Lisbon');
    updateFamilyTimezone.mockRejectedValue(
      new ApiError('too soon', 422, { reason: 'timezone_change_too_soon', next_allowed_at: '2026-11-09T19:30:00+01:00' }),
    );
    renderWithQuery(<FamilyTimezoneCard familyZone="Europe/Ljubljana" />);

    fireEvent.press(screen.getByTestId('family-zone-change'));
    fireEvent.press(screen.getByTestId('family-zone-option-Europe/Lisbon'));
    fireEvent.press(screen.getByTestId('family-zone-confirm'));
    await flush();

    expect(screen.getByTestId('family-zone-error').props.children).toMatch(/enkrat na 24 ur/);
    expect(screen.getByTestId('family-zone-sheet')).toBeTruthy();
  });

  it('opens the sheet when the dashboard banner asks, once', () => {
    const handled = jest.fn();
    renderWithQuery(<FamilyTimezoneCard familyZone="Europe/Ljubljana" openRequest={1} onOpenRequestHandled={handled} />);
    expect(screen.getByTestId('family-zone-sheet')).toBeTruthy();
    expect(handled).toHaveBeenCalledTimes(1);
  });

  it('keeps »Spremeni« off while the family zone is unknown', () => {
    renderWithQuery(<FamilyTimezoneCard familyZone={null} />);
    expect(screen.getByTestId('family-zone-change').props.accessibilityState).toEqual({ disabled: true });
  });
});

describe('TimezoneSuggestionBanner', () => {
  it('suggests the change when the phone clock differs, and opens the setting', async () => {
    setPhoneZoneForTests('Europe/Lisbon');
    const onChange = jest.fn();
    renderWithQuery(<TimezoneSuggestionBanner familyZone="Europe/Ljubljana" onChange={onChange} />);
    await flush();

    expect(screen.getByText('Telefon je v drugem časovnem pasu (Lizbona) kot družina (Ljubljana). Zamenjam?')).toBeTruthy();
    fireEvent.press(screen.getByTestId('zone-suggestion-change'));
    expect(onChange).toHaveBeenCalled();
  });

  it('is hidden on the family clock and for an unknown phone zone', async () => {
    setPhoneZoneForTests('Europe/Vienna');
    const view = renderWithQuery(<TimezoneSuggestionBanner familyZone="Europe/Ljubljana" onChange={jest.fn()} />);
    await flush();
    expect(screen.queryByTestId('zone-suggestion')).toBeNull();
    view.unmount();

    setPhoneZoneForTests(null);
    renderWithQuery(<TimezoneSuggestionBanner familyZone="Europe/Ljubljana" onChange={jest.fn()} />);
    await flush();
    expect(screen.queryByTestId('zone-suggestion')).toBeNull();
  });

  it('remembers »Ne zdaj« for this phone zone only', async () => {
    const saved = new Map<string, string>();
    setZoneSuggestionStoreForTests({
      getItemAsync: async (key) => saved.get(key) ?? null,
      setItemAsync: async (key, value) => void saved.set(key, value),
    });
    setPhoneZoneForTests('Europe/Lisbon');
    const view = renderWithQuery(<TimezoneSuggestionBanner familyZone="Europe/Ljubljana" onChange={jest.fn()} />);
    await flush();

    fireEvent.press(screen.getByTestId('zone-suggestion-dismiss'));
    await flush();
    expect(screen.queryByTestId('zone-suggestion')).toBeNull();
    expect(saved.get(ZONE_SUGGESTION_KEY)).toBe('Europe/Lisbon');
    view.unmount();

    // A fresh app run in the same zone: still hidden (read from the store).
    resetZoneSuggestionForTests();
    const again = renderWithQuery(<TimezoneSuggestionBanner familyZone="Europe/Ljubljana" onChange={jest.fn()} />);
    await flush();
    expect(screen.queryByTestId('zone-suggestion')).toBeNull();
    again.unmount();

    // Yet another zone: back.
    setPhoneZoneForTests('America/New_York');
    renderWithQuery(<TimezoneSuggestionBanner familyZone="Europe/Ljubljana" onChange={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('zone-suggestion')).toBeTruthy();
  });
});

describe('QuietHoursCard family time note', () => {
  it('says the times are family time when the phone clock differs', async () => {
    setPhoneZoneForTests('Europe/Lisbon');
    renderWithQuery(<QuietHoursCard familyZone="Europe/Ljubljana" />);
    await flush();
    expect(screen.getByTestId('qh-family-time').props.children).toBe('po času družine (Ljubljana)');
  });

  it('shows no note on the family clock', async () => {
    setPhoneZoneForTests('Europe/Ljubljana');
    renderWithQuery(<QuietHoursCard familyZone="Europe/Ljubljana" />);
    await flush();
    expect(screen.queryByTestId('qh-family-time')).toBeNull();
  });
});
