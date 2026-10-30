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

export function useDerivedValue<T>(fn: () => T): SharedValue<T> {
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

export const useAnimatedStyle = <T,>(fn: () => T): T => fn();
export const useAnimatedProps = <T,>(fn: () => T): T => fn();
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

const Animated = { View, Text, Image, ScrollView, createAnimatedComponent: <C,>(c: C): C => c };
export default Animated;
