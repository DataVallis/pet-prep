import * as client from '@/api/client';
import { ApiError } from '@/api/client';
import { peekReport, queuedCount, setQueueFileStoreForTests } from '@/modules/errorReporting/errorQueue';
import { installGlobalErrorHandlers, resetGlobalHandlersForTests } from '@/modules/errorReporting/globalHandlers';
import { BACKOFF_MIN_MS, flushAppErrors, reportAppError, resetErrorReporterForTests, setErrorRoute, setErrorSession } from '@/modules/errorReporting/reporter';
import { setReportingAllowed } from '@/modules/errorReporting/errorQueue';
import { useServerStatusStore } from '@/store/serverStatusStore';
import type { AppIdentity } from '@/modules/errorReporting/errorReport';
import { logRenderError } from '@/utils/logRenderError';

const IDENTITY: AppIdentity = { appVersion: '3.1.4', build: 'e70d189', runtimeVersion: 'rt1', updateId: null, platform: 'android', osVersion: '35' };

let send: jest.SpyInstance;
let token: jest.SpyInstance;

beforeEach(() => {
  jest.useFakeTimers();
  setQueueFileStoreForTests(null);
  resetErrorReporterForTests(IDENTITY);
  resetGlobalHandlersForTests();
  setErrorSession('parent');
  send = jest.spyOn(client.api, 'reportAppError').mockResolvedValue(null);
  token = jest.spyOn(client, 'getAuthToken').mockResolvedValue('7|session');
  jest.spyOn(console, 'error').mockImplementation(() => undefined);
});

afterEach(() => {
  jest.restoreAllMocks();
  resetErrorReporterForTests(null, false);
  jest.useRealTimers();
});

describe('reportAppError', () => {
  it('queues and sends the report with the session token after a short delay', async () => {
    setErrorRoute('child-hud');
    expect(reportAppError('child-hud', new TypeError('boom'))).toBe(true);
    expect(send).not.toHaveBeenCalled();

    await jest.advanceTimersByTimeAsync(2_000);

    expect(send).toHaveBeenCalledTimes(1);
    const [body, options] = send.mock.calls[0];
    expect(body).toMatchObject({ scope: 'child-hud', name: 'TypeError', message: 'boom', route: 'child-hud', app_version: '3.1.4', platform: 'android' });
    expect(options).toEqual({ token: '7|session', timeoutMs: 10_000 });
    expect(queuedCount()).toBe(0);
  });

  it('dedupes the same error within 10 minutes on the device', async () => {
    const error = new Error('same');
    expect(reportAppError('app', error)).toBe(true);
    expect(reportAppError('app', error)).toBe(false);
    await jest.advanceTimersByTimeAsync(2_000);
    expect(send).toHaveBeenCalledTimes(1);
  });

  it('keeps reports while offline and sends them on the next flush', async () => {
    send.mockRejectedValue(new TypeError('Network request failed'));
    reportAppError('global', new Error('a'));
    reportAppError('global', new Error('b'));
    await jest.advanceTimersByTimeAsync(2_000);
    expect(send).toHaveBeenCalledTimes(1); // stops at the first failure
    expect(queuedCount()).toBe(2);

    send.mockResolvedValue(null);
    await flushAppErrors(); // still in the backoff
    expect(send).toHaveBeenCalledTimes(1);
    await jest.advanceTimersByTimeAsync(BACKOFF_MIN_MS);
    await flushAppErrors();
    expect(send).toHaveBeenCalledTimes(3);
    expect(queuedCount()).toBe(0);
  });

  it('keeps a throttled report, drops one the server refuses for good', async () => {
    send.mockRejectedValueOnce(new ApiError('Too many', 429, null, 120));
    reportAppError('global', new Error('a'));
    await flushAppErrors();
    expect(queuedCount()).toBe(1);

    // Retry-After 120 s is honoured (longer than the 60 s minimum backoff).
    await jest.advanceTimersByTimeAsync(90_000);
    await flushAppErrors();
    expect(send).toHaveBeenCalledTimes(1);
    await jest.advanceTimersByTimeAsync(31_000);

    send.mockRejectedValueOnce(new ApiError('Invalid', 422, {}));
    await flushAppErrors();
    expect(queuedCount()).toBe(0);
  });

  it('queues and sends nothing while the server switch is off, and empties the queue when it goes off', async () => {
    reportAppError('app', new Error('before'));
    setReportingAllowed(false);
    expect(queuedCount()).toBe(0);
    expect(reportAppError('app', new Error('while off'))).toBe(false);
    await jest.advanceTimersByTimeAsync(5_000);
    await flushAppErrors();
    expect(send).not.toHaveBeenCalled();
  });

  it('sends the token only while the session still has the role the report was queued under', async () => {
    setErrorSession('child');
    reportAppError('child-hud', new Error('child side'));
    setErrorSession('parent'); // profile switched before the flush
    await flushAppErrors();
    expect(send.mock.calls[0][1]).toEqual({ token: null, timeoutMs: 10_000 });
    expect(token).not.toHaveBeenCalled();

    setErrorSession(null);
    reportAppError('app', new Error('signed out'));
    setErrorSession('parent');
    await flushAppErrors();
    expect(send.mock.calls[1][1]).toEqual({ token: null, timeoutMs: 10_000 });
  });

  it('sends anonymously when the keychain fails', async () => {
    token.mockRejectedValue(new Error('keychain locked'));
    reportAppError('app', new Error('x'));
    await flushAppErrors();
    expect(send.mock.calls[0][1]).toEqual({ token: null, timeoutMs: 10_000 });
  });

  it('never throws — not even when the API call throws synchronously', async () => {
    send.mockImplementation(() => {
      throw new Error('sync boom');
    });
    expect(() => reportAppError('app', new Error('x'))).not.toThrow();
    await expect(flushAppErrors()).resolves.toBeUndefined();
    expect(queuedCount()).toBe(1);
  });

  it('does nothing while disabled (dev builds)', async () => {
    resetErrorReporterForTests(IDENTITY, false);
    expect(reportAppError('app', new Error('x'))).toBe(false);
    await jest.advanceTimersByTimeAsync(5_000);
    expect(send).not.toHaveBeenCalled();
  });
});

