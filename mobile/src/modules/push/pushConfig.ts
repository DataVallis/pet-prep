/**
 * Push notification constants and texts (M3-02; texts in `push.json`, M1-18).
 */

import { strings } from '@/i18n/strings';

/** Android channels; the server picks `alarm` for phase 2 / 3, illness and game over. */
export const PUSH_CHANNELS = {
  default: 'default',
  alarm: 'alarm',
} as const;

/** SecureStore keys (letters, digits, ".", "-", "_" only). */
export const PUSH_STORAGE_KEYS = {
  /** The Expo token this install registered — needed to unregister on logout. */
  token: 'petprep.push.expoToken',
  /** When the user tapped "Ne zdaj" on our pre-prompt (ms since epoch). */
  declinedAt: 'petprep.push.prePromptDeclinedAt',
} as const;

/** After "Ne zdaj" we ask again at the next good moment, but not sooner than this. */
export const PRE_PROMPT_RETRY_MS = 3 * 24 * 60 * 60 * 1000;

/** Strong vibration for the alarm channel (ms: wait, buzz, pause, buzz …). */
export const ALARM_VIBRATION = [0, 600, 250, 600, 250, 900];

/** Pre-prompt and Android channel names (`push:push`, M1-18). */
export const PUSH_STRINGS = strings('push', 'push');

/** Who is asked: the child's or the parent's pre-prompt. */
export type PushAudience = 'child' | 'parent';
