/**
 * M2-01a invite / join helpers and M2-05 live dashboard patching.
 */
import { QueryClient } from '@tanstack/react-query';

import { ApiError, type ParentDashboardResponse } from '@/api/client';
import {
  classifyInviteError,
  classifyJoinError,
  expiryText,
  formatInviteCode,
  inviteShareMessage,
  normalizeInviteCode,
} from '@/modules/family/invite';
import {
  PARENT_LIVE_REFRESH_MS,
  PARENT_POLL_MS,
  TICK_REFETCH_THROTTLE_MS,
  applyParentBroadcast,
  createParentBroadcastHandler,
  livePollInterval,
  needsRefetch,
  parentDashboardKey,
  patchDashboardPet,
  setDashboardHardStop,
} from '@/modules/family/live';
import { familyFromDashboard } from '@/modules/family/family';
import { makeBroadcast, makeFamilyPet, makeMedia, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';

describe('invite helpers', () => {
  it('normalises what the parent typed (no inner spaces reach the backend)', () => {
    expect(normalizeInviteCode(' abcd efgh ')).toBe('ABCDEFGH');
    expect(normalizeInviteCode('ABCD-EFGH')).toBe('ABCDEFGH');
    expect(normalizeInviteCode('abc')).toBeNull();
    expect(normalizeInviteCode('ABCD EFG!')).toBeNull();
  });

  it('formats the code, its expiry in family time, and the share text without child data', () => {
    expect(formatInviteCode('ABCDEFGH')).toBe('ABCD EFGH');
    expect(expiryText('2026-10-05T12:30:00Z', 'Europe/Ljubljana')).toBe('5. 10. ob 14:30');
    const msg = inviteShareMessage('ABCDEFGH', '5. 10. ob 14:30');
    expect(msg).toContain('ABCD EFGH');
    expect(msg).toContain('Koda velja do 5. 10. ob 14:30.');
  });

  it.each([
    [new ApiError('x', 422, { reason: 'invalid_code' }), 'invalid_code'],
    [new ApiError('x', 422, { reason: 'code_expired' }), 'code_expired'],
    [new ApiError('x', 422, { reason: 'code_used' }), 'code_used'],
    [new ApiError('x', 422, { message: 'The code field format is invalid.', errors: {} }), 'invalid_format'],
    [new ApiError('x', 409, { reason: 'already_member' }), 'already_member'],
    [new ApiError('x', 409, { reason: 'family_not_empty' }), 'family_not_empty'],
    [new ApiError('x', 429, { reason: 'too_many_attempts' }), 'too_many_attempts'],
    [new ApiError('Too Many Attempts.', 429, { message: 'Too Many Attempts.' }), 'rate_limited'],
    [new ApiError('x', 403, { reason: 'not_a_parent' }), 'not_a_parent'],
    [new ApiError('x', 409, { reason: 'merge_requested' }), 'server'],
    [new ApiError('x', 409, null), 'server'],
    [new ApiError('x', 500, null), 'server'],
    [new TypeError('Network request failed'), 'offline'],
  ])('join error %#', (error, kind) => {
    expect(classifyJoinError(error)).toBe(kind);
  });

  it('invite errors', () => {
    expect(classifyInviteError(new ApiError('x', 429))).toBe('too_many');
    expect(classifyInviteError(new ApiError('x', 500))).toBe('server');
    expect(classifyInviteError(new Error('offline'))).toBe('offline');
  });
});

describe('live dashboard', () => {
  const data = makeScoredDashboard([makeScoredChild()], [makeFamilyPet({ id: 7 }), makeFamilyPet({ id: 8 })]) as unknown as ParentDashboardResponse;

  it('polls every 30 s unless every channel is connected; then refreshes every 3 min', () => {
    expect(livePollInterval('connected')).toBe(PARENT_LIVE_REFRESH_MS);
    expect(PARENT_LIVE_REFRESH_MS).toBe(180_000);
    expect(livePollInterval('reconnecting')).toBe(PARENT_POLL_MS);
    expect(livePollInterval('disconnected')).toBe(30_000);
  });

  it('patches only the broadcast pet, immutably', () => {
    const event = makeBroadcast({ pet_id: 8, hunger_level: 12, is_hard_stopped: true, event_type: 'hard_stop_activated' });
    const next = patchDashboardPet(data, event);
    const family = familyFromDashboard(next);
    expect(family?.pets.find((p) => p.id === 8)).toMatchObject({ metrics: { hunger: 12 }, is_hard_stopped: true });
    expect(family?.pets.find((p) => p.id === 7)?.metrics.hunger).toBe(100);
    expect(familyFromDashboard(data)?.pets.find((p) => p.id === 8)?.is_hard_stopped).toBe(false);
    expect(patchDashboardPet(data, makeBroadcast({ pet_id: 99 }))).toBe(data);
    expect(patchDashboardPet(undefined, event)).toBeUndefined();
  });

  it('carries the freshly signed media of the broadcast (M4-05); keeps it when absent', () => {
    const media = makeMedia({ status: 'partial', reference_image_url: 'https://api.petprep.si/api/media/1?v=a&signature=s' });
    const next = patchDashboardPet(data, makeBroadcast({ pet_id: 7, event_type: 'reference_image_ready', media }));
    expect(familyFromDashboard(next)?.pets.find((p) => p.id === 7)?.media).toEqual(media);
    const kept = patchDashboardPet(data, makeBroadcast({ pet_id: 7 }));
    expect(familyFromDashboard(kept)?.pets.find((p) => p.id === 7)?.media).toEqual(makeMedia());
  });

  it('sets the hard-stop flag from the toggle answer', () => {
    expect(familyFromDashboard(setDashboardHardStop(data, 7, true))?.pets[0].is_hard_stopped).toBe(true);
  });

  it('a decay tick only patches; any other event also refetches dashboard, timeline and reports', () => {
    const client = new QueryClient();
    client.setQueryData(parentDashboardKey, data);
    const invalidate = jest.spyOn(client, 'invalidateQueries').mockResolvedValue();

    applyParentBroadcast(client, makeBroadcast({ pet_id: 7, hunger_level: 40, event_type: 'metric_changed' }));
    expect(familyFromDashboard(client.getQueryData(parentDashboardKey))?.pets[0].metrics.hunger).toBe(40);
    expect(invalidate).not.toHaveBeenCalled();
    expect(needsRefetch(makeBroadcast({ event_type: 'metric_changed' }))).toBe(false);

    applyParentBroadcast(client, makeBroadcast({ pet_id: 7, event_type: 'fed_pet' }));
    expect(invalidate.mock.calls.map((c) => c[0]?.queryKey)).toEqual([
      ['parent', 'dashboard'],
      ['parent', 'activities', 7],
      ['parent', 'childReport'],
    ]);
    client.clear();
  });

  it('throttles decay-tick refetches to one per minute; actions always refetch', () => {
    const client = new QueryClient();
    client.setQueryData(parentDashboardKey, data);
    const invalidate = jest.spyOn(client, 'invalidateQueries').mockResolvedValue();
    let t = 1_000_000;
    const handle = createParentBroadcastHandler(client, () => t);
    const dashboardRefetches = () => invalidate.mock.calls.filter((c) => c[0]?.queryKey?.[1] === 'dashboard').length;

    handle(makeBroadcast({ pet_id: 7, hunger_level: 90, event_type: 'metric_changed' }));
    expect(dashboardRefetches()).toBe(1);
    t += 30_000;
    handle(makeBroadcast({ pet_id: 7, hunger_level: 80, event_type: 'metric_changed' }));
    expect(dashboardRefetches()).toBe(1);
    expect(familyFromDashboard(client.getQueryData(parentDashboardKey))?.pets[0].metrics.hunger).toBe(80);
    t += TICK_REFETCH_THROTTLE_MS;
    handle(makeBroadcast({ pet_id: 7, hunger_level: 70, event_type: 'metric_changed' }));
    expect(dashboardRefetches()).toBe(2);

    // An action refetches at once and restarts the tick window.
    t += 1_000;
    handle(makeBroadcast({ pet_id: 7, event_type: 'fed_pet' }));
    expect(dashboardRefetches()).toBe(3);
    t += 10_000;
    handle(makeBroadcast({ pet_id: 7, event_type: 'metric_changed' }));
    expect(dashboardRefetches()).toBe(3);
    client.clear();
  });
});