describe('error boundaries', () => {
  it('logRenderError queues a report with the component stack and never throws', async () => {
    logRenderError('locked-overlay', new Error('render'), { componentStack: '\n    in LockedScreen' });
    expect(peekReport()?.report).toMatchObject({ scope: 'locked-overlay', message: 'render' });
    expect(peekReport()?.report.stack).toContain('in LockedScreen');

    jest.spyOn(console, 'error').mockImplementation(() => {
      throw new Error('console gone');
    });
    expect(() => logRenderError('app', new Error('again'))).not.toThrow();
  });

  it('the root boundary sends at once', async () => {
    logRenderError('app', new Error('root'));
    await jest.advanceTimersByTimeAsync(0);
    expect(send).toHaveBeenCalledTimes(1);
  });
});

describe('global handlers', () => {
  it('reports an uncaught error and still calls the previous handler', () => {
    const previous = jest.fn();
    let handler: ((error: unknown, isFatal?: boolean) => void) | undefined;
    const errorUtils = { getGlobalHandler: () => previous, setGlobalHandler: (h: typeof handler) => (handler = h) };

    expect(installGlobalErrorHandlers({ ErrorUtils: errorUtils })).toBe(true);
    const error = new Error('fatal one');
    handler!(error, true);

    expect(previous).toHaveBeenCalledWith(error, true);
    expect(peekReport()?.report).toMatchObject({ scope: 'global', fatal: true, message: 'fatal one' });
  });

  it('still calls the previous handler when reporting fails', () => {
    setQueueFileStoreForTests({
      read: () => {
        throw new Error('io');
      },
      write: () => {
        throw new Error('io');
      },
    });
    resetErrorReporterForTests(null); // real identity read (Platform mock) — must not throw either
    const previous = jest.fn();
    let handler: ((error: unknown, isFatal?: boolean) => void) | undefined;
    installGlobalErrorHandlers({ ErrorUtils: { getGlobalHandler: () => previous, setGlobalHandler: (h) => (handler = h) } });
    expect(() => handler!('weird', false)).not.toThrow();
    expect(previous).toHaveBeenCalledWith('weird', false);
  });

  it('reports unhandled promise rejections through the Hermes tracker', () => {
    let options: { onUnhandled: (id: number, rejection?: unknown) => void } | undefined;
    installGlobalErrorHandlers({ HermesInternal: { enablePromiseRejectionTracker: (o) => (options = o) } });
    options!.onUnhandled(1, new RangeError('nope'));
    expect(peekReport()?.report).toMatchObject({ scope: 'unhandled-rejection', name: 'RangeError', fatal: false });
  });
});

describe('outage banner (QA #375 m2)', () => {
  it('an error report request never touches the server status store', async () => {
    send.mockRestore();
    const fetchSpy = jest.spyOn(global, 'fetch').mockResolvedValue(new Response(null, { status: 502 }));
    const before = useServerStatusStore.getState();
    await client.api.reportAppError(
      { scope: 'app', name: 'Error', message: 'x', stack: null, fatal: false, app_version: '3.1.4', runtime_version: null, update_id: null, build: null, platform: 'ios', os_version: null, route: null, occurred_at: '2026-10-24T10:00:00Z' },
      { token: null, timeoutMs: 1_000 },
    ).catch(() => undefined);
    expect(useServerStatusStore.getState()).toEqual(before);
    fetchSpy.mockRestore();
  });
});
