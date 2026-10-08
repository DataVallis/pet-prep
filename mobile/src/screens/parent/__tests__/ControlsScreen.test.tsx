/**
 * M2-05 / M2-01a: "Nadzor" — hard stop per pet with in-app confirmation (sets the
 * confirmed intent: {pet_id, active}; PR #20 review M1), quiet hours, second-parent
 * invite + share, joining a family.
 */
import type { ReactElement } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, screen } from '@testing-library/react-native';
import { Alert, Share } from 'react-native';

import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/client';
import { i18n } from '@/i18n';
import { api } from '@/api/client';
import { JOIN_FAMILY_STRINGS } from '@/components/parent/JoinFamilyCard';
import { FAMILY_PARENTS_STRINGS } from '@/components/parent/FamilyParentsCard';
import { PET_CONTROLS_STRINGS } from '@/components/parent/PetControlsCard';
import { QUIET_HOURS_STRINGS } from '@/components/parent/QuietHoursCard';
import { useParentDashboard } from '@/hooks/queries/useParentDashboard';
import { familyFromDashboard, type FamilyOverview } from '@/modules/family/family';
import ControlsScreen from '@/screens/parent/ControlsScreen';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      setHardStop: jest.fn(),
      getQuietHours: jest.fn(),
      updateQuietHours: jest.fn(),
      inviteParent: jest.fn(),
      joinFamily: jest.fn(),
      getParentDashboard: jest.fn(),
      revokeChildTokens: jest.fn(),
    },
  };
});

// Every test renders the whole Nadzor screen (now incl. the "Račun" card); the
// default 5 s was flaky under a loaded full run (PR #29 nit).
jest.setTimeout(20_000);

const setHardStop = api.setHardStop as jest.Mock;
const getParentDashboard = api.getParentDashboard as jest.Mock;
const inviteParent = api.inviteParent as jest.Mock;
const joinFamily = api.joinFamily as jest.Mock;
const updateQuietHours = api.updateQuietHours as jest.Mock;

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

function dashboardData(pet7Stopped = false) {
  return makeScoredDashboard(
    [makeScoredChild(), makeScoredChild({ id: 5, name: 'Maja', pet_id: 8 })],
    [
      makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }], is_hard_stopped: pet7Stopped }),
      makeFamilyPet({ id: 8, caretakers: [{ child_id: 5, contract_signed: true }], is_hard_stopped: true }),
    ],
  );
}

const FAMILY = familyFromDashboard(dashboardData() as never) as FamilyOverview;

/** Controls fed by the real dashboard query (refetches change what the card sees). */
function LiveControls() {
  const dashboard = useParentDashboard({ refetchInterval: false });
  return (
    <ControlsScreen onBack={jest.fn()} family={familyFromDashboard(dashboard.data)} onAddChild={jest.fn()} onChildPin={jest.fn()} />
  );
}

/** rerender keeping the test's QueryClient. */
function rerenderWith(view: ReturnType<typeof renderWithQuery>, ui: ReactElement) {
  view.rerender(<QueryClientProvider client={view.client}>{ui}</QueryClientProvider>);
}

const EMPTY: FamilyOverview = { ...FAMILY, children: [], pets: [] };

/** Like the app (AppNavigator subscribes to the language): re-renders on a switch. */
function LocalizedControls() {
  useTranslation();
  return <ControlsScreen onBack={jest.fn()} family={FAMILY} onAddChild={jest.fn()} onChildPin={jest.fn()} />;
}

