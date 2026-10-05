/**
 * Push notification constants and Slovenian texts (M3-02; i18n with M1-18).
 */

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

export const PUSH_STRINGS = {
  channels: {
    default: 'Opomniki za kužo',
    alarm: 'Nujna opozorila',
  },
  prePrompt: {
    child: {
      title: 'Naj te kuža pokliče?',
      message:
        'Ko bo tvoj kuža lačen, žejen, umazan ali bo moral na sprehod, ti pošljemo obvestilo. ' +
        'Med šolo in spanjem (tihe ure) ne pošiljamo ničesar.',
      allow: 'Dovoli obvestila',
      later: 'Ne zdaj',
    },
    parent: {
      title: 'Obvestila o skrbi za kužo',
      message:
        'Obvestimo vas, ko otrok več kot uro ne poskrbi za kužo, ko kuža zboli ali odide v zavetišče. ' +
        'Med tihimi urami ne pošiljamo ničesar. Obvestila ne vsebujejo imen otrok.',
      allow: 'Dovoli obvestila',
      later: 'Ne zdaj',
    },
  },
} as const;

export type PushAudience = keyof typeof PUSH_STRINGS.prePrompt;
