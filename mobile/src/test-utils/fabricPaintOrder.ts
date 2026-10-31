/**
 * `fabricPaintOrder` — the order in which React Native's New Architecture (Fabric) PAINTS the
 * host views under a node (later = on top), computed from an RNTL render tree.
 *
 * Why (ball v2 device bug, 2026-10-31): Jest renders the tree in React order, but Fabric mounts
 * it differently. A `View` that does not form a *stacking context* has its children hoisted into
 * the nearest ancestor that does, and every stacking context sorts its (hoisted) children by
 * `zIndex` (stable). So a `zIndex: 2` deep inside the video stage (`ClipDeck`'s front player)
 * ended up above the top bar, the ball, the buttons and the feedback on both platforms — while
 * every Jest test still found them. This helper mirrors the native rules so a test can assert
 * what is really on top.
 *
 * Mirrors (RN 0.86):
 * - `ReactCommon/react/renderer/components/view/ViewShadowNode.cpp` — `formsStackingContext` /
 *   `formsView` traits of a `View`;
 * - `ConcreteViewShadowNode.h` — `orderIndex = zIndex` unless `position: 'static'`;
 * - `mounting/internal/sliceChildShadowNodeViewPairs.cpp` — children of a view that does not
 *   form a stacking context are flattened into the parent list, which is then stable-sorted by
 *   `orderIndex`.
 * Any non-`View` host component (Text, Image, a native component) is treated as forming its own
 * stacking context, like the native shadow nodes do. The expo-video / gesture-handler mocks
 * render a `View`; they are recognised by their props (`player`, the root's own testID).
 */
import { StyleSheet, type ViewStyle } from 'react-native';
import type { ReactTestInstance } from 'react-test-renderer';

type Props = Record<string, unknown>;

const EVENT_PROPS = /^on(StartShouldSetResponder|MoveShouldSetResponder|Responder|Touch|Pointer|Click)/;

function styleOf(node: ReactTestInstance): ViewStyle {
  return (StyleSheet.flatten(node.props.style as ViewStyle) ?? {}) as ViewStyle;
}

/** An `Animated.Value` (mocked or real) or a plain number. */
function numeric(value: unknown): number | undefined {
  if (typeof value === 'number') return value;
  if (value !== null && typeof value === 'object' && '__getValue' in value && typeof (value as { __getValue: unknown }).__getValue === 'function') {
    const v = (value as { __getValue: () => unknown }).__getValue();
    return typeof v === 'number' ? v : undefined;
  }
  return undefined;
}

function isHostView(node: ReactTestInstance): boolean {
  return (node.type as unknown) === 'View' && (node.props as Props).player === undefined;
}

function formsStackingContext(node: ReactTestInstance): boolean {
  if (!isHostView(node)) return true;
  const p = node.props as Props;
  const s = styleOf(node);
  const pointerEvents = p.pointerEvents ?? s.pointerEvents;
  const opacity = numeric(s.opacity);
  return (
    p.collapsable === false ||
    pointerEvents === 'none' ||
    pointerEvents === 'box-only' ||
    (typeof p.nativeID === 'string' && p.nativeID !== '') ||
    p.accessible === true ||
    (opacity !== undefined && opacity !== 1) ||
    (Array.isArray(s.transform) && s.transform.length > 0) ||
    (s.zIndex !== undefined && s.position !== 'static') ||
    s.display === 'none' ||
    s.overflow === 'hidden' ||
    Object.keys(p).some((k) => EVENT_PROPS.test(k) && p[k] !== undefined) ||
    p.accessibilityElementsHidden === true ||
    p.accessibilityViewIsModal === true ||
    (p.importantForAccessibility !== undefined && p.importantForAccessibility !== 'auto') ||
    p.removeClippedSubviews === true ||
    (s as { isolation?: string }).isolation === 'isolate'
  );
}

function orderIndex(node: ReactTestInstance): number {
  const s = styleOf(node);
  if (s.position === 'static') return 0;
  return typeof s.zIndex === 'number' ? s.zIndex : 0;
}

/** Host children of `node` (skipping composite components and plain strings). */
function hostChildren(node: ReactTestInstance): ReactTestInstance[] {
  const out: ReactTestInstance[] = [];
  for (const child of node.children) {
    if (typeof child === 'string') continue;
    if (typeof (child.type as unknown) === 'string') out.push(child);
    else out.push(...hostChildren(child));
  }
  return out;
}

/** The slice of one stacking context: its (hoisted) children, sorted by zIndex. */
function slice(context: ReactTestInstance): ReactTestInstance[] {
  const list: ReactTestInstance[] = [];
  const walk = (parent: ReactTestInstance) => {
    for (const child of hostChildren(parent)) {
      if (styleOf(child).display === 'none') continue;
      list.push(child);
      if (!formsStackingContext(child)) walk(child);
    }
  };
  walk(context);
  // Array.prototype.sort is stable (ES2019), like std::stable_sort.
  return list.sort((a, b) => orderIndex(a) - orderIndex(b));
}

/** Every host node under `root`, in native paint order (index 0 = bottom). */
export function fabricPaintOrder(root: ReactTestInstance): ReactTestInstance[] {
  const out: ReactTestInstance[] = [];
  const paint = (context: ReactTestInstance) => {
    for (const node of slice(context)) {
      out.push(node);
      if (formsStackingContext(node)) paint(node);
    }
  };
  paint(root);
  return out;
}

/** The testIDs under `root` in native paint order. */
export function fabricPaintedTestIDs(root: ReactTestInstance): string[] {
  return fabricPaintOrder(root)
    .map((n) => (n.props as Props).testID)
    .filter((id): id is string => typeof id === 'string');
}

/**
 * Asserts that everything inside `stage` (the full-screen video) is painted BELOW every node in
 * `above` — the bug class of 2026-10-31. Returns the painted order for extra assertions.
 */
export function expectPaintedAbove(root: ReactTestInstance, stage: ReactTestInstance, above: readonly ReactTestInstance[]): void {
  const order = fabricPaintOrder(root);
  const stageNodes = new Set<ReactTestInstance>();
  const collect = (n: ReactTestInstance) => {
    for (const c of hostChildren(n)) {
      stageNodes.add(c);
      collect(c);
    }
  };
  stageNodes.add(stage);
  collect(stage);
  const lastStage = Math.max(...order.map((n, i) => (stageNodes.has(n) ? i : -1)));
  for (const node of above) {
    const at = order.indexOf(node);
    if (at === -1) throw new Error(`${String((node.props as Props).testID)} is not painted under the root`);
    if (at < lastStage) {
      const covering = order[lastStage];
      throw new Error(
        `${String((node.props as Props).testID)} is painted under the stage (${String((covering.props as Props).testID ?? covering.type)} is on top of it)`,
      );
    }
  }
}