function renderControls(family: FamilyOverview | null = FAMILY) {
  return renderWithQuery(<ControlsScreen onBack={jest.fn()} family={family} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
}

describe('ControlsScreen — hard stop per pet', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    (api.getQuietHours as jest.Mock).mockResolvedValue({ quiet_hours: null });
  });

  it('asks in-app first; the request SETS the confirmed state for that pet', async () => {
    const alertSpy = jest.spyOn(Alert, 'alert');
    setHardStop.mockResolvedValueOnce({ message: 'x', pet_id: 7, is_hard_stopped: true, changed: true });
    renderControls();
    await flush();

    expect(screen.getByTestId('pet-caretakers-7')).toHaveTextContent('Skrbi: Luka');
    expect(screen.getByTestId('pet-caretakers-8')).toHaveTextContent('Skrbi: Maja');
    expect(screen.getByLabelText(PET_CONTROLS_STRINGS.a11y(PET_CONTROLS_STRINGS.stop, 'Mešanček', 'Luka'))).toBeTruthy();
    fireEvent.press(screen.getByTestId('hard-stop-7'));
    expect(screen.getByText(PET_CONTROLS_STRINGS.confirmStop('Luka'))).toBeTruthy();
    expect(setHardStop).not.toHaveBeenCalled();
    expect(alertSpy).not.toHaveBeenCalled();

    fireEvent.press(screen.getByTestId('hard-stop-confirm-button-7'));
    await flush();
    expect(setHardStop).toHaveBeenCalledTimes(1);
    expect(setHardStop).toHaveBeenCalledWith(7, true);
    expect(screen.getByTestId('hard-stop-result-7')).toHaveTextContent(PET_CONTROLS_STRINGS.stopped);
    expect(screen.queryByTestId('hard-stop-confirm-7')).toBeNull();
    alertSpy.mockRestore();
  });

  it('a stopped pet resumes with active=false; cancel sends nothing', async () => {
    setHardStop.mockResolvedValueOnce({ message: 'x', pet_id: 8, is_hard_stopped: false, changed: true });
    renderControls();
    await flush();
    expect(screen.getByTestId('pet-status-8')).toHaveTextContent(/hard stop/);
    fireEvent.press(screen.getByTestId('hard-stop-8'));
    expect(screen.getByText(PET_CONTROLS_STRINGS.confirmResume)).toBeTruthy();
    fireEvent.press(screen.getByText(PET_CONTROLS_STRINGS.cancel));
    expect(setHardStop).not.toHaveBeenCalled();

    fireEvent.press(screen.getByTestId('hard-stop-8'));
    fireEvent.press(screen.getByTestId('hard-stop-confirm-button-8'));
    await flush();
    expect(setHardStop).toHaveBeenCalledWith(8, false);
    expect(screen.getByTestId('hard-stop-result-8')).toHaveTextContent(PET_CONTROLS_STRINGS.resumed);
  });

  it('state flips to the intent while confirming (other parent / broadcast) → closes, sends nothing', async () => {
    const view = renderControls();
    await flush();
    fireEvent.press(screen.getByTestId('hard-stop-7'));
    expect(screen.getByTestId('hard-stop-confirm-7')).toBeTruthy();

    const stopped = familyFromDashboard(dashboardData(true) as never) as FamilyOverview;
    rerenderWith(view, <ControlsScreen onBack={jest.fn()} family={stopped} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
    await flush();

    expect(screen.queryByTestId('hard-stop-confirm-7')).toBeNull();
    expect(screen.getByTestId('hard-stop-result-7')).toHaveTextContent(PET_CONTROLS_STRINGS.alreadyStopped);
    expect(setHardStop).not.toHaveBeenCalled();
  });

  it('double tap on confirm sends exactly one request', async () => {
    let resolve: (v: unknown) => void = () => undefined;
    setHardStop.mockReturnValueOnce(new Promise((r) => (resolve = r)));
    renderControls();
    await flush();
    fireEvent.press(screen.getByTestId('hard-stop-7'));
    const confirm = screen.getByTestId('hard-stop-confirm-button-7');
    fireEvent.press(confirm);
    fireEvent.press(confirm);
    fireEvent.press(confirm);
    await flush();
    expect(setHardStop).toHaveBeenCalledTimes(1);
    await act(async () => resolve({ message: 'x', pet_id: 7, is_hard_stopped: true, changed: true }));
    await flush();
    expect(setHardStop).toHaveBeenCalledTimes(1);
  });

  it('lost response: refetches first; if the stop landed, the confirmation closes without a resend', async () => {
    getParentDashboard.mockResolvedValueOnce(dashboardData(false)).mockResolvedValue(dashboardData(true));
    setHardStop.mockRejectedValueOnce(new TypeError('Network request failed'));
    renderWithQuery(<LiveControls />);
    await flush();

    fireEvent.press(screen.getByTestId('hard-stop-7'));
    fireEvent.press(screen.getByTestId('hard-stop-confirm-button-7'));
    await flush();
    await flush();

    expect(setHardStop).toHaveBeenCalledTimes(1);
    expect(getParentDashboard.mock.calls.length).toBeGreaterThanOrEqual(2);
    expect(screen.queryByTestId('hard-stop-confirm-7')).toBeNull();
    expect(screen.getByTestId('hard-stop-result-7')).toHaveTextContent(PET_CONTROLS_STRINGS.alreadyStopped);
    expect(screen.getByTestId('pet-status-7')).toHaveTextContent(/hard stop/);
  });

  it('lost response that did not land: the retry sends the same intent again (idempotent)', async () => {
    getParentDashboard.mockResolvedValue(dashboardData(false));
    setHardStop
      .mockRejectedValueOnce(new TypeError('Network request failed'))
      .mockResolvedValueOnce({ message: 'x', pet_id: 7, is_hard_stopped: true, changed: true });
    renderWithQuery(<LiveControls />);
    await flush();

    fireEvent.press(screen.getByTestId('hard-stop-7'));
    fireEvent.press(screen.getByTestId('hard-stop-confirm-button-7'));
    await flush();
    await flush();
    expect(screen.getByTestId('hard-stop-result-7')).toHaveTextContent(PET_CONTROLS_STRINGS.errors.offline);
    expect(screen.getByTestId('hard-stop-confirm-7')).toBeTruthy();

    fireEvent.press(screen.getByTestId('hard-stop-confirm-button-7'));
    await flush();
    expect(setHardStop.mock.calls).toEqual([
      [7, true],
      [7, true],
    ]);
    expect(screen.getByTestId('hard-stop-result-7')).toHaveTextContent(PET_CONTROLS_STRINGS.stopped);
  });

  it('game-over and inactive pets have no hard stop button', async () => {
    const family: FamilyOverview = {
      ...FAMILY,
      pets: [
        { ...FAMILY.pets[0], is_game_over: true, is_active: false },
        { ...FAMILY.pets[1], is_active: false, is_hard_stopped: false },
      ],
    };
    renderControls(family);
    await flush();
    expect(screen.queryByTestId('hard-stop-7')).toBeNull();
    expect(screen.queryByTestId('hard-stop-8')).toBeNull();
    expect(screen.getByTestId('pet-status-7')).toHaveTextContent(/Igra končana/);
    expect(screen.getByTestId('pet-status-8')).toHaveTextContent(/ni aktiven/);
  });
});

