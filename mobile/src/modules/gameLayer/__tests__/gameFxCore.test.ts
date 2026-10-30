import { createGameFx, type HapticAdapter, type SoundAdapter } from '@/modules/gameLayer/gameFxCore';

function adapters() {
  const sounds: SoundAdapter & { play: jest.Mock; release: jest.Mock } = { play: jest.fn(), release: jest.fn() };
  const haptics: { [K in keyof HapticAdapter]: jest.Mock } = { impactLight: jest.fn(), success: jest.fn(), selection: jest.fn() };
  return { sounds, haptics };
}

describe('GameFx rules (GAMES_SPEC §7.1, §7.6)', () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it('plays sounds (now or delayed) and positive haptics only', () => {
    const { sounds, haptics } = adapters();
    const fx = createGameFx({ enabled: true, sounds, haptics });
    fx.sound('whoosh');
    fx.sound('bounce', 550);
    expect(sounds.play.mock.calls).toEqual([['whoosh']]);
    jest.advanceTimersByTime(550);
    expect(sounds.play.mock.calls).toEqual([['whoosh'], ['bounce']]);
    fx.haptic('tap');
    fx.haptic('success');
    fx.haptic('cue');
    expect(haptics.impactLight).toHaveBeenCalledTimes(1);
    expect(haptics.success).toHaveBeenCalledTimes(1);
    expect(haptics.selection).toHaveBeenCalledTimes(1);
  });

  it('the parent switch off = silence and no vibration', () => {
    const { sounds, haptics } = adapters();
    const fx = createGameFx({ enabled: false, sounds, haptics });
    fx.sound('chime');
    fx.haptic('success');
    jest.runAllTimers();
    expect(sounds.play).not.toHaveBeenCalled();
    expect(haptics.success).not.toHaveBeenCalled();
  });

  it('release cancels delayed sounds; a failing native call never throws into the game', async () => {
    const { sounds, haptics } = adapters();
    sounds.play.mockImplementation(() => {
      throw new Error('audio session');
    });
    haptics.success.mockReturnValue(Promise.reject(new Error('no engine')));
    const fx = createGameFx({ enabled: true, sounds, haptics });
    expect(() => fx.sound('pop')).not.toThrow();
    expect(() => fx.haptic('success')).not.toThrow();
    fx.sound('chime', 300);
    fx.release();
    jest.advanceTimersByTime(300);
    expect(sounds.play).toHaveBeenCalledTimes(1);
    expect(sounds.release).toHaveBeenCalledTimes(1);
    await Promise.resolve();
  });
});
