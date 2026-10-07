/**
 * `strings()` — a live, typed view of one section of a namespace (M1-18).
 *
 * The app grew with one `*_STRINGS` object per screen/module (`S.title`, `S.retry(time)`).
 * `strings('auth', 'pin', { …functions })` keeps that shape but every read goes through
 * i18next at access time, so a value is always in the current language:
 *
 *   export const CHILD_PIN_STRINGS = strings('auth', 'pin', {
 *     rateLimited: (time: string) => t('auth:pin.rateLimited', { time }),
 *   });
 *   S.title            // → t('auth:pin.title')
 *   S.errors.offline   // → t('auth:pin.errors.offline') (nested sections work too)
 *   S.rateLimited('2 min')
 *
 * Plain string leaves come from the English JSON (the type is derived from it, so a
 * missing key is a compile error); interpolated or plural texts are the explicit
 * functions in `extras`. Never copy a value into a module-level constant — read it when
 * rendering. `AppNavigator` subscribes to language changes, so the whole tree re-renders
 * and re-reads; `React.memo` components and `useMemo` values that hold text must
 * subscribe themselves (`useTranslation()`) or depend on `i18n.language`.
 */

import { i18n } from './index';
import { resources, type Namespace, type Resources } from './resources';

type Tree = { readonly [key: string]: string | Tree };

/** JSON shape → read-only view with string leaves. */
export type Leaves<T> = { readonly [K in keyof T]: T[K] extends string ? string : Leaves<T[K]> };

type SectionOf<NS extends Namespace> = keyof Resources[NS] & string;

/** Leaves of `T`, with entries of `E` replacing (or, for nested objects, extending) them. */
export type View<T, E> = {
  readonly [K in keyof T | keyof E]: K extends keyof E
    ? K extends keyof T
      ? E[K] extends (...args: never[]) => unknown
        ? E[K]
        : E[K] extends object
          ? View<T[K], E[K]>
          : E[K]
      : E[K]
    : K extends keyof T
      ? T[K] extends string
        ? string
        : Leaves<T[K]>
      : never;
};

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && Object.getPrototypeOf(value) === Object.prototype;
}

/** Untyped lookup (keys are checked by the `View` type, not here). */
const translate = (key: string): string => (i18n.t as unknown as (k: string) => string)(key);

function makeView(ns: string, path: string, node: Tree, extras: Record<string, unknown>): object {
  const keys = (): string[] => [...new Set([...Object.keys(node), ...Object.keys(extras)])];
  const read = (prop: string): unknown => {
    const child = node[prop];
    const key = path ? `${path}.${prop}` : prop;
    const extra = Object.prototype.hasOwnProperty.call(extras, prop) ? extras[prop] : undefined;
    // A nested section with its own functions: `{ errors: { tooMany: (n) => … } }`.
    if (isPlainObject(extra) && child && typeof child === 'object') return makeView(ns, key, child, extra);
    if (extra !== undefined) return extra;
    if (typeof child === 'string') return translate(`${ns}:${key}`);
    if (child && typeof child === 'object') return makeView(ns, key, child, {});
    return undefined;
  };
  return new Proxy<Record<string, unknown>>(extras, {
    get: (target, prop) => (typeof prop === 'string' ? read(prop) : Reflect.get(target, prop)),
    has: (_target, prop) => typeof prop === 'string' && keys().includes(prop),
    ownKeys: () => keys(),
    // Fresh descriptor (not the target's): the value is resolved per read.
    getOwnPropertyDescriptor: (_target, prop) =>
      typeof prop === 'string' && keys().includes(prop)
        ? { configurable: true, enumerable: true, writable: false, value: read(prop) }
        : undefined,
    set: () => false,
    defineProperty: () => false,
    deleteProperty: () => false,
  });
}

/** Whole namespace or one top-level section of it, plus explicit function entries. */
export function strings<NS extends Namespace, S extends SectionOf<NS>, E extends Record<string, unknown> = Record<never, never>>(
  ns: NS,
  section: S,
  extras?: E,
): View<Resources[NS][S], E> {
  const node = (resources.en[ns] as unknown as Record<string, Tree>)[section];
  if (!node || typeof node !== 'object') throw new Error(`i18n: unknown section ${ns}:${section}`);
  return makeView(ns, section, node, (extras ?? {}) as Record<string, unknown>) as View<Resources[NS][S], E>;
}