describe('ControlsScreen — quiet hours', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    (api.getQuietHours as jest.Mock).mockResolvedValue({
      quiet_hours: { id: 1, school_start: '08:00', school_end: '13:00', bedtime_start: '21:00', bedtime_end: '07:00', is_active: true },
      timezone: 'Europe/Ljubljana',
    });
  });

  it('a shown message follows a language switch (M1-18 review)', async () => {
    renderWithQuery(<LocalizedControls />);
    await flush();
    fireEvent.changeText(screen.getByTestId('qh-school-start'), '7:3');
    fireEvent.press(screen.getByTestId('qh-save'));
    const slovenian = QUIET_HOURS_STRINGS.invalidTime;
    expect(screen.getByTestId('qh-message')).toHaveTextContent(slovenian);

    try {
      await act(async () => {
        await i18n.changeLanguage('en');
      });
      expect(QUIET_HOURS_STRINGS.invalidTime).not.toBe(slovenian);
      expect(screen.getByTestId('qh-message')).toHaveTextContent(QUIET_HOURS_STRINGS.invalidTime);
    } finally {
      await act(async () => {
        await i18n.changeLanguage('sl');
      });
    }
  });

  it('loads, validates HH:MM and saves', async () => {
    updateQuietHours.mockResolvedValueOnce({
      message: 'ok',
      quiet_hours: { id: 1, school_start: '07:30', school_end: '13:00', bedtime_start: '21:00', bedtime_end: '07:00', is_active: true },
    });
    renderControls();
    await flush();
    expect(screen.getByTestId('qh-school-start').props.value).toBe('08:00');

    fireEvent.changeText(screen.getByTestId('qh-school-start'), '7:3');
    fireEvent.press(screen.getByTestId('qh-save'));
    expect(screen.getByTestId('qh-message')).toHaveTextContent(QUIET_HOURS_STRINGS.invalidTime);
    expect(updateQuietHours).not.toHaveBeenCalled();

    fireEvent.changeText(screen.getByTestId('qh-school-start'), '07:30');
    fireEvent.press(screen.getByTestId('qh-save'));
    await flush();
    expect(updateQuietHours).toHaveBeenCalledWith({
      school_start: '07:30',
      school_end: '13:00',
      bedtime_start: '21:00',
      bedtime_end: '07:00',
      is_active: true,
    });
    expect(screen.getByTestId('qh-message')).toHaveTextContent(QUIET_HOURS_STRINGS.saved);
  });
});

