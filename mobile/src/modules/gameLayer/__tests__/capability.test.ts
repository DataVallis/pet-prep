/**
 * The capability flag must keep an OTA'd older binary on the v1 games (GAMES_SPEC §7.4).
 * `jest.afterEnv.ts` already loaded the module, so every test loads a fresh copy with its own
 * native registries (`load`).
 */
import type * as Capability from '@/modules/gameLayer/capability';
import type { GameLayerProbes } from '@/modules/gameLayer/capability';

interface Natives {
  turbo: string[];
  expo: string[];
}

function load(natives: Natives): { cap: typeof Capability; turboGet: jest.Mock } {
  jest.resetModules();
  jest.doMock('expo', () => ({ requireOptionalNativeModule: (name: string) => (natives.expo.includes(name) ? {} : null) }));
  // The fresh registry's react-native is the one the fresh capability module will import.
  const rn = jest.requireActual<typeof import('react-native')>('react-native');
  const turboGet = jest
    .spyOn(rn.TurboModuleRegistry, 'get')
    .mockImplementation(((name: string) => (natives.turbo.includes(name) ? {} : null)) as typeof rn.TurboModuleRegistry.get) as unknown as jest.Mock;
  const cap = jest.requireActual<typeof Capability>('@/modules/gameLayer/capability');
  cap.setGameLayerAvailableForTests(null);
  return { cap, turboGet };
}

const ALL_TURBO = ['ReanimatedModule', 'WorkletsModule', 'RNGestureHandlerModule', 'RNSkiaModule'];
const ALL_EXPO = ['ExpoHaptics', 'ExpoAudio'];

afterEach(() => {
  jest.dontMock('expo');
  jest.restoreAllMocks();
  jest.resetModules();
});

describe('gameLayerAvailable (GAMES_SPEC §7.4)', () => {
  it('runs only when every native module of the layer is present', () => {
    const { cap } = load({ turbo: [], expo: [] });
    const probes = (over: Partial<GameLayerProbes> = {}): GameLayerProbes => {
      const all = Object.fromEntries(cap.GAME_LAYER_MODULES.map((m) => [m, () => true])) as unknown as GameLayerProbes;
      return { ...all, ...over };
    };
    expect(cap.probeGameLayer(probes())).toBe(true);
    for (const name of cap.GAME_LAYER_MODULES) {
      expect(cap.probeGameLayer(probes({ [name]: () => false }))).toBe(false);
      expect(cap.missingGameLayerModules(probes({ [name]: () => false }))).toEqual([name]);
    }
    // A probe that throws counts as missing — never a crash.
    const throwing = probes({
      skia: () => {
        throw new Error('TurboModuleRegistry.getEnforcing(...): RNSkiaModule could not be found');
      },
    });
    expect(() => cap.probeGameLayer(throwing)).not.toThrow();
    expect(cap.probeGameLayer(throwing)).toBe(false);
  });

  it('an old binary (OTA JS without the new native modules) keeps the v1 games', () => {
    // Reanimated / worklets are in every binary since 2026-10; the four new ones are not.
    const { cap } = load({ turbo: ['ReanimatedModule', 'WorkletsModule'], expo: [] });
    expect(cap.gameLayerAvailable()).toBe(false);
    expect(cap.missingGameLayerModules(cap.NATIVE_GAME_LAYER_PROBES)).toEqual(['gestureHandler', 'skia', 'haptics', 'audio']);
  });

  it('one missing module (e.g. expo-audio) is enough to stay on v1', () => {
    expect(load({ turbo: ALL_TURBO, expo: ['ExpoHaptics'] }).cap.gameLayerAvailable()).toBe(false);
    expect(load({ turbo: ALL_TURBO.filter((n) => n !== 'RNSkiaModule'), expo: ALL_EXPO }).cap.gameLayerAvailable()).toBe(false);
  });

  it('a new store binary with every module runs the layer — decided once per runtime', () => {
    const { cap, turboGet } = load({ turbo: ALL_TURBO, expo: ALL_EXPO });
    expect(cap.gameLayerAvailable()).toBe(true);
    const calls = turboGet.mock.calls.length;
    expect(cap.gameLayerAvailable()).toBe(true);
    expect(turboGet.mock.calls.length).toBe(calls);
  });

  it('tests can force the answer (Jest default: off, like an old binary)', () => {
    const { cap } = load({ turbo: ALL_TURBO, expo: ALL_EXPO });
    cap.setGameLayerAvailableForTests(false);
    expect(cap.gameLayerAvailable()).toBe(false);
    cap.setGameLayerAvailableForTests(true);
    expect(cap.gameLayerAvailable()).toBe(true);
  });
});
