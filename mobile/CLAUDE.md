@AGENTS.md

# Mobile (Expo) — agent notes

Root rules in `../CLAUDE.md` apply. Since 2026-10-02 this folder is a normal part of the `pet-prep` monorepo (no submodule).

## Stack
Expo SDK 57 · React Native 0.86 · React 19 · TypeScript (strict) · StyleSheet only (NativeWind removed 2026-10-05) · Zustand 5 · TanStack Query 5 · laravel-echo + pusher-js · expo-video · expo-secure-store · expo-sensors · lucide-react-native · Jest (jest-expo) + RNTL.
Expo APIs change between SDKs — check https://docs.expo.dev/versions/v57.0.0/ (see AGENTS.md) before using any module. The `expo` Claude plugin is enabled in `.claude/settings.json`.

## Commands
`yarn install` · `npx expo start` · `yarn test` · `npx tsc --noEmit` · `eas build --profile development|preview|production` (`eas.json`).
The app now defaults to the **production** API (`https://api.petprep.si`, wss :443) — set `EXPO_PUBLIC_API_URL` etc. in `mobile/.env` for local work. `credentials.json` (signing) is local-only and gitignored.
Env: `EXPO_PUBLIC_API_URL`, `EXPO_PUBLIC_REVERB_APP_KEY|HOST|PORT|SCHEME` (see `src/config/env.ts`). Physical device → use the Mac's LAN IP, Android emulator → `10.0.2.2`.

## Structure & conventions
- `src/api/client.ts` — the only place that calls `fetch`. Type requests/responses with `src/api/schema.ts` (generated; never hand-edit).
- Server state → TanStack Query hooks (`src/hooks/queries/…`); Zustand (`src/store/appStore.ts`) only for session token, ws status, lock state, overlay visibility.
- Screens in `src/screens/` (child) and `src/screens/parent/`; reusable UI in `src/components/`; feature modules in `src/modules/<feature>/`.
- Styling: React Native `StyleSheet` only (no `className`; guarded by `src/__tests__/noClassName.test.ts`); design tokens per README ADR-007 (child = dark glass HUD, parent = clean light). Status colours: emerald-500 / amber-500 / rose-500.
- Path aliases: `@/`, `@api/`, `@components/`, `@hooks/`, `@screens/`, `@store/`, `@modules/`, `@types/`, `@utils/`.
- No `any`. No `console.log` left in committed code (use a tiny logger).

