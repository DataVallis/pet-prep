/**
 * `gameLayerAvailable()` — the capability flag of the native game layer (GAMES_SPEC §7.4).
 *
 * The game layer needs four native libraries that older store binaries don't contain:
 * `react-native-gesture-handler`, `@shopify/react-native-skia`, `expo-haptics`, `expo-audio`
 * (plus `react-native-reanimated` / `react-native-worklets`, already in every binary since
 * 2026-10). An OTA update brings the new JS to an old binary, so:
 *
 * - this file imports NONE of those libraries — it only asks the native registries whether
 *   the modules exist (`TurboModuleRegistry.get`, `requireOptionalNativeModule`), which never
 *   throws for a missing module;
 * - every file that does import them (`modules/gameLayer/*.tsx`, `useGameGestures`, `gameFx`,
 *   `modules/play/ballV2/BallGameV2`) is reached ONLY through a lazy `require()` behind this
 *   flag (`modules/play/PlayOverlay.tsx#loadBallGameV2`) — an old binary keeps the v1 games and
 *   never evaluates them;
 * - the answer is decided once per JS runtime (a binary can't gain a module while running).
 *
 * Jest: off by default (`jest.afterEnv.ts` → `setGameLayerAvailableForTests(false)`); a test
 * opts in with `setGameLayerAvailableForTests(true)`. The real probe is tested with injected
 * probes (`probeGameLayer`).
 */

import { TurboModuleRegistry } from 'react-native';
import { requireOptionalNativeModule } from 'expo';

/** One probe per native module of the layer; each answers "present?" and may throw. */
export interface GameLayerProbes {
  gestureHandler: () => boolean;
  skia: () => boolean;
  reanimated: () => boolean;
  worklets: () => boolean;
  haptics: () => boolean;
  audio: () => boolean;
}

export const GAME_LAYER_MODULES: readonly (keyof GameLayerProbes)[] = ['gestureHandler', 'skia', 'reanimated', 'worklets', 'haptics', 'audio'];

const turbo = (name: string) => () => TurboModuleRegistry.get(name) != null;
const expoModule = (name: string) => () => requireOptionalNativeModule(name) != null;

/** The native names the libraries register (checked against their sources, 2026-10-30). */
export const NATIVE_GAME_LAYER_PROBES: GameLayerProbes = {
  // react-native-gesture-handler 2.32: src/specs/NativeRNGestureHandlerModule.ts
  gestureHandler: turbo('RNGestureHandlerModule'),
  // @shopify/react-native-skia 2.6: src/specs/NativeSkiaModule.ts
  skia: turbo('RNSkiaModule'),
  // react-native-reanimated 4 / react-native-worklets: their TurboModules
  reanimated: turbo('ReanimatedModule'),
  worklets: turbo('WorkletsModule'),
  // expo-haptics: requireOptionalNativeModule('ExpoHaptics'); expo-audio: requireNativeModule('ExpoAudio')
  haptics: expoModule('ExpoHaptics'),
  audio: expoModule('ExpoAudio'),
};

/** Which modules are missing (empty = the layer can run). A probe that throws counts as missing. */
export function missingGameLayerModules(probes: GameLayerProbes): (keyof GameLayerProbes)[] {
  return GAME_LAYER_MODULES.filter((name) => {
    try {
      return probes[name]() !== true;
    } catch {
      return true;
    }
  });
}

/** Pure: the layer runs only when every native module is present. */
export function probeGameLayer(probes: GameLayerProbes): boolean {
  return missingGameLayerModules(probes).length === 0;
}

let cached: boolean | null = null;
let override: boolean | null = null;

/** True when this binary carries every native module of the game layer (decided once). */
export function gameLayerAvailable(): boolean {
  if (override !== null) return override;
  if (cached === null) cached = probeGameLayer(NATIVE_GAME_LAYER_PROBES);
  return cached;
}

/** Tests: force the answer (`null` = the real, cached probe). */
export function setGameLayerAvailableForTests(value: boolean | null): void {
  override = value;
  cached = null;
}
