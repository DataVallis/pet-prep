import { buildReport, type AppErrorReport } from '@/modules/errorReporting/errorReport';
import { DEDUPE_MS, enqueueReport, loadQueue, MAX_QUEUE, peekReport, queuedCount, removeReport, setQueueFileStoreForTests, type QueueFileStore } from '@/modules/errorReporting/errorQueue';

function fakeFile(initial: string | null = null): QueueFileStore & { content: string | null } {
  const file = {
    content: initial,
    read: () => file.content,
    write: (content: string) => {
      file.content = content;
    },
  };
  return file;
}

function report(message: string): AppErrorReport {
  return buildReport({
    scope: 'global',
    error: new Error(message),
    route: null,
    identity: { appVersion: '3.1.4', build: null, runtimeVersion: null, updateId: null, platform: 'android', osVersion: '35' },
    now: new Date('2026-10-24T10:00:00Z'),
  });
}

afterEach(() => setQueueFileStoreForTests(null));

describe('error queue', () => {
  it('keeps at most 20 reports and drops the oldest', () => {
    setQueueFileStoreForTests(fakeFile());
    for (let i = 0; i < MAX_QUEUE + 5; i++) enqueueReport(report(`e${i}`), `k${i}`, 1_000 + i);
    expect(queuedCount()).toBe(MAX_QUEUE);
    expect(peekReport()?.message).toBe('e5');
  });

  it('skips the same error within 10 minutes, queues it again after', () => {
    setQueueFileStoreForTests(fakeFile());
    expect(enqueueReport(report('same'), 'k', 0)).toBe(true);
    expect(enqueueReport(report('same'), 'k', DEDUPE_MS - 1)).toBe(false);
    expect(enqueueReport(report('same'), 'k', DEDUPE_MS)).toBe(true);
    expect(queuedCount()).toBe(2);
  });

  it('persists synchronously and survives a restart (crash)', () => {
    const file = fakeFile();
    setQueueFileStoreForTests(file);
    enqueueReport(report('crash'), 'k', 0);
    expect(file.content).toContain('crash');

    setQueueFileStoreForTests(file); // new JS start
    expect(peekReport()?.message).toBe('crash');
    // Dedupe survives too.
    expect(enqueueReport(report('crash'), 'k', 1)).toBe(false);
  });

  it('removes a sent report', () => {
    setQueueFileStoreForTests(fakeFile());
    enqueueReport(report('a'), 'a', 0);
    enqueueReport(report('b'), 'b', 0);
    removeReport(peekReport()!);
    expect(peekReport()?.message).toBe('b');
  });

  it('treats a broken file as empty and a failing disk as memory-only', () => {
    setQueueFileStoreForTests(fakeFile('{not json'));
    expect(loadQueue().reports).toEqual([]);

    setQueueFileStoreForTests({
      read: () => {
        throw new Error('io');
      },
      write: () => {
        throw new Error('disk full');
      },
    });
    expect(() => enqueueReport(report('x'), 'x', 0)).not.toThrow();
    expect(queuedCount()).toBe(1);
  });
});
