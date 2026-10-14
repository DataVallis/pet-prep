/**
 * M5-R13 — dog grooming per breed (David 2026-10-12, "kot pri mački"): a brushed dog gets the
 * cat's brushing — the "Počeši" chip from the server's `grooming` block, the same stroke
 * mini-game with the dog's words, the dog's refusal / contract / parent texts — and a dog
 * without the block (an existing dog, the mutt, an older server) sees nothing new.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { api, clientFeatures, type ChildPetState } from '@/api/client';
import CatCareOverlay from '@/modules/catCare/CatCareOverlay';
import ChoreOverlay from '@/modules/catCare/ChoreOverlay';
import { catRefusalMessage } from '@/modules/catCare/catCare';
import { careChips, catChips, ownCatGame } from '@/modules/catCare/catHud';
import { isDogGrooming } from '@/modules/catCare/dogGrooming';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { contractBody } from '@/modules/contract/contractText';
import { activityText, reportRoutineTypes, type RoutineType, type TypeTotals } from '@/modules/family/scoring';
import { GROOMING_DOG_BREEDS, hasGroomingRoutine } from '@/modules/species/species';
import { i18n, t } from '@/i18n';
import { useAppStore } from '@/store/appStore';
import { makeChoreSession, makeGroomingState, makeLiveChildState, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, startCareChore: jest.fn(), finishCareChore: jest.fn(), getChildPet: jest.fn() },
  };
});

const mocked = api as unknown as Record<string, jest.Mock>;
const T0 = Date.parse('2026-10-04T12:00:00+02:00');
const clock = { mono: () => Date.now(), wall: () => Date.now() };

/** A Rottweiler with the server's grooming block (default: 1 of 3 done, may start). */
function dogState(grooming: unknown = makeGroomingState(), o: Parameters<typeof makeLiveChildState>[0] = {}): ChildPetState {
  return makeLiveChildState({ ...o, pet: { breed_type: 'rottweiler', species: 'dog', ...o.pet } as Partial<ChildPetState['pet']>, grooming });
}

function viewOf(state: ChildPetState): ChildPetView {
  return normalizeChildState(state, 0, T0);
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

const ZERO: TypeTotals = { expected: 0, done: 0, done_by_child: 0, missed: 0, pending: 0 };
const byType = (counted: Partial<Record<RoutineType, Partial<TypeTotals>>> = {}): Record<RoutineType, TypeTotals> => {
  const all = ['feed', 'water', 'clean', 'walk', 'training', 'play', 'litter_scoop', 'litter_change', 'grooming'] as const;
  return Object.fromEntries(all.map((k) => [k, { ...ZERO, ...counted[k] }])) as Record<RoutineType, TypeTotals>;
};

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
  jest.setSystemTime(T0);
  mocked.getChildPet.mockResolvedValue(dogState());
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({ token: 't', user: { id: 2, name: 'Maja', email: null, role: 'child' }, pet: makePet(), awaitingContract: false });
});

afterEach(() => {
  jest.useRealTimers();
  jest.restoreAllMocks();
});

describe('the HUD chip and resume (server block decides)', () => {
  it('a brushed dog gets "Počeši" with the dog\'s words; the matted coat is the note', () => {
    const view = viewOf(dogState(makeGroomingState({ matted: true })));
    expect(isDogGrooming(view)).toBe(true);
    expect(catChips(view)).toEqual([]); // the cat helper stays cat-only
    expect(careChips(view)).toEqual([
      { kind: 'grooming', label: 'Počeši', a11y: 'Počeši kužka. Ta teden: 1 od 3. Zdaj lahko.', pending: true, note: 'V dlaki ima vozel — počeši ga.' },
    ]);
  });

  it('nothing new for a dog without the block, a locked dog or a finished week', () => {
    expect(careChips(viewOf(makeLiveChildState()))).toEqual([]);
    expect(isDogGrooming(viewOf(makeLiveChildState()))).toBe(false);
    expect(careChips(viewOf(dogState(makeGroomingState(), { lock: { is_locked: true, reason: 'hard_stopped' } })))).toEqual([]);
    expect(careChips(viewOf(dogState(makeGroomingState({ done_this_week: 3, can_start: false, blocked_reason: 'grooming_week_done' }))))).toEqual([]);
  });

  it('resumes the dog\'s own running brushing', () => {
    expect(ownCatGame(viewOf(dogState(makeGroomingState({ session: makeChoreSession('grooming') }))))).toEqual({
      kind: 'grooming',
      id: makeChoreSession('grooming').id,
    });
    expect(ownCatGame(viewOf(dogState()))).toBeNull();
  });
});

