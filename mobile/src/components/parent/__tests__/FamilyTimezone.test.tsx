/**
 * Family time zone setting UI (David 2026-11-08): the Nadzor card + sheet (pick → warning →
 * PUT /api/parent/settings), the quiet hours »po času družine« note; time zone per pet
 * (David 2026-11-09): the pet's »Časovni pas« row (PUT /api/parent/pets/{id}/timezone, »Po
 * družini« = null) and the per-pet dashboard banner (only this phone's pets pre-checked,
 * dismissed per phone zone).
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import FamilyTimezoneCard, { PetTimezoneRow } from '@/components/parent/FamilyTimezoneCard';
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
    api: { ...actual.api, updateFamilyTimezone: jest.fn(), updatePetTimezone: jest.fn(), getQuietHours: jest.fn() },
  };
});

const updateFamilyTimezone = api.updateFamilyTimezone as jest.Mock;
const updatePetTimezone = api.updatePetTimezone as jest.Mock;
const getQuietHours = api.getQuietHours as jest.Mock;

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

beforeEach(async () => {
  await i18n.changeLanguage('sl');
  updateFamilyTimezone.mockReset();
  updatePetTimezone.mockReset();
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

  it('keeps »Spremeni« off while the family zone is unknown', () => {
    renderWithQuery(<FamilyTimezoneCard familyZone={null} />);
    expect(screen.getByTestId('family-zone-change').props.accessibilityState).toEqual({ disabled: true });
  });
});

describe('PetTimezoneRow', () => {
  it('shows the effective zone with »po družini«, and resets to the family through the pet endpoint', async () => {
    setPhoneZoneForTests('Europe/Lisbon');
    updatePetTimezone.mockResolvedValue({ pet_id: 7, timezone: 'Europe/Ljubljana', timezone_follows_family: true });
    const own = { id: 7, timezone: 'Europe/Lisbon', timezone_follows_family: false, breed_type: 'mutt', species: 'dog', name: 'Suki' };
    renderWithQuery(<PetTimezoneRow pet={own} familyZone="Europe/Ljubljana" />);
    expect(screen.getByTestId('pet-zone-value-7').props.children).toBe('Lizbona · UTC');

    fireEvent.press(screen.getByTestId('pet-zone-change-7'));
    expect(screen.getByText('Po družini (Ljubljana)')).toBeTruthy();
    fireEvent.press(screen.getByTestId('family-zone-option-follow-family'));
    expect(screen.getByTestId('family-zone-warning')).toBeTruthy();
    fireEvent.press(screen.getByTestId('family-zone-confirm'));
    await flush();

    expect(updatePetTimezone).toHaveBeenCalledWith(7, null);
    expect(updateFamilyTimezone).not.toHaveBeenCalled();
    expect(screen.getByTestId('pet-zone-saved-7')).toBeTruthy();
  });

  it('labels a pet that follows the family and sets its own zone', async () => {
    setPhoneZoneForTests('Europe/Lisbon');
    updatePetTimezone.mockResolvedValue({ pet_id: 8, timezone: 'Europe/Lisbon', timezone_follows_family: false });
    const follower = { id: 8, timezone: 'Europe/Ljubljana', timezone_follows_family: true, breed_type: 'mutt', species: 'dog', name: null };
    renderWithQuery(<PetTimezoneRow pet={follower} familyZone="Europe/Ljubljana" />);
    expect(screen.getByTestId('pet-zone-value-8').props.children).toMatch(/^Ljubljana · UTC\+1 · po družini$/);

    fireEvent.press(screen.getByTestId('pet-zone-change-8'));
    fireEvent.press(screen.getByTestId('family-zone-option-Europe/Lisbon'));
    fireEvent.press(screen.getByTestId('family-zone-confirm'));
    await flush();
    expect(updatePetTimezone).toHaveBeenCalledWith(8, 'Europe/Lisbon');
  });
});

describe('TimezoneSuggestionBanner (per pet)', () => {
  const suki = { id: 1, name: 'Suki', breed_type: 'mutt', species: 'dog', timezone: 'Europe/Ljubljana', is_active: true, is_game_over: false, caretakers: [{ child_id: 10 }] };
  const bela = { id: 2, name: 'Bela', breed_type: 'mutt', species: 'dog', timezone: 'Europe/Ljubljana', is_active: true, is_game_over: false, caretakers: [{ child_id: 11 }] };

  it('asks about the one pet this phone cares for, and switches it after the warning', async () => {
    setPhoneZoneForTests('Europe/Lisbon');
    updatePetTimezone.mockResolvedValue({ pet_id: 1, timezone: 'Europe/Lisbon', timezone_follows_family: false });
    renderWithQuery(<TimezoneSuggestionBanner familyZone="Europe/Ljubljana" pets={[suki]} phoneChildIds={[10]} />);
    await flush();

    expect(screen.getByText('Telefon je v drugem časovnem pasu (Lizbona) kot Suki (Ljubljana). Zamenjam za Suki?')).toBeTruthy();
    fireEvent.press(screen.getByTestId('zone-suggestion-change'));
    expect(screen.getByTestId('zone-suggestion-warning')).toBeTruthy();
    expect(updatePetTimezone).not.toHaveBeenCalled();
    fireEvent.press(screen.getByTestId('zone-suggestion-confirm'));
    await flush();
    expect(updatePetTimezone).toHaveBeenCalledWith(1, 'Europe/Lisbon');
  });

  it('lists several pets, pre-checks only this phone\'s, and never changes the unchecked ones', async () => {
    setPhoneZoneForTests('Europe/Lisbon');
    updatePetTimezone.mockResolvedValue({ pet_id: 1, timezone: 'Europe/Lisbon', timezone_follows_family: false });
    renderWithQuery(<TimezoneSuggestionBanner familyZone="Europe/Ljubljana" pets={[bela, suki]} phoneChildIds={[10]} />);
    await flush();

    expect(screen.getByTestId('zone-suggestion-pet-1').props.accessibilityState).toMatchObject({ checked: true });
    expect(screen.getByTestId('zone-suggestion-pet-2').props.accessibilityState).toMatchObject({ checked: false });
    fireEvent.press(screen.getByTestId('zone-suggestion-change'));
    fireEvent.press(screen.getByTestId('zone-suggestion-confirm'));
    await flush();
    expect(updatePetTimezone).toHaveBeenCalledTimes(1);
    expect(updatePetTimezone).toHaveBeenCalledWith(1, 'Europe/Lisbon');
  });

  it('is hidden when no pet of this phone is on another clock', async () => {
    setPhoneZoneForTests('Europe/Lisbon');
    const view = renderWithQuery(<TimezoneSuggestionBanner familyZone="Europe/Ljubljana" pets={[suki, bela]} phoneChildIds={[]} />);
    await flush();
    expect(screen.queryByTestId('zone-suggestion')).toBeNull();
    view.unmount();

    setPhoneZoneForTests('Europe/Vienna');
    renderWithQuery(<TimezoneSuggestionBanner familyZone="Europe/Ljubljana" pets={[suki]} phoneChildIds={[10]} />);
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
    const view = renderWithQuery(<TimezoneSuggestionBanner familyZone="Europe/Ljubljana" pets={[suki]} phoneChildIds={[10]} />);
    await flush();

    fireEvent.press(screen.getByTestId('zone-suggestion-dismiss'));
    await flush();
    expect(screen.queryByTestId('zone-suggestion')).toBeNull();
    expect(saved.get(ZONE_SUGGESTION_KEY)).toBe('Europe/Lisbon');
    view.unmount();

    resetZoneSuggestionForTests();
    const again = renderWithQuery(<TimezoneSuggestionBanner familyZone="Europe/Ljubljana" pets={[suki]} phoneChildIds={[10]} />);
    await flush();
    expect(screen.queryByTestId('zone-suggestion')).toBeNull();
    again.unmount();

    setPhoneZoneForTests('America/New_York');
    renderWithQuery(<TimezoneSuggestionBanner familyZone="Europe/Ljubljana" pets={[suki]} phoneChildIds={[10]} />);
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
