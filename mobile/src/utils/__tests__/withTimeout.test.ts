import { TimeoutError, isTimeoutError, withTimeout, withTimeoutOr } from '@/utils/withTimeout';

describe('withTimeout', () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it('resolves with the value and leaves no timer behind', async () => {
    await expect(withTimeout(Promise.resolve(7), 1_000)).resolves.toBe(7);
    expect(jest.getTimerCount()).toBe(0);
  });

  it('passes a rejection through', async () => {
    await expect(withTimeout(Promise.reject(new Error('x')), 1_000)).rejects.toThrow('x');
    expect(jest.getTimerCount()).toBe(0);
  });

  it('rejects with a TimeoutError and calls onTimeout when the promise never settles', async () => {
    const onTimeout = jest.fn();
    const result = withTimeout(new Promise<number>(() => undefined), 1_000, onTimeout);
    const caught = result.catch((error: unknown) => error);
    await jest.advanceTimersByTimeAsync(999);
    expect(onTimeout).not.toHaveBeenCalled();
    await jest.advanceTimersByTimeAsync(1);
    const error = await caught;
    expect(error).toBeInstanceOf(TimeoutError);
    expect(isTimeoutError(error)).toBe(true);
    expect(onTimeout).toHaveBeenCalledTimes(1);
  });

  it('withTimeoutOr falls back on timeout and on rejection', async () => {
    const hung = withTimeoutOr(new Promise<string>(() => undefined), 500, 'fallback');
    await jest.advanceTimersByTimeAsync(500);
    await expect(hung).resolves.toBe('fallback');
    await expect(withTimeoutOr(Promise.reject(new Error('x')), 500, 'fb')).resolves.toBe('fb');
  });
});
