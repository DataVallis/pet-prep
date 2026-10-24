import { buildReport, dedupeKey, describeThrown, MESSAGE_MAX, STACK_MAX, stripPaths, topOfStack, type AppIdentity } from '@/modules/errorReporting/errorReport';

const IDENTITY: AppIdentity = {
  appVersion: '3.1.4',
  build: 'e70d189',
  runtimeVersion: 'abc123',
  updateId: '0f8e7d6c-1111-2222-3333-444455556666',
  platform: 'ios',
  osVersion: '18.1',
};
const NOW = new Date('2026-10-24T10:00:00Z');

describe('buildReport', () => {
  it('keeps only technical facts, truncated to the server limits', () => {
    const error = new TypeError('x'.repeat(900));
    error.stack = `TypeError: boom\n${'    at fn (file:///var/mobile/Containers/Data/Application/ABC-123/main.jsbundle:1:2)\n'.repeat(80)}`;
    const report = buildReport({ scope: 'child-hud', error, route: 'child-hud', identity: IDENTITY, now: NOW, componentStack: '\n    in Hud\n    in App' });

    expect(report.message).toHaveLength(MESSAGE_MAX);
    expect(report.stack!.length).toBeLessThanOrEqual(STACK_MAX);
    expect(report.stack).not.toContain('Containers');
    expect(report.stack).toContain('main.jsbundle:1:2');
    expect(Object.keys(report).sort()).toEqual(
      ['app_version', 'build', 'fatal', 'message', 'name', 'occurred_at', 'os_version', 'platform', 'route', 'runtime_version', 'scope', 'stack', 'update_id'].sort(),
    );
    expect(report).toMatchObject({ scope: 'child-hud', name: 'TypeError', fatal: false, app_version: '3.1.4', platform: 'ios', route: 'child-hud', occurred_at: '2026-10-24T10:00:00.000Z' });
  });

  it('appends the first component-stack lines when there is room', () => {
    const stack = topOfStack('Error: a\n    at one (index.bundle:1:2)', '\n    in PetMediaView\n    in Hud');
    expect(stack).toBe('Error: a\nat one (index.bundle:1:2)\nComponent stack:\nin PetMediaView\nin Hud');
  });

  it('drops identity values the server would refuse', () => {
    const report = buildReport({ scope: 'app', error: new Error('a'), route: '/pets/12?name=Rex', identity: { ...IDENTITY, runtimeVersion: 'has space', osVersion: '' }, now: NOW });
    expect(report.route).toBeNull();
    expect(report.runtime_version).toBeNull();
    expect(report.os_version).toBeNull();
  });

  it('never serialises a thrown object (it may hold state)', () => {
    expect(describeThrown({ user: { name: 'Lukec' } })).toEqual({ name: 'NonError', message: 'non-Error value thrown (object)', stack: null });
    expect(describeThrown('plain')).toEqual({ name: 'Error', message: 'plain', stack: null });
    expect(describeThrown(undefined).message).toBe('undefined');
  });

  it('strips device paths from messages', () => {
    expect(stripPaths('cannot open /data/user/0/si.petprep/files/media/a.mp4 now')).toBe('cannot open a.mp4 now');
  });
});

describe('dedupeKey', () => {
  it('is the same for one error at another bundle offset', () => {
    const a = buildReport({ scope: 'app', error: Object.assign(new Error('m'), { stack: 'Error: m\n    at f (index.bundle:1:10)' }), route: null, identity: IDENTITY, now: NOW });
    const b = buildReport({ scope: 'app', error: Object.assign(new Error('m'), { stack: 'Error: m\n    at f (index.bundle:1:99)' }), route: null, identity: IDENTITY, now: NOW });
    const c = buildReport({ scope: 'app', error: Object.assign(new Error('m'), { stack: 'Error: m\n    at g (index.bundle:1:99)' }), route: null, identity: IDENTITY, now: NOW });
    expect(dedupeKey(a)).toBe(dedupeKey(b));
    expect(dedupeKey(a)).not.toBe(dedupeKey(c));
  });
});