describe('the brushing game for a dog (ChoreOverlay, same session as the cat)', () => {
  it('words the intro, the stroke button and the result for the dog; the server verdict counts', async () => {
    const session = makeChoreSession('grooming');
    mocked.startCareChore.mockResolvedValue({ status: 'accepted', session, state: dogState() });
    mocked.finishCareChore.mockResolvedValue({
      status: 'accepted',
      result: { session_id: session.id, success: true, reason: null, strokes: 12, min_strokes: 10, segments_hit: 3, segments: 3, matted: false },
      state: dogState(makeGroomingState({ done_this_week: 2 })),
    });
    const view = viewOf(dogState(makeGroomingState({ matted: true, session_seconds: 60 })));
    renderWithQuery(<ChoreOverlay kind="grooming" view={view} onClose={jest.fn()} reduceMotion clock={clock} />);
    expect(screen.getByText('Počeši kužka')).toBeTruthy();
    expect(screen.getByText('Česanje ta teden: 1 od 3')).toBeTruthy();
    expect(screen.getByText('V dlaki ima vozel. To česanje traja malo dlje (60 s) in vozel razreši.')).toBeTruthy();

    fireEvent.press(screen.getByTestId('cat-grooming-start'));
    await advance(0);
    expect(screen.getByText('Glava in vrat')).toBeTruthy();
    expect(screen.queryByLabelText('Enkrat počeši muco')).toBeNull();
    for (const wait of [1_000, 9_500, 10_400]) {
      await advance(wait);
      fireEvent.press(screen.getByLabelText('Enkrat počeši kužka'));
    }
    await advance(10_000);
    expect(mocked.finishCareChore).toHaveBeenCalledWith('grooming', session.id, [1_000, 10_500, 20_900]);
    await advance(0);
    expect(screen.getByTestId('cat-grooming-result-title').props.children).toBe('Mehak in svetleč!');
    expect(screen.getByText('Kuža je počesan. To česanje šteje za ta teden.')).toBeTruthy();
  });

  it('a blocked dog brushing names the dog and the time', () => {
    const view = viewOf(
      dogState(makeGroomingState({ can_start: false, blocked_reason: 'grooming_quiet_hours', next_allowed_at: '2026-10-05T07:00:00+02:00' })),
    );
    renderWithQuery(<ChoreOverlay kind="grooming" view={view} onClose={jest.fn()} reduceMotion />);
    expect(screen.getByTestId('cat-grooming-blocked').props.children).toBe('Kuža zdaj spi. Počeši ga jutri ob 07:00.');
  });

  it('the host opens it for a brushed dog (and still closes it for a dog without the block)', async () => {
    useAppStore.getState().openCatGame('grooming');
    const { unmount } = renderWithQuery(<CatCareOverlay view={viewOf(dogState())} reduceMotion />);
    await advance(0);
    expect(screen.getByTestId('cat-grooming')).toBeTruthy();
    expect(useAppStore.getState().catOverlay).toBe('grooming');
    unmount();

    renderWithQuery(<CatCareOverlay view={viewOf(makeLiveChildState())} reduceMotion />);
    await advance(0);
    expect(screen.queryByTestId('cat-grooming')).toBeNull();
    expect(useAppStore.getState().catOverlay).toBeNull();
  });
});

