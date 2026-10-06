/**
 * Runs before every test (after the Jest globals exist): the child-pet fetch gate is a
 * module singleton (hotfix 2026-10-06) — a test must not inherit another test's tokens
 * or 429 block.
 */
import { channelAuthGate, childPetFetchGate } from '@/modules/childPet/refetchGovernor';

beforeEach(() => {
  childPetFetchGate.reset();
  channelAuthGate.reset();
});