## Current reality (2026-10-04) — read before coding
- Entry is `StartScreen`: "Sem otrok" → `ChildPinLoginScreen` (PIN-only, `POST /api/child/pin-login`, no e-mail for children) / "Sem starš" → `ParentLoginScreen` (e-mail). `AppNavigator` routes by `user.role`; a child whose `awaiting_contract` (per child, from `/api/user`, `/api/login`, pin-login) is true goes to `ContractScreen` before the HUD. `PairingScreen` and `ParentAppNavigator` are gone.
- Session is restored on launch (M1-12): `src/modules/session/` (`restoreSession`, `useSessionBootstrap`, `logout`). Login and restore both go through `appStore.signIn()`; **always log out via `logout()`** (revokes, clears token + query cache + store). `client.ts` calls the registered unauthorized handler on a 401 only if the rejected token is still the stored one; the server revoke in `logout()` is aborted after 5 s. Bootstrap runs carry a run id — only the newest applies.
- One shared `QueryClient` in `src/api/queryClient.ts` (no retries on 4xx); TanStack `focusManager` is bound to `AppState`, so `refetchInterval` polling pauses in the background. Tests use `src/test-utils/renderWithQuery.tsx` — keep `gcTime: Infinity` there, a finite gcTime leaves a 5-min timer that hangs Jest. TanStack notifies via `setTimeout(0)`: under fake timers flush with `jest.advanceTimersByTimeAsync(0)`.
- Realtime: `src/modules/realtime/echoConfig.ts` + `usePetWebSocket` subscribe to `private-pet.{id}`, event `.pet.updated` (leading dot), auth via `api.authorizeChannel()` (Bearer token → `POST /api/broadcasting/auth`); wss when scheme is https.
- Child HUD runs on `useChildPet()` (`['child','pet']`, `GET /api/child/pet`); actions in `hooks/queries/useChildActions.ts` (optimistic, then ALWAYS the server `state`, also on 422/423); steps in `modules/steps/` (iOS motion history since the family-local midnight, Android live counter per child while the app is open; step day = family day via `familyCalendar`). `ChildPetState` from `schema.ts` is loosely typed → always go through `normalizeChildState`. Paywall simulated.
- Dev quick-login (parent only) renders only when `__DEV__` (M0-10); test the child side by creating a child + PIN. The lucide mock in `__mocks__/` returns a View for any icon name.
- Family: parent "Dodaj otroka" (`AddChildScreen`: nickname + optional birth year → "Nov pes" / "Pridruži se psu" → PIN) and `FamilyChildrenCard` (relogin PIN, "Odjavi vse naprave") — many children allowed (backend limit 10). Device name = phone model only, never the user-given device name (child PII).
- Parent app is light (ADR-007) and fully on real data: dashboard query live via one Echo in `usePetChannels` → `applyParentBroadcast` (`modules/family/live.ts`); polls every 30 s unless every pet channel is live, every 3 min while live; tick refetches throttled to 1/min; read loose payloads through `modules/family/scoring.ts` (`FamilyPet` = cleaned type, `FamilyPetRaw` = schema type). The parent broadcast path no longer touches the store. `api.setHardStop(petId, active)` sets the state idempotently — send the intent the parent confirmed. Fixtures: `makeScoredChild`, `makeScoredDashboard`, `makeMissed`, `makeDayRow`. Breeds tab still dark/simulated (M3).
- Lock comes from the server state (`lockStateFromView` in the HUD, `lockStateFromPet` on restore — hard stop, inactive, illness, game over) with `lockDetails` for "do 18:30"; `LockedScreen` is rendered only by `AppNavigator`.
- `expo-sensors` Pedometer cannot read step history on Android → Health Connect needed (M3-05).
- HealthKit, RevenueCat and push require a **dev build**, not Expo Go.
- Realtime: `enabledTransports: ['ws','wss']` always; Pusher constructor via `resolvePusherConstructor` (the RN build exports `{ Pusher }`). HUD geometry in `modules/hud/hudLayout.ts`.
- Jest: Keep `@react-native/jest-preset` pinned to the installed RN minor (0.86.x) — 0.87 mocks `react-native/setup-env`, which RN 0.86 lacks. TypeScript 6 needs explicit `"types": ["jest"]` in tsconfig.
- `usePetWebSocket(petId, handler?)` reports `connected` only after the channel subscription succeeds and drops events older than the last `emitted_at`; `useChildPet` polls every 10 s while not connected; the child routes broadcasts to `applyBroadcastToCache`, the parent (no handler) to the store. Jest runs with `TZ=UTC` (`jest.config.js`). Tests: `makeLiveChildState()` / `makeBroadcast()` in `test-utils/fixtures.ts`.
- M5-R04: `AddChildScreen` steps `form → pet → dog → pin`; `DogPickerStep` sends the full `{breed, origin, age_stage}` via `generatePinBody()` (never with `pet_id`), plus `features: ["behaviour_events"]`; child `pinLogin` always sends the same `features`. Join and re-login skip the picker. Read the pet profile only via `modules/petProfile/petProfile.ts#readPetProfile` (null for legacy); never show values listed in `profile.unverified` as facts.
- M5-R02: `ChildPetView.behaviour` comes from `readChildBehaviour` (missing → `EMPTY_BEHAVIOUR`). Scrub vs. tidy via `needsScrubbing` / `onlyChewingOpen` — never `needs_cleaning` alone (a chewed slipper sets it too). "Pelji ven" only when `behaviour.take_out` is non-null. Video keys are `VideoState` (six states + `accident`/`chewing`); pass a scene to `PetMediaView` only from `behaviour.scene`. Video fallbacks live in `petMedia.ts#VIDEO_FALLBACKS` (sick/low_energy → sleeping → idle). Fixtures: `makeTakeOut()`, `makeBehaviourEvent(kind)`, `makeLiveChildState({ behaviour })`.
- M5-R03: `ChildPetView.training` from `readChildTraining` (missing/disabled → `EMPTY_TRAINING`, no "Šola"). Game logic is pure in `modules/training/game.ts` (mirrors the server scorer incl. 150 ms floor; first tap per cue; `SessionClock` + touch timestamps); `useTrainingGame` finishes once per session and resumes from the server schedule. Parse start/finish only via `readStartResponse` / `readFinishResponse`. `CLIENT_FEATURES = ['behaviour_events','training']`. Every effect that calls the network must be bounded (push-registration incident 2026-10-06).
