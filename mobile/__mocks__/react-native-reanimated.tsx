/**
 * Jest mock of react-native-reanimated 4 (its own `mock` needs native worklets under jest-expo).
 * Only what the game layer uses: shared values are plain objects, derived values recompute on
 * every read, animations jump to their target (the game logic runs on JS timers, not on these).
 */
import { useRef } from 'react';
import { Image, ScrollView, Text, View } from 'react-native';

type Fn = (...args: never[]) => unknown;

export interface SharedValue<T> {
  value: T;
  get: () => T;
  set: (v: T) => void;
}

function makeShared<T>(initial: T): SharedValue<T> {
  const box = {
    value: initial,
    get: () => box.value,
    set: (v: T) => {
      box.value = v;
    },
  };
  return box;
}

export function useSharedValue<T>(initial: T): SharedValue<T> {
  const ref = useRef<SharedValue<T> | null>(null);
  if (ref.current === null) ref.current = makeShared(initial);
  return ref.current;
}

/**
 * UI-thread guard (cat wand crash, David 2026-11-02): on a device `useDerivedValue` /
 * `useAnimatedStyle` / `useAnimatedProps` run their updater on the UI runtime, where a captured
 * function that is NOT a worklet is a »remote function« — calling it throws, and react-native-
 * worklets reports that as a FATAL error (release build = the whole app closes; no error
 * boundary can catch it). Jest runs the updater on the JS thread, where the call just works, so
 * the mock checks what the worklets Babel plugin recorded (`__closure`): every function the
 * updater (or a worklet it calls) captures must itself be a worklet. Plain functions passed by
 * tests (no `__closure`) and this mock's own animation helpers are allowed.
 */
interface WorkletMeta {
  __closure?: Record<string, unknown>;
  __workletHash?: number;
}

const MOCK_SAFE = new WeakSet<object>();

export function assertUiWorklet(fn: unknown, hook: string, seen: Set<unknown> = new Set()): void {
  if (typeof fn !== 'function' || seen.has(fn)) return;
  seen.add(fn);
  const closure = (fn as WorkletMeta).__closure;
  if (closure === undefined) return;
  for (const [name, value] of Object.entries(closure)) {
    if (typeof value !== 'function') continue;
    if ((value as WorkletMeta).__workletHash !== undefined) {
      assertUiWorklet(value, hook, seen);
      continue;
    }
    if (MOCK_SAFE.has(value)) continue;
    throw new Error(
      `[Worklets] ${hook} captures the non-worklet function "${name}" — on a device that is a remote function and calling it on the UI runtime is a FATAL crash. Mark it 'worklet' or compute the value outside the worklet.`,
    );
  }
}

export function useDerivedValue<T>(fn: () => T): SharedValue<T> {
  assertUiWorklet(fn, 'useDerivedValue');
  const fnRef = useRef(fn);
  fnRef.current = fn;
  const ref = useRef<SharedValue<T> | null>(null);
  if (ref.current === null) {
    const derived = {
      get value() {
        return fnRef.current();
      },
      set value(_v: T) {
        // read-only
      },
      get: () => fnRef.current(),
      set: () => undefined,
    };
    ref.current = derived as SharedValue<T>;
  }
  return ref.current;
}

export const useAnimatedStyle = <T,>(fn: () => T): T => {
  assertUiWorklet(fn, 'useAnimatedStyle');
  return fn();
};
export const useAnimatedProps = <T,>(fn: () => T): T => {
  assertUiWorklet(fn, 'useAnimatedProps');
  return fn();
};
export const withTiming = <T,>(to: T): T => to;
export const withSpring = <T,>(to: T): T => to;
export const withDelay = <T,>(_ms: number, v: T): T => v;
export const withRepeat = <T,>(v: T): T => v;
export const withSequence = <T,>(...values: T[]): T => values[values.length - 1];
export const cancelAnimation = (): void => undefined;
export const runOnJS = <F extends Fn>(fn: F): F => fn;
export const runOnUI = <F extends Fn>(fn: F): F => fn;
export const useReducedMotion = (): boolean => false;

const identity = (t: number) => t;
const curve = () => identity;
export const Easing = {
  linear: identity,
  quad: identity,
  cubic: identity,
  ease: identity,
  in: curve,
  out: curve,
  inOut: curve,
  back: curve,
  bezier: curve,
};

// The real versions of these are worklets.
for (const f of [withTiming, withSpring, withDelay, withRepeat, withSequence, cancelAnimation, identity, curve]) MOCK_SAFE.add(f);

const Animated = { View, Text, Image, ScrollView, createAnimatedComponent: <C,>(c: C): C => c };
export default Animated;
