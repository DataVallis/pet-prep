/**
 * Environment configuration for PetPrep mobile app.
 * Values are read from app.json extras or fall back to localhost defaults.
 */

export const ENV = {
  API_BASE_URL: process.env.EXPO_PUBLIC_API_URL ?? 'https://api.petprep.si',
  REVERB_APP_KEY: process.env.EXPO_PUBLIC_REVERB_APP_KEY ?? 'clxd3fw5l7ggxgagu281',
  REVERB_HOST: process.env.EXPO_PUBLIC_REVERB_HOST ?? 'api.petprep.si',
  REVERB_PORT: Number(process.env.EXPO_PUBLIC_REVERB_PORT ?? 443),
  REVERB_SCHEME: process.env.EXPO_PUBLIC_REVERB_SCHEME ?? 'https',
} as const;

export type Env = typeof ENV;
