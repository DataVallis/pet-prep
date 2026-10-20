/**
 * Runs before every test (after the Jest globals exist): the child-pet fetch gate is a
 * module singleton (hotfix 2026-10-06) — a test must not inherit another test's tokens
 * or 429 block — and so is the server-outage state (M5-F13).
 */
import { channelAuthGate, childPetFetchGate } from '@/modules/childPet/refetchGovernor';
import { useServerStatusStore } from '@/store/serverStatusStore';
import { setMediaFileStoreForTests } from '@/modules/petMedia/mediaFileCache';

beforeEach(() => {
  childPetFetchGate.reset();
  channelAuthGate.reset();
  // M5-F13: the "server is updating" banner state is a module singleton too.
  useServerStatusStore.getState().reset();
  // Local media copies (2026-10-20): off by default (remote URLs); tests opt in with a fake store.
  setMediaFileStoreForTests(null);
});