describe('ControlsScreen — quiet hours show server truth (fix/quiet-hours-default)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it.each([
    ['saved: false (the server applies its defaults)', { id: null, school_start: null, school_end: null, bedtime_start: '21:00', bedtime_end: '07:00', is_active: true, saved: false }],
    ['null (older server)', null],
  ])('says the default times apply until Save — %s', async (_label, quietHours) => {
    (api.getQuietHours as jest.Mock).mockResolvedValue({ quiet_hours: quietHours });
    updateQuietHours.mockResolvedValueOnce({
      message: 'ok',
      quiet_hours: { id: 5, school_start: null, school_end: null, bedtime_start: '21:00', bedtime_end: '07:00', is_active: true, saved: true },
    });
    renderControls();
    await flush();

    expect(screen.getByTestId('qh-not-saved')).toHaveTextContent(QUIET_HOURS_STRINGS.defaultsApply);
    // Pre-filled with the server default: night 21:00–07:00, no school window.
    expect(screen.getByTestId('qh-bed-start').props.value).toBe('21:00');
    expect(screen.getByTestId('qh-bed-end').props.value).toBe('07:00');
    expect(screen.getByTestId('qh-school-start').props.value).toBe('');
    expect(screen.getByTestId('qh-school-end').props.value).toBe('');

    fireEvent.press(screen.getByTestId('qh-save'));
    await flush();
    expect(updateQuietHours).toHaveBeenCalledWith({
      school_start: null,
      school_end: null,
      bedtime_start: '21:00',
      bedtime_end: '07:00',
      is_active: true,
    });
    expect(screen.queryByTestId('qh-not-saved')).toBeNull();
    expect(screen.getByTestId('qh-message')).toHaveTextContent(QUIET_HOURS_STRINGS.saved);
  });

  it('shows no "not saved" notice for stored quiet hours — also when they are switched off', async () => {
    (api.getQuietHours as jest.Mock).mockResolvedValue({
      quiet_hours: { id: 1, school_start: null, school_end: null, bedtime_start: '22:00', bedtime_end: '06:00', is_active: false, saved: true },
      timezone: 'Europe/Ljubljana',
    });
    renderControls();
    await flush();

    expect(screen.queryByTestId('qh-not-saved')).toBeNull();
    expect(screen.getByTestId('qh-bed-start').props.value).toBe('22:00');
  });

  it('shows no "not saved" notice while loading or after a load error', async () => {
    (api.getQuietHours as jest.Mock).mockRejectedValue(new Error('offline'));
    renderControls();
    expect(screen.queryByTestId('qh-not-saved')).toBeNull();
    await flush();
    expect(screen.queryByTestId('qh-not-saved')).toBeNull();
  });
});

