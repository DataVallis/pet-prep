import { logRenderError } from '@/utils/logRenderError';

describe('logRenderError', () => {
  it('logs scope, error name/message and the component stack only', () => {
    const spy = jest.spyOn(console, 'error').mockImplementation(() => undefined);
    logRenderError('child-hud', new TypeError('x is undefined'), { componentStack: '\n    in Hud' });
    expect(spy).toHaveBeenCalledWith('[render-error:child-hud] TypeError: x is undefined', '\n    in Hud');
    spy.mockRestore();
  });

  it('never throws, even when the console does', () => {
    const spy = jest.spyOn(console, 'error').mockImplementation(() => {
      throw new Error('console gone');
    });
    expect(() => logRenderError('app', new Error('boom'))).not.toThrow();
    spy.mockRestore();
  });
});
