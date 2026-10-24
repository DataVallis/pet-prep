/**
 * M5-05 app error reports — pure part: what one report contains (David 2026-10-19: our own
 * System health instead of Sentry; no third-party SDK).
 *
 * A report carries ONLY technical facts: scope, error name, message (≤ 500), the top of the
 * JS stack (≤ ~2 KB, device file paths reduced to file names), app / build / OTA identity,
 * platform + OS version, the screen name and the time. Never props, state, the store, a
 * user, a child, a pet name or an e-mail — the child is a minor (minimum data).
 *
 * QA #375 M2: the text is scrubbed HERE, before it is written to disk, with the same rules
 * the server applies again (`AppErrorText`): URL query strings / fragments dropped,
 * percent-encoding decoded, e-mails / bearer tokens / credentials redacted, phone numbers
 * and digit runs of 4+ replaced (never a stack `:line:col`), quoted values → `{s}` (only a
 * dotted code path in `evaluating 'a.b'` stays). Free-text words cannot be recognised, so
 * app code must never put a name into an error message (DECISIONS 2026-10-24; guarded by
 * `__tests__/noNamesInErrors.test.ts`).
 */

export type AppErrorScope = 'app' | 'child-hud' | 'locked-overlay' | 'global' | 'unhandled-rejection';

export type AppErrorPlatform = 'ios' | 'android';

/** Mirrors the backend `ReportAppErrorRequest`. */
export interface AppErrorReport {
  scope: AppErrorScope;
  name: string;
  message: string;
  stack: string | null;
  fatal: boolean;
  app_version: string;
  runtime_version: string | null;
  update_id: string | null;
  build: string | null;
  platform: AppErrorPlatform;
  os_version: string | null;
  route: string | null;
  occurred_at: string;
}

/** Identity of the running app (build + OTA), read once per report. */
export interface AppIdentity {
  appVersion: string;
  build: string | null;
  runtimeVersion: string | null;
  updateId: string | null;
  platform: AppErrorPlatform;
  osVersion: string | null;
}

export const NAME_MAX = 100;
export const MESSAGE_MAX = 500;
export const STACK_MAX = 2048;
const COMPONENT_STACK_LINES = 6;

/** Error names are identifiers (`TypeError`); anything else is sent as `Error`. */
const NAME_PATTERN = /^[A-Za-z0-9_$.\- ]{1,100}$/;

/** Same character rules as the server (else the report is refused with 422). */
const VERSION = /^[0-9A-Za-z.\-+() ]+$/;
const TOKEN = /^[0-9A-Za-z._-]+$/;

function clip(text: string, max: number): string {
  return text.length > max ? `${text.slice(0, max - 1)}…` : text;
}

function safeValue(value: string | null | undefined, pattern: RegExp, max: number): string | null {
  if (typeof value !== 'string') return null;
  const trimmed = value.trim();
  return trimmed.length > 0 && trimmed.length <= max && pattern.test(trimmed) ? trimmed : null;
}

/**
 * `file:///var/mobile/Containers/Data/…/main.jsbundle:1:2` / `/data/user/0/…/x.js` → the
 * file name only: an app container path may carry a device or user id.
 */
export function stripPaths(text: string): string {
  return text.replace(/(?:[a-z][a-z0-9+.-]*:\/\/)?(?:\/[^\s/():]+)+\/([^\s/():]+)/gi, '$1');
}

function safeDecode(text: string): string {
  try {
    return decodeURIComponent(text);
  } catch {
    return text.replace(/%40/gi, '@');
  }
}

