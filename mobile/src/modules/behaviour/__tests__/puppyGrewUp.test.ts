/**
 * »{{name}} je zrasel!« (David 2026-11-27) — the rule is pure; the pet-local »tomorrow« comes
 * from the pet's zone (`view.timezone`), never the phone's (Jest runs in UTC).
 */
import { act, renderHook, waitFor } from '@testing-library/react-native';

import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import {
  markPuppyGrewUpDismissed,
  puppyGrewUpFor,
  puppyGrewUpShort,
  puppyGrewUpText,
  setPuppyGrewUpStoreForTests,
  usePuppyGrewUp,
  wasPuppyGrewUpDismissed,
} from '@/modules/behaviour/puppyGrewUp';
import { makeLiveChildState, makePetProfile, makeTakeOut, type LiveStateOverrides } from '@/test-utils/fixtures';

// 2026-11-27 20:30 in Ljubljana (UTC+1) — the evening of Bafo's last puppy day.
const LAST_EVENING = '2026-11-27T19:30:00Z';

function view(o: LiveStateOverrides & { profile?: Parameters<typeof makePetProfile>[0] } = {}) {
  const { profile, ...rest } = o;
  return normalizeChildState(
    makeLiveChildState({
      server_time: '2026-11-27T20:30:00+01:00',
      behaviour: { take_out: null },
      ...rest,
      pet: {
        name: 'Bafo',
        species: 'dog',
        breed_type: 'mutt',
        profile: makePetProfile({
          age_months: 8,
          life_stage: 'puppy',
          behaviour_enabled: true,
          next_stage: { life_stage: 'young', from_date: '2026-11-28' },
          ...profile,
        }),
        ...rest.pet,
      },
    }),
  );
}

describe('puppyGrewUpFor', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.setSystemTime(new Date(LAST_EVENING));
  });
  afterEach(() => jest.useRealTimers());

  it('shows on the last puppy day once the potty clock is gone (young from the next pet-local day)', () => {
    expect(puppyGrewUpFor(view(), Date.now())).toEqual({ petId: expect.any(Number), fromDate: '2026-11-28', name: 'Bafo' });
  });

  it('never while the take-out clock still runs (before the evening take-out)', () => {
    expect(puppyGrewUpFor(view({ behaviour: { take_out: makeTakeOut({ kind: 'evening' }) } }), Date.now())).toBeNull();
  });

  it('only when the next stage starts TOMORROW — a puppy with days left gets nothing', () => {
    expect(puppyGrewUpFor(view({ profile: { next_stage: { life_stage: 'young', from_date: '2026-11-29' } } }), Date.now())).toBeNull();
    expect(puppyGrewUpFor(view({ profile: { next_stage: null } }), Date.now())).toBeNull();
  });

  it('is gone after the pet-local midnight even with a stale profile (then it is a young dog)', () => {
    jest.setSystemTime(new Date('2026-11-27T23:05:00Z')); // 00:05 on the 28th in Ljubljana
    expect(puppyGrewUpFor(view(), Date.now())).toBeNull();
  });

  it("uses the PET's zone for »tomorrow«, not UTC / the phone", () => {
    // 19:00 on the 27th in Los Angeles is already the 28th in UTC.
    jest.setSystemTime(new Date('2026-11-28T03:00:00Z'));
    const la = view({ timezone: 'America/Los_Angeles', server_time: '2026-11-27T19:00:00-08:00' });
    expect(puppyGrewUpFor(la, Date.now())?.fromDate).toBe('2026-11-28');
    // The same instant for a pet in Ljubljana (04:00 on the 28th): young from today → nothing.
    expect(puppyGrewUpFor(view(), Date.now())).toBeNull();
  });

  it('never for a pet that never had the potty clock: cat, no behaviour events, young / adult, legacy', () => {
    expect(puppyGrewUpFor(view({ pet: { species: 'cat', breed_type: 'domestic_cat' } }), Date.now())).toBeNull();
    expect(puppyGrewUpFor(view({ profile: { behaviour_enabled: false } }), Date.now())).toBeNull();
    expect(
      puppyGrewUpFor(view({ profile: { life_stage: 'young', next_stage: { life_stage: 'adult', from_date: '2026-11-28' } } }), Date.now()),
    ).toBeNull();
    expect(puppyGrewUpFor(view({ profile: { legacy: true } }), Date.now())).toBeNull();
  });

  it('never under a lock', () => {
    expect(puppyGrewUpFor(view({ lock: { is_locked: true, reason: 'hard_stopped' } }), Date.now())).toBeNull();
  });

  it('texts: with the name, and the nameless fallback', () => {
    const card = puppyGrewUpFor(view(), Date.now())!;
    expect(puppyGrewUpText(card)).toBe('Bafo je zrasel! Od jutri ga ni treba več peljati ven na potrebo — zdaj štejejo sprehodi.');
    expect(puppyGrewUpShort(card)).toBe('Bafo je zrasel!');
    expect(puppyGrewUpText({ ...card, name: null })).toBe('Kuža je zrasel! Od jutri ga ni treba več peljati ven na potrebo — zdaj štejejo sprehodi.');
    expect(puppyGrewUpShort({ ...card, name: null })).toBe('Kuža je zrasel!');
  });
});

describe('device memory', () => {
  it('remembers a dismissal per pet + boundary date', async () => {
    expect(await wasPuppyGrewUpDismissed({ petId: 7, fromDate: '2026-11-28' })).toBe(false);
    await markPuppyGrewUpDismissed({ petId: 7, fromDate: '2026-11-28' });
    expect(await wasPuppyGrewUpDismissed({ petId: 7, fromDate: '2026-11-28' })).toBe(true);
    expect(await wasPuppyGrewUpDismissed({ petId: 8, fromDate: '2026-11-28' })).toBe(false);
  });

  it('an unreadable store counts as dismissed (never a card on every start)', async () => {
    setPuppyGrewUpStoreForTests({
      getItemAsync: () => Promise.reject(new Error('keychain')),
      setItemAsync: () => Promise.reject(new Error('keychain')),
    });
    expect(await wasPuppyGrewUpDismissed({ petId: 7, fromDate: '2026-11-28' })).toBe(true);
    await expect(markPuppyGrewUpDismissed({ petId: 7, fromDate: '2026-11-28' })).resolves.toBeUndefined();
  });
});

describe('usePuppyGrewUp', () => {
  const now = Date.parse(LAST_EVENING);

  it('appears when take_out turns null, and × hides it for good on this device', async () => {
    const running = view({ behaviour: { take_out: makeTakeOut({ kind: 'evening' }) } });
    const { result, rerender, unmount } = renderHook(({ v }: { v: ChildPetView }) => usePuppyGrewUp(v, now), { initialProps: { v: running } });
    expect(result.current.card).toBeNull();

    rerender({ v: view() });
    await waitFor(() => expect(result.current.card?.name).toBe('Bafo'));

    act(() => result.current.dismiss());
    expect(result.current.card).toBeNull();
    unmount();

    // App opened again later the same evening (state already null): stays dismissed.
    const again = renderHook(() => usePuppyGrewUp(view(), now));
    await act(async () => {
      await Promise.resolve();
    });
    expect(again.result.current.card).toBeNull();
  });

  it('shows on app open when the take-out already happened (no live transition seen)', async () => {
    const { result } = renderHook(() => usePuppyGrewUp(view(), now));
    expect(result.current.card).toBeNull(); // hidden until the device memory is read
    await waitFor(() => expect(result.current.card).not.toBeNull());
  });
});
