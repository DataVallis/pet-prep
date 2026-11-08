/**
 * Runs before every test (after the Jest globals exist): the child-pet fetch gate is a
 * module singleton (hotfix 2026-10-06) — a test must not inherit another test's tokens
 * or 429 block — and so is the server-outage state (M5-F13).
 */
import { channelAuthGate, childPetFetchGate } from '@/modules/childPet/refetchGovernor';
import { useServerStatusStore } from '@/store/serverStatusStore';
import { setMediaFileStoreForTests } from '@/modules/petMedia/mediaFileCache';
import { resetTopStackPrefsForTests, setTopStackPrefsStoreForTests } from '@/modules/hud/topStackPrefs';
import { memoryPendingStore, setPendingPurchaseStoreForTests } from '@/modules/wishes/pendingPurchases';
import { setGameLayerAvailableForTests } from '@/modules/gameLayer/capability';
import { resetZoneSuggestionForTests, setZoneSuggestionStoreForTests } from '@/modules/familyZone/zoneSuggestionPrefs';
import { setPhoneZoneForTests } from '@/modules/familyZone/familyZone';

beforeEach(() => {
  childPetFetchGate.reset();
  channelAuthGate.reset();
  // M5-F13: the "server is updating" banner state is a module singleton too.
  useServerStatusStore.getState().reset();
  // Local media copies (2026-10-20): off by default (remote URLs); tests opt in with a fake store.
  setMediaFileStoreForTests(null);
  // David 2026-10-26: the HUD top stack's collapsed state is a device-wide store — start expanded.
  setTopStackPrefsStoreForTests(null);
  resetTopStackPrefsForTests();
  // QA #416 M2: pending wish purchases live in a device store — a fresh, empty one per test.
  setPendingPurchaseStoreForTests(memoryPendingStore());
  // GAMES_SPEC §7.4: the native game layer is off (like an older binary) unless a test opts in.
  setGameLayerAvailableForTests(false);
  // David 2026-11-08: the zone banner's dismissal is a device store; the phone zone is unknown (no banner / note)
  // unless a test sets one — Jest runs in UTC, which differs from every Ljubljana fixture.
  setZoneSuggestionStoreForTests({ getItemAsync: async () => null, setItemAsync: async () => undefined });
  resetZoneSuggestionForTests();
  setPhoneZoneForTests(null);
});
