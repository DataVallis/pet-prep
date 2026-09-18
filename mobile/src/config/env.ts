/**
 * Environment configuration for PetPrep mobile app.
 * Values are read from app.json extras or fall back to localhost defaults.
 */

export const ENV = {
  // iOS Simulator: use 127.0.0.1 (localhost resolves to the simulator, not the host Mac)
  // Android Emulator: use 10.0.2.2 (maps to host loopback)
  // Physical device: use your Mac's LAN IP (e.g. 192.168.x.x)
  API_BASE_URL: process.env.EXPO_PUBLIC_API_URL ?? 'http://127.0.0.1:8000',
  REVERB_APP_KEY: process.env.EXPO_PUBLIC_REVERB_APP_KEY ?? 'clxd3fw5l7ggxgagu281',
  REVERB_HOST: process.env.EXPO_PUBLIC_REVERB_HOST ?? '127.0.0.1',
  REVERB_PORT: Number(process.env.EXPO_PUBLIC_REVERB_PORT ?? 8080),
  REVERB_SCHEME: process.env.EXPO_PUBLIC_REVERB_SCHEME ?? 'http',
} as const;

export type Env = typeof ENV;