describe('ControlsScreen — family (invite / join)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    (api.getQuietHours as jest.Mock).mockResolvedValue({ quiet_hours: null });
  });

  it('invites a second parent: code, expiry in family time, share sheet', async () => {
    const shareSpy = jest.spyOn(Share, 'share').mockResolvedValue({ action: 'sharedAction' });
    inviteParent.mockResolvedValueOnce({ code: 'K7QM2XPA', expires_at: '2026-10-05T12:30:00+00:00', expires_in_hours: 24 });
    renderControls();
    await flush();

    expect(screen.getByTestId('family-parent-1')).toHaveTextContent(/Starš \(vi\)/);
    fireEvent.press(screen.getByTestId('invite-parent'));
    await flush();
    expect(inviteParent).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('invite-code')).toHaveTextContent('K7QM 2XPA');
    expect(screen.getByTestId('invite-expiry')).toHaveTextContent(FAMILY_PARENTS_STRINGS.validUntil('5. 10. ob 14:30'));

    fireEvent.press(screen.getByTestId('invite-share'));
    expect(shareSpy).toHaveBeenCalledTimes(1);
    const message = (shareSpy.mock.calls[0][0] as { message: string }).message;
    expect(message).toContain('K7QM 2XPA');
    expect(message).not.toMatch(/Luka|Maja/); // no child data in the share text
    shareSpy.mockRestore();
  });

  it('invite rate limit is explained', async () => {
    inviteParent.mockRejectedValueOnce(new ApiError('Too Many Attempts.', 429, null, 3600));
    renderControls();
    await flush();
    fireEvent.press(screen.getByTestId('invite-parent'));
    await flush();
    expect(screen.getByTestId('invite-error')).toHaveTextContent(FAMILY_PARENTS_STRINGS.errors.too_many);
  });

  it('join is offered only while the family is empty', async () => {
    renderControls();
    await flush();
    expect(screen.queryByTestId('join-family')).toBeNull();
  });

  it('a successful join is announced by the parent screen (notice survives the card)', async () => {
    joinFamily.mockResolvedValueOnce({
      message: 'You joined the family.',
      family: { id: 9, timezone: 'Europe/Ljubljana', parents: [{ id: 3, name: 'Ana' }, { id: 1, name: 'Starš' }], children_count: 1, pets_count: 1 },
    });
    const onNotice = jest.fn();
    const view = renderWithQuery(
      <ControlsScreen onBack={jest.fn()} family={EMPTY} onAddChild={jest.fn()} onChildPin={jest.fn()} onNotice={onNotice} />,
    );
    await flush();
    fireEvent.changeText(screen.getByTestId('join-code-input'), 'K7QM2XPA');
    fireEvent.press(screen.getByTestId('join-submit'));
    await flush();
    expect(onNotice).toHaveBeenCalledWith({ kind: 'joined', parents: 2 });

    // The family is no longer empty → join card gone, the lifted notice stays.
    rerenderWith(
      view,
      <ControlsScreen
        onBack={jest.fn()}
        family={FAMILY}
        onAddChild={jest.fn()}
        onChildPin={jest.fn()}
        notice={{ kind: 'joined', parents: 2 }}
        onNotice={onNotice}
      />,
    );
    expect(screen.queryByTestId('join-family')).toBeNull();
    expect(screen.getByTestId('parent-notice')).toHaveTextContent(new RegExp(JOIN_FAMILY_STRINGS.joined(2).replace(/[()]/g, '\\$&')));
    fireEvent.press(screen.getByLabelText('Zapri'));
    expect(onNotice).toHaveBeenLastCalledWith(null);
  });

  it('joins with a normalised code', async () => {
    joinFamily.mockResolvedValueOnce({
      message: 'You joined the family.',
      family: { id: 9, timezone: 'Europe/Ljubljana', parents: [{ id: 3, name: 'Ana' }, { id: 1, name: 'Starš' }], children_count: 1, pets_count: 1 },
    });
    renderControls(EMPTY);
    await flush();
    fireEvent.changeText(screen.getByTestId('join-code-input'), ' k7qm 2xpa ');
    fireEvent.press(screen.getByTestId('join-submit'));
    await flush();
    expect(joinFamily).toHaveBeenCalledWith('K7QM2XPA');
    expect(screen.getByTestId('join-message')).toHaveTextContent(JOIN_FAMILY_STRINGS.joined(2));
  });

  it.each([
    [new ApiError('x', 409, { reason: 'family_not_empty' }), JOIN_FAMILY_STRINGS.errors.family_not_empty],
    [new ApiError('x', 409, { reason: 'already_member' }), JOIN_FAMILY_STRINGS.errors.already_member],
    [new ApiError('x', 422, { reason: 'invalid_code' }), JOIN_FAMILY_STRINGS.errors.invalid_code],
    [new ApiError('x', 422, { reason: 'code_expired' }), JOIN_FAMILY_STRINGS.errors.code_expired],
    [new ApiError('x', 422, { reason: 'code_used' }), JOIN_FAMILY_STRINGS.errors.code_used],
    [new ApiError('x', 429, { reason: 'too_many_attempts' }), JOIN_FAMILY_STRINGS.errors.too_many_attempts],
    [new ApiError('Too Many Attempts.', 429, { message: 'Too Many Attempts.' }, 60), JOIN_FAMILY_STRINGS.errors.rate_limited],
    [new ApiError('x', 403, { reason: 'not_a_parent' }), JOIN_FAMILY_STRINGS.errors.not_a_parent],
    [new ApiError('x', 409, { reason: 'something_new' }), JOIN_FAMILY_STRINGS.errors.server],
    [new TypeError('Network request failed'), JOIN_FAMILY_STRINGS.errors.offline],
  ])('join error %#', async (error, text) => {
    joinFamily.mockRejectedValueOnce(error);
    renderControls(EMPTY);
    await flush();
    fireEvent.changeText(screen.getByTestId('join-code-input'), 'ABCDEFGH');
    fireEvent.press(screen.getByTestId('join-submit'));
    await flush();
    expect(screen.getByTestId('join-message')).toHaveTextContent(text);
  });

  it('a malformed code is refused locally (no request)', async () => {
    renderControls(EMPTY);
    await flush();
    fireEvent.changeText(screen.getByTestId('join-code-input'), 'ab!');
    fireEvent.press(screen.getByTestId('join-submit'));
    expect(screen.getByTestId('join-message')).toHaveTextContent(JOIN_FAMILY_STRINGS.errors.invalid_format);
    expect(joinFamily).not.toHaveBeenCalled();
  });

  it('join is also offered without any family yet', async () => {
    renderControls(null);
    await flush();
    expect(screen.getByTestId('join-family')).toBeTruthy();
    expect(screen.queryByTestId('family-parents')).toBeNull();
  });
});

