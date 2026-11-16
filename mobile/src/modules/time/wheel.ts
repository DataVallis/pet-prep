/**
 * Pure math of the time wheels (David 2026-11-16) — `components/time/*` render it.
 * A time is always "HH:MM" (24 h data, what the API takes); the 12 h wheels only change
 * what the hour column shows and add an AM / PM column.
 */

import { formatClock, parseClock } from '@/modules/time/clock';
import { t } from '@/i18n';

export interface WheelItem<T extends string | number> {
  value: T;
  label: string;
}

/** Minutes offered on the minute wheel (15-minute steps). */
export const MINUTE_STEP = 15;

/** Row index under the middle band for a scroll offset (clamped). */
export function indexAtOffset(offsetY: number, rowHeight: number, count: number): number {
  if (count <= 0) return 0;
  return Math.min(count - 1, Math.max(0, Math.round(offsetY / rowHeight)));
}

/** One step up / down without wrapping (screen-reader increment / decrement). */
export function stepIndex(index: number, delta: 1 | -1, count: number): number {
  return Math.min(count - 1, Math.max(0, index + delta));
}

export type Period = 'am' | 'pm';

export interface TimeParts {
  hour: number; // 0–23
  minute: number;
}

export function toParts(hhmm: string | null | undefined, fallback = '08:00'): TimeParts {
  const parsed = parseClock(hhmm) ?? parseClock(fallback) ?? [8, 0];
  return { hour: parsed[0], minute: parsed[1] };
}

export function toHHMM({ hour, minute }: TimeParts): string {
  return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
}

/** 12 h wheel hour (1–12) of a 24 h hour. */
export function hour12Of(hour: number): number {
  return hour % 12 === 0 ? 12 : hour % 12;
}

export function periodOf(hour: number): Period {
  return hour < 12 ? 'am' : 'pm';
}

/** 24 h hour from the 12 h wheels: 12 AM = 0, 12 PM = 12. */
export function hourFrom12(hour12: number, period: Period): number {
  const base = hour12 % 12;
  return period === 'pm' ? base + 12 : base;
}

export function hourItems(hour12: boolean): WheelItem<number>[] {
  return hour12
    ? Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: String(i + 1) }))
    : Array.from({ length: 24 }, (_, i) => ({ value: i, label: String(i) }));
}

/** 00, 15, 30, 45 — plus the current minute when an older value is not on the grid (kept, never silently moved). */
export function minuteItems(current?: number): WheelItem<number>[] {
  const values = new Set<number>();
  for (let m = 0; m < 60; m += MINUTE_STEP) values.add(m);
  if (current !== undefined && current >= 0 && current < 60) values.add(current);
  return [...values].sort((a, b) => a - b).map((m) => ({ value: m, label: String(m).padStart(2, '0') }));
}

export function periodItems(): WheelItem<Period>[] {
  return [
    { value: 'am', label: t('common:clock.am') },
    { value: 'pm', label: t('common:clock.pm') },
  ];
}

/** New time after one wheel moved. */
export function withHour(parts: TimeParts, wheelHour: number, hour12: boolean): TimeParts {
  return { ...parts, hour: hour12 ? hourFrom12(wheelHour, periodOf(parts.hour)) : wheelHour };
}

export function withMinute(parts: TimeParts, minute: number): TimeParts {
  return { ...parts, minute };
}

export function withPeriod(parts: TimeParts, period: Period): TimeParts {
  return { ...parts, hour: hourFrom12(hour12Of(parts.hour), period) };
}

/** What a screen reader / the field shows for a value. */
export function timeLabel(hhmm: string, hour12: boolean): string {
  const { hour, minute } = toParts(hhmm);
  return formatClock(hour, minute, hour12);
}