describe('texts per species', () => {
  const ctx = { nowIso: '2026-10-04T12:00:00+02:00', timezone: 'Europe/Ljubljana' };

  it('dog refusals name the dog; neutral ones stay shared; the cat is unchanged', () => {
    expect(catRefusalMessage('grooming_session_active', null, { ...ctx, dog: true })).toBe('Kužka zdaj češe nekdo drug. Poskusi čez minuto.');
    expect(catRefusalMessage('grooming_done_today', '2026-10-05T07:00:00+02:00', { ...ctx, dog: true })).toBe('Kuža je danes že počesan. Spet ga počeši jutri ob 07:00.');
    expect(catRefusalMessage('grooming_week_done', null, { ...ctx, dog: true })).toBe('Vsa česanja ta teden so opravljena. Bravo!');
    expect(catRefusalMessage('grooming_session_active', null, ctx)).toBe('Muco zdaj češe nekdo drug. Poskusi čez minuto.');
  });

  it('the contract adds brushing only for a dog that brushes (breed routine AND grooming_enabled)', () => {
    expect(contractBody('dog', 'rottweiler', true)).toBe(t('contract:screen.bodyWithBrushing'));
    expect(contractBody('dog', 'rottweiler', true)).toContain('Redno ga bom česal, da se mu dlaka ne bo zavozlala.');
    expect(contractBody('dog', 'rottweiler', false)).toBe(t('contract:screen.body'));
    expect(contractBody('dog', 'rottweiler')).toBe(t('contract:screen.body'));
    expect(contractBody('dog', 'mutt', true)).toBe(t('contract:screen.body'));
    expect(contractBody('dog', 'border_collie', true)).toBe(t('contract:screen.body'));
    // The cat keeps its own rule.
    expect(contractBody('cat', 'maine_coon')).toBe(t('cat:contract.bodyWithBrushing'));
  });

  it('parent timeline: the dog and the cat are each named', async () => {
    expect(activityText({ activity_type: 'groomed_pet', actor_nickname: 'Ana' }, 'dog')).toBe('Ana počesal(a) kužka');
    expect(activityText({ activity_type: 'groomed_pet', actor_nickname: 'Ana' }, 'cat')).toBe('Ana počesal(a) muco');
    expect(activityText({ activity_type: 'pet_coat_matted', actor_nickname: null }, 'dog')).toBe('Kužku se je zavozlala dlaka');
    expect(activityText({ activity_type: 'pet_coat_matted', actor_nickname: null }, 'cat')).toBe('Muci se je zavozlala dlaka');
    await i18n.changeLanguage('en');
    try {
      expect(activityText({ activity_type: 'groomed_pet', actor_nickname: 'Ana' }, 'dog')).toBe('Ana brushed the dog');
      expect(activityText({ activity_type: 'groomed_pet', actor_nickname: 'Ana' }, 'cat')).toBe('Ana brushed the cat');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('parent report: "Česanje" for a dog once the server counted a slot, never for a dog without one', () => {
    expect(reportRoutineTypes('dog', 'rottweiler', byType(), false)).not.toContain('grooming');
    expect(reportRoutineTypes('dog', 'rottweiler', byType({ grooming: { expected: 1, missed: 1 } }), false)).toEqual(['feed', 'water', 'clean', 'walk', 'grooming']);
  });
});

describe('breeds and build features', () => {
  it('every brushed dog breed has a routine; the mutt and the Border Collie have none', () => {
    expect(GROOMING_DOG_BREEDS).toHaveLength(33);
    for (const breed of GROOMING_DOG_BREEDS) expect(hasGroomingRoutine(breed)).toBe(true);
    expect(hasGroomingRoutine('mutt')).toBe(false);
    expect(hasGroomingRoutine('border_collie')).toBe(false);
  });

  it('this build declares `dog_grooming` (the server enables it for a new dog only with both apps)', () => {
    expect(clientFeatures(true)).toContain('dog_grooming');
    expect(clientFeatures(false)).toContain('dog_grooming');
  });
});