describe('ControlsScreen — "Nakupi / izziv" row (M5-F01)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    (api.getQuietHours as jest.Mock).mockResolvedValue({ quiet_hours: null });
  });

  it('a family with a challenge dog gets the row; it opens the paywall', async () => {
    const trial: FamilyOverview = {
      ...FAMILY,
      pets: FAMILY.pets.map((p) =>
        p.id === 7 ? { ...p, breed_type: 'border_collie', plan: { type: 'challenge', status: 'trial', trial_ends_at: null, paid_at: null } } : p,
      ),
    };
    const onOpenChallenge = jest.fn();
    renderWithQuery(
      <ControlsScreen onBack={jest.fn()} family={trial} onAddChild={jest.fn()} onChildPin={jest.fn()} onOpenChallenge={onOpenChallenge} />,
    );
    await flush();
    expect(screen.getByText('Nakupi / izziv')).toBeTruthy();
    expect(screen.getByTestId('controls-purchases-subtitle')).toHaveTextContent('1 pes čaka na nakup');
    fireEvent.press(screen.getByTestId('controls-purchases'));
    expect(onOpenChallenge).toHaveBeenCalledTimes(1);
  });

  it('a mutt-only family on the free plan has no row', async () => {
    const free: FamilyOverview = {
      ...FAMILY,
      pets: FAMILY.pets.map((p) => ({ ...p, breed_type: 'mutt', plan: { type: 'free', status: null, trial_ends_at: null, paid_at: null } })),
    };
    renderWithQuery(<ControlsScreen onBack={jest.fn()} family={free} onAddChild={jest.fn()} onChildPin={jest.fn()} onOpenChallenge={jest.fn()} />);
    await flush();
    expect(screen.queryByTestId('controls-purchases')).toBeNull();
  });
});
