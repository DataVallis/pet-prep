/**
 * Child HUD device fixes (TestFlight 3.0.0, 2026-10-07):
 * - M5-F05: the header is one short line; a tap opens a sheet with the full profile.
 * - M5-F06: "Kuža se pripravlja …" is stacked above the dock (top of the above-dock column,
 *   above "Obroki danes"), not drawn on the media where the meals row / dock covered it.
 */
import { fireEvent, screen, within } from '@testing-library/react-native';

import { api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { PET_MEDIA_STRINGS } from '@/components/PetMediaView';
import { profileHeaderA11y, profileSheetRows } from '@/modules/petProfile/profileSheet';
import { readPetProfile } from '@/modules/petProfile/petProfile';
import { useAppStore } from '@/store/appStore';
import { i18n } from '@/i18n';
import { makeLegacyPetProfile, makeLiveChildState, makeMedia, makePet, makePetProfile } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, getChildPet: jest.fn(), getChildPetGrowth: jest.fn(), syncSteps: jest.fn(), logout: jest.fn() },
  };
});
// iPhone SE / mini width class; height per test.
const mockWindow = { width: 375, height: 667, scale: 2, fontScale: 1 };
jest.mock('react-native/Libraries/Utilities/useWindowDimensions', () => ({ __esModule: true, default: () => mockWindow }));
jest.mock('@/hooks/usePetWebSocket', () => ({ usePetWebSocket: jest.fn() }));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(20_000);

const getChildPet = api.getChildPet as jest.Mock;

async function renderHud(state: ReturnType<typeof makeLiveChildState>) {
  getChildPet.mockResolvedValue(state);
  const utils = renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('action-feed');
  return utils;
}

beforeEach(() => {
  jest.clearAllMocks();
  mockWindow.height = 667;
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({
    token: 'child-token',
    user: { id: 2, name: 'Maja', email: null, role: 'child' },
    pet: makePet({ id: 7 }),
    awaitingContract: false,
  });
  useAppStore.getState().setWsStatus('connected');
});

