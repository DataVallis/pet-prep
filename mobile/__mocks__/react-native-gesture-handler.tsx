/**
 * Jest mock of react-native-gesture-handler 2: the builders record their worklet callbacks and
 * `GestureDetector` registers the gesture under its child's `testID`, so a test can fire them:
 * `fireGameGesture('play-field', 'pan', { translationY: -120, velocityY: -1500 })`.
 */
import { type ReactElement, type ReactNode } from 'react';
import { View, type ViewProps } from 'react-native';

type Handler = (e: Record<string, number>) => void;

export class MockGesture {
  readonly handlers: Partial<Record<'begin' | 'update' | 'end', Handler>> = {};
  constructor(readonly kind: 'pan' | 'tap' | 'longPress') {}
  onBegin(fn: Handler) {
    this.handlers.begin = fn;
    return this;
  }
  onStart(fn: Handler) {
    this.handlers.begin = fn;
    return this;
  }
  onUpdate(fn: Handler) {
    this.handlers.update = fn;
    return this;
  }
  onEnd(fn: Handler) {
    this.handlers.end = fn;
    return this;
  }
  onFinalize() {
    return this;
  }
  minDistance() {
    return this;
  }
  maxDuration() {
    return this;
  }
  minDuration() {
    return this;
  }
  enabled() {
    return this;
  }
  runOnJS() {
    return this;
  }
}

export class MockComposed {
  constructor(readonly gestures: MockGesture[]) {}
}

export const Gesture = {
  Pan: () => new MockGesture('pan'),
  Tap: () => new MockGesture('tap'),
  LongPress: () => new MockGesture('longPress'),
  Exclusive: (...g: MockGesture[]) => new MockComposed(g),
  Simultaneous: (...g: MockGesture[]) => new MockComposed(g),
  Race: (...g: MockGesture[]) => new MockComposed(g),
};

export type GestureType = MockGesture;
export type ComposedGesture = MockComposed;
export type GestureStateChangeEvent<T> = T;
export type GestureUpdateEvent<T> = T;
export type PanGestureHandlerEventPayload = { x: number; y: number; translationX: number; translationY: number; velocityX: number; velocityY: number };
export type TapGestureHandlerEventPayload = { x: number; y: number };

const registry = new Map<string, MockGesture[]>();

export function GestureDetector({ gesture, children }: { gesture: MockGesture | MockComposed; children: ReactElement<{ testID?: string }> }) {
  const id = children.props.testID ?? 'gesture';
  registry.set(id, gesture instanceof MockComposed ? gesture.gestures : [gesture]);
  return children;
}

/** Fire a recorded gesture of the detector whose child has `testID` (begin → update → end). */
export function fireGameGesture(testID: string, kind: 'pan' | 'tap', event: Record<string, number>): void {
  const gesture = registry.get(testID)?.find((g) => g.kind === kind);
  if (gesture === undefined) throw new Error(`no ${kind} gesture on ${testID}`);
  const e = { x: 0, y: 0, translationX: 0, translationY: 0, velocityX: 0, velocityY: 0, ...event };
  gesture.handlers.begin?.(e);
  gesture.handlers.update?.(e);
  gesture.handlers.end?.(e);
}

/** Fire a recorded pan as a real drag: begin at the first sample, update through every one, end at the last. */
export function fireGamePan(testID: string, samples: Record<string, number>[]): void {
  const gesture = registry.get(testID)?.find((g) => g.kind === 'pan');
  if (gesture === undefined) throw new Error(`no pan gesture on ${testID}`);
  const events = samples.map((e) => ({ x: 0, y: 0, translationX: 0, translationY: 0, velocityX: 0, velocityY: 0, ...e }));
  gesture.handlers.begin?.(events[0]);
  for (const e of events.slice(1)) gesture.handlers.update?.(e);
  gesture.handlers.end?.(events[events.length - 1]);
}

export function GestureHandlerRootView({ children, ...props }: ViewProps & { children?: ReactNode }) {
  return <View {...props}>{children}</View>;
}