/** The scrub rules shared with the server (see the header). Never throws. */
export function scrubText(input: string): string {
  let text = input.replace(/((?:[a-z][a-z0-9+.-]*:\/\/|\/)[^\s?#()'"]*)[?#][^\s()'"]*/gi, '$1?[query]');
  text = text.replace(/\?[A-Za-z0-9_\-[\]]+=[^\s()'"]*/g, '?[query]');
  text = safeDecode(text);
  text = text.replace(
    /((?:password|passwd|secret|token|api[_-]?key|authorization|signature|code|pin|nonce|cookie|session)["']?\s*[:=]\s*["']?)[^\s"'&,;)}\]]+/gi,
    '$1[redacted]',
  );
  text = text.replace(/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+/]+=*/gi, '$1 [redacted]');
  text = text.replace(/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/g, '[jwt]');
  text = text.replace(/\b\d+\|[A-Za-z0-9]{20,}\b/g, '[token]');
  text = text.replace(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g, '[email]');
  text = text.replace(/\b(?:\d{1,3}\.){3}\d{1,3}\b/g, '[ip]');
  // Dotted code paths in `evaluating 'a.b'` are source code, not data.
  text = text.replace(/(evaluating )'([A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*)+)'/g, '$1`$2`');
  text = text.replace(/'[^']*'|"[^"]*"/g, '{s}');
  text = text.replace(/(?<![:\d])\+?\d[\d \-/]{6,}\d(?![:\d])/g, '{phone}');
  text = text.replace(/(?<![:\d])\d{4,}/g, '{n}');
  return text;
}

/** An error name the server accepts, scrubbed; else `Error`. */
export function safeName(name: string): string {
  const trimmed = name.trim();
  if (!NAME_PATTERN.test(trimmed)) return 'Error';
  const scrubbed = scrubText(trimmed);
  return NAME_PATTERN.test(scrubbed) ? scrubbed : 'Error';
}

/** The top of a stack (+ the first component-stack lines), cut at a line boundary. */
export function topOfStack(stack: string | null | undefined, componentStack?: string | null): string | null {
  const lines: string[] = [];
  let length = 0;
  const push = (line: string): boolean => {
    const clean = clip(scrubText(stripPaths(line.trim())), 300);
    if (clean.length === 0) return true;
    if (length + clean.length + 1 > STACK_MAX) return false;
    lines.push(clean);
    length += clean.length + 1;
    return true;
  };
  for (const line of (stack ?? '').split('\n')) {
    if (!push(line)) break;
  }
  const component = (componentStack ?? '').split('\n').filter((line) => line.trim().length > 0).slice(0, COMPONENT_STACK_LINES);
  if (component.length > 0 && push('Component stack:')) {
    for (const line of component) {
      if (!push(line)) break;
    }
  }
  return lines.length > 0 ? lines.join('\n') : null;
}

/** Any thrown value → name + message (a rejected promise may carry a string or nothing). */
export function describeThrown(value: unknown): { name: string; message: string; stack: string | null } {
  if (value instanceof Error) {
    return { name: value.name || 'Error', message: value.message ?? '', stack: typeof value.stack === 'string' ? value.stack : null };
  }
  if (typeof value === 'string') return { name: 'Error', message: value, stack: null };
  if (value === undefined || value === null) return { name: 'Error', message: String(value), stack: null };
  // Never serialise an object: it may hold state / user data. Only its type.
  return { name: 'NonError', message: `non-Error value thrown (${typeof value})`, stack: null };
}

export interface BuildReportInput {
  scope: AppErrorScope;
  error: unknown;
  componentStack?: string | null;
  fatal?: boolean;
  route: string | null;
  identity: AppIdentity;
  now: Date;
}

export function buildReport(input: BuildReportInput): AppErrorReport {
  const thrown = describeThrown(input.error);
  const name = clip(safeName(thrown.name), NAME_MAX);
  return {
    scope: input.scope,
    name,
    message: clip(scrubText(stripPaths(thrown.message)), MESSAGE_MAX),
    stack: topOfStack(thrown.stack, input.componentStack),
    fatal: input.fatal === true,
    app_version: safeValue(input.identity.appVersion, VERSION, 32) ?? '0.0.0',
    runtime_version: safeValue(input.identity.runtimeVersion, TOKEN, 64),
    update_id: safeValue(input.identity.updateId, TOKEN, 64),
    build: safeValue(input.identity.build, TOKEN, 40),
    platform: input.identity.platform,
    os_version: safeValue(input.identity.osVersion, VERSION, 32),
    route: safeValue(input.route, TOKEN, 64),
    occurred_at: input.now.toISOString(),
  };
}

/** The first frame of a stack (the line after the message). */
export function firstFrame(stack: string | null): string {
  for (const line of (stack ?? '').split('\n')) {
    const trimmed = line.trim();
    if (trimmed.startsWith('at ') || /^[^\s(]*@/.test(trimmed)) return trimmed.replace(/:\d+(:\d+)?/g, '');
  }
  return '';
}

/** Numbers, quoted values, uuids and hex vary per occurrence — the error does not (server `ErrorText::normalise`). */
export function normalise(text: string): string {
  return text
    .replace(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/gi, '{uuid}')
    .replace(/'[^']*'|"[^"]*"/g, '{s}')
    .replace(/\b0x[0-9a-f]+\b|\b[0-9a-f]{12,}\b/gi, '{hex}')
    .replace(/\d+(\.\d+)?/g, '{n}')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Device-side dedupe key: the same error (name + normalised message + first frame) in the same scope. */
export function dedupeKey(report: AppErrorReport): string {
  return [report.scope, report.name, normalise(report.message), normalise(firstFrame(report.stack))].join('|');
}