describe('profileSheetRows (M5-F05)', () => {
  it('lists breed, stage, age, origin, next stage and meals in full', () => {
    const info = readPetProfile(makePetProfile({ age_months: 2 }))!;
    const rows = profileSheetRows('Border Collie', info);
    expect(rows.map((r) => [r.key, r.label, r.value])).toEqual([
      ['breed', 'Pasma', 'Border Collie'],
      ['stage', 'Faza', 'Mladiček'],
      ['age', 'Starost', '2 meseca'],
      ['origin', 'Od kod je', 'Kupljen pri vzreditelju'],
      ['nextStage', 'Naslednja faza', '24. 11. 2026 postane mlad pes'],
      ['meals', 'Obroki na dan', 'Danes 4 obroki — 1 v tihih urah nahrani starš'],
    ]);
    expect(profileHeaderA11y(rows)).toBe(
      'Border Collie, Mladiček, 2 meseca, Kupljen pri vzreditelju, 24. 11. 2026 postane mlad pes, Danes 4 obroki — 1 v tihih urah nahrani starš',
    );
  });

  it('leaves out what is unknown or unverified (no guesses)', () => {
    const info = readPetProfile(
      makePetProfile({ life_stage: 'senior', origin: null, next_stage: null, age_months: 120, unverified: ['meals_per_day'] }),
    )!;
    expect(profileSheetRows('Mešanček', info).map((r) => r.key)).toEqual(['breed', 'stage', 'age']);
  });

  it('English labels', async () => {
    await i18n.changeLanguage('en');
    try {
      const info = readPetProfile(makePetProfile({ age_months: 2 }))!;
      const rows = profileSheetRows('Border Collie', info);
      expect(rows.map((r) => r.label)).toEqual(['Breed', 'Stage', 'Age', "Where it's from", 'Next stage', 'Meals per day']);
      expect(rows.find((r) => r.key === 'nextStage')?.value).toBe('Grows into a young dog on 24 Nov 2026');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });
});

describe('ChildHudScreen header sheet (M5-F05)', () => {
  it('a tap on the header opens the full profile; "Zapri" closes it', async () => {
    await renderHud(makeLiveChildState({ pet: { breed_type: 'border_collie', profile: makePetProfile({ age_months: 2 }) } }));
    const header = screen.getByTestId('hud-profile-open');
    expect(header.props.accessibilityRole).toBe('button');
    expect(header.props.accessibilityLabel).toContain('Kupljen pri vzreditelju');
    // One short line in the header — the full text lives in the sheet.
    expect(screen.getByTestId('hud-profile-sub').props.numberOfLines).toBe(1);
    expect(screen.queryByTestId('hud-profile-sheet')).toBeNull();

    fireEvent.press(header);
    const sheet = screen.getByTestId('hud-profile-sheet');
    expect(within(sheet).getByText('24. 11. 2026 postane mlad pes')).toBeTruthy();
    expect(within(sheet).getByText('Danes 4 obroki — 1 v tihih urah nahrani starš')).toBeTruthy();
    expect(within(screen.getByTestId('hud-profile-sheet-origin')).getByText('Kupljen pri vzreditelju')).toBeTruthy();

    fireEvent.press(screen.getByTestId('hud-profile-sheet-close'));
    expect(screen.queryByTestId('hud-profile-sheet')).toBeNull();
  });

  it('a legacy pet (no profile) keeps a plain header without a sheet', async () => {
    await renderHud(makeLiveChildState({ pet: { profile: makeLegacyPetProfile() } }));
    const header = screen.getByTestId('hud-profile-open');
    expect(header.props.accessibilityRole).toBeUndefined();
    // No sheet to read it in → the breed name may wrap instead of being cut.
    expect(within(header).getByText('Mešanček').props.numberOfLines).toBe(2);
    fireEvent.press(header);
    expect(screen.queryByTestId('hud-profile-sheet')).toBeNull();
  });
});

describe('ChildHudScreen "getting ready" notice (M5-F06)', () => {
  it('sits at the top of the above-dock column, above the meals row, at 375 × 667', async () => {
    await renderHud(makeLiveChildState({ pet: { profile: makePetProfile(), media: makeMedia({ status: 'pending' }) } }));
    const column = screen.getByTestId('hud-behaviour');
    const notice = within(column).getByTestId('hud-pet-media-pending');
    expect(within(notice).getByText(PET_MEDIA_STRINGS.pending)).toBeTruthy();
    // Nothing on the media itself any more (that copy sat behind the dock).
    expect(within(screen.getByTestId('hud-pet-media')).queryByText(PET_MEDIA_STRINGS.pending)).toBeNull();
    // Top of the column = furthest from the dock; the meals row comes after it.
    // Queries return host elements in document order.
    const order: string[] = within(column)
      .getAllByTestId(/^hud-(pet-media-pending|meals(-compact)?)$/)
      .map((el) => String(el.props.testID));
    expect(order[0]).toBe('hud-pet-media-pending');
    expect(order.some((id) => id.startsWith('hud-meals'))).toBe(true);
    // The column is positioned from the measured dock height (layout.aboveDock), never under it.
    const style = Array.isArray(column.props.style) ? Object.assign({}, ...column.props.style) : column.props.style;
    expect(style.bottom).toBeGreaterThan(134);
  });

  it('large fonts: the notice text is capped (≤ 2 lines, ≤ 1.5× growth) so the column cannot reach the header', async () => {
    mockWindow.fontScale = 2;
    try {
      await renderHud(makeLiveChildState({ pet: { profile: makePetProfile(), media: makeMedia({ status: 'pending' }) } }));
      const text = within(screen.getByTestId('hud-pet-media-pending')).getByText(PET_MEDIA_STRINGS.pending);
      expect(text.props.numberOfLines).toBe(2);
      expect(text.props.maxFontSizeMultiplier).toBe(1.5);
    } finally {
      mockWindow.fontScale = 1;
    }
  });

  it('under a translucent lock (hard stop) the panel is hidden, so the notice stays on the media', async () => {
    await renderHud(
      makeLiveChildState({
        pet: { profile: makePetProfile(), media: makeMedia({ status: 'pending' }), is_hard_stopped: true },
        lock: { is_locked: true, reason: 'hard_stopped' },
      }),
    );
    expect(screen.queryByTestId('hud-behaviour')).toBeNull();
    expect(within(screen.getByTestId('hud-pet-media')).getByText(PET_MEDIA_STRINGS.pending)).toBeTruthy();
  });

  it('ready media: no notice', async () => {
    await renderHud(makeLiveChildState({ pet: { profile: makePetProfile(), media: makeMedia({ status: 'ready' }) } }));
    expect(screen.queryByTestId('hud-pet-media-pending')).toBeNull();
  });
});
