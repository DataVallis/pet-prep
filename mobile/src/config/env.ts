/**
 * Environment configuration for PetPrep mobile app.
 *
 * `EXPO_PUBLIC_*` variables are inlined by Metro at bundle time (from `mobile/.env`
 * locally, from `eas.json` `build.<profile>.env` / EAS environment variables on EAS
 * Build). Unset → the production defaults below (`https://api.petprep.si`, Reverb
 * over wss on 443 behind Caddy, which proxies `/app/*` to `reverb:8080`).
 *
 * `EXPO_PUBLIC_REVERB_APP_KEY` must equal the server's `REVERB_APP_KEY`
 * (`/opt/petprep/.env`) — a public identifier, not a secret; a mismatch makes Reverb
 * refuse the socket and the HUD falls back to 10 s polling (grey refresh icon).
 *
 * RevenueCat (M3-07): `EXPO_PUBLIC_REVENUECAT_IOS_KEY` / `EXPO_PUBLIC_REVENUECAT_ANDROID_KEY`
 * are the platform **public** SDK keys (`appl_…` / `goog_…`, safe in the bundle — never the
 * secret REST key). No default: unset → in-app purchases stay disabled (no SDK, no network).
 *
 * Google / Apple sign-in (M2-10c): the Google OAuth client IDs are public identifiers.
 * `EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID` — the ID token's audience on both platforms (the API
 * accepts it); `EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID` — the iOS OAuth client;
 * `EXPO_PUBLIC_GOOGLE_IOS_URL_SCHEME` — its reversed form, registered in Info.plist by the
 * config plugin (`app.config.ts`, only when set). No default: missing → the Google button
 * is hidden (iOS needs all three, Android the web ID). Apple needs no configuration.
 */

export const ENV = {
  API_BASE_URL: process.env.EXPO_PUBLIC_API_URL ?? 'https://api.petprep.si',
  REVERB_APP_KEY: process.env.EXPO_PUBLIC_REVERB_APP_KEY ?? 'clxd3fw5l7ggxgagu281',
  REVERB_HOST: process.env.EXPO_PUBLIC_REVERB_HOST ?? 'api.petprep.si',
  REVERB_PORT: Number(process.env.EXPO_PUBLIC_REVERB_PORT ?? 443),
  REVERB_SCHEME: process.env.EXPO_PUBLIC_REVERB_SCHEME ?? 'https',
  REVENUECAT_IOS_KEY: process.env.EXPO_PUBLIC_REVENUECAT_IOS_KEY ?? '',
  REVENUECAT_ANDROID_KEY: process.env.EXPO_PUBLIC_REVENUECAT_ANDROID_KEY ?? '',
  GOOGLE_WEB_CLIENT_ID: process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID ?? '',
  GOOGLE_IOS_CLIENT_ID: process.env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID ?? '',
  GOOGLE_IOS_URL_SCHEME: process.env.EXPO_PUBLIC_GOOGLE_IOS_URL_SCHEME ?? '',
} as const;

export type Env = typeof ENV;
