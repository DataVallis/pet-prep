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
 */

export const ENV = {
  API_BASE_URL: process.env.EXPO_PUBLIC_API_URL ?? 'https://api.petprep.si',
  REVERB_APP_KEY: process.env.EXPO_PUBLIC_REVERB_APP_KEY ?? 'clxd3fw5l7ggxgagu281',
  REVERB_HOST: process.env.EXPO_PUBLIC_REVERB_HOST ?? 'api.petprep.si',
  REVERB_PORT: Number(process.env.EXPO_PUBLIC_REVERB_PORT ?? 443),
  REVERB_SCHEME: process.env.EXPO_PUBLIC_REVERB_SCHEME ?? 'https',
} as const;

export type Env = typeof ENV;
