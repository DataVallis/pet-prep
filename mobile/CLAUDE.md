@AGENTS.md

# Mobile (Expo) — agent notes

Root rules in `../CLAUDE.md` apply. Since 2026-10-02 this folder is a normal part of the `pet-prep` monorepo (no submodule).

## Stack
Expo SDK 57 · React Native 0.86 · React 19 · TypeScript (strict) · NativeWind 4 (Tailwind 3) · Zustand 5 · TanStack Query 5 · laravel-echo + pusher-js · expo-video · expo-secure-store · expo-sensors · lucide-react-native · Jest (jest-expo) + RNTL.
Expo APIs change between SDKs — check https://docs.expo.dev/versions/v57.0.0/ (see AGENTS.md) before using any module. The `expo` Claude plugin is enabled in `.claude/settings.json`.

## Commands
`yarn install` · `npx expo start` · `yarn test` · `npx tsc --noEmit` · `eas build --profile development|preview|production` (`eas.json`).
The app now defaults to the **production** API (`https://api.petprep.si`, wss :443) — set `EXPO_PUBLIC_API_URL` etc. in `mobile/.env` for local work. `credentials.json` (signing) is local-only and gitignored.
Env: `EXPO_PUBLIC_API_URL`, `EXPO_PUBLIC_REVERB_APP_KEY|HOST|PORT|SCHEME` (see `src/config/env.ts`). Physical device → use the Mac's LAN IP, Android emulator → `10.0.2.2`.

## Structure & conventions
- `src/api/client.ts` — the only place that calls `fetch`. Type requests/responses with `src/api/schema.ts` (generated; never hand-edit).
- Server state → TanStack Query hooks (`src/hooks/queries/…`); Zustand (`src/store/appStore.ts`) only for session token, ws status, lock state, overlay visibility.
- Screens in `src/screens/` (child) and `src/screens/parent/`; reusable UI in `src/components/`; feature modules in `src/modules/<feature>/`.
- Styling: NativeWind classes; design tokens per README ADR-007 (child = dark glass HUD, parent = clean light). Status colours: emerald-500 / amber-500 / rose-500.
- Path aliases: `@/`, `@api/`, `@components/`, `@hooks/`, `@screens/`, `@store/`, `@modules/`, `@types/`, `@utils/`.
- No `any`. No `console.log` left in committed code (use a tiny logger).

## Current reality (2026-10-02) — read before coding
- `AppNavigator` routes by `user.role` (parent → `ParentDashboardScreen`, child → HUD); `/api/login` and `/api/user` now also return `pet`. `ParentAppNavigator` is obsolete.
- Feed/Water only add +20 % locally (not persisted, no backend endpoint); walk sync and cleaning are local only; parent dashboard timeline/chart still use `MOCK_*` data; paywall is simulated.
- `PairingScreen` has quick-login buttons with seeded test credentials — must be `__DEV__`-only (M0-10).
- Session isn't restored on launch; hard stop never sets `lockState`.
- `expo-sensors` Pedometer cannot read step history on Android → Health Connect needed (M3-05).
- HealthKit, RevenueCat and push require a **dev build**, not Expo Go.
- `src/components/WalkTrackerOverlay.tsx` is a stale duplicate of `src/modules/walk/WalkTrackerOverlay.tsx`.
- Jest: NativeWind babel preset is disabled in tests. Keep `@react-native/jest-preset` pinned to the installed RN minor (0.86.x) — 0.87 mocks `react-native/setup-env`, which RN 0.86 lacks. TypeScript 6 needs explicit `"types": ["jest"]` in tsconfig.
- `usePetWebSocket` polling fallback is a no-op (TODO) — should refetch pet state via TanStack Query.
