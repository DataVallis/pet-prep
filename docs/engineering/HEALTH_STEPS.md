# Health steps (M3-04 / M3-05 / M3-06) — build notes and device checklist

Steps from **Apple Health (HealthKit)** on iOS and **Health Connect** on Android, so steps walked while the app is closed count. Code: `mobile/src/modules/steps/` (`health/` adapters, `todaySteps.ts`, `useStepSync.ts`, `backgroundSteps.ts`), UI in `mobile/src/modules/walk/WalkTrackerOverlay.tsx`. Flow diagram: `DIAGRAMS.md` §5b′. Decisions: `docs/product/DECISIONS.md` (2026-10-07).

## What it does (short)
- Child's phone only (child HUD). Reads **one number**: today's step total for the family-local day (`[family midnight, now]`). Read permission for steps only, no writes, no other data types. Only that number (+ `recorded_at`, `source`) goes to `POST /api/child/pet/steps`.
- Sources: health store (when connected) and the motion sensor (iOS CoreMotion history / Android live counter while open). **The app sends the maximum, never the sum.**
- The motion-sensor permission is always offered while undetermined, also when Health is connected (a denied iOS Health read or an empty Health Connect returns 0 — the sensor is then the real source); on iOS the app asks for it right after the Health sheet.
- Triggers: HUD open, foreground (automatic ≤ 1/min), every 5 min, "Osveži", walk overlay closed. iOS: `expo-background-task` (best effort, ~15 min hint, iOS decides). Android: no background read (would need `READ_HEALTH_DATA_IN_BACKGROUND`); the day is caught up on the next open.
- Server unchanged: max per child per day; anti-cheat ≤ 200 steps/min measured from the last accepted sync, so a catch-up after hours is accepted (Pest: `EnergyStepsTest` "health-store catch-up").

## Libraries (2026-10-07)
| Package | Version | Why |
|---|---|---|
| `@kingstinct/react-native-healthkit` (+ `@react-native-healthkit/core`) | 16.0.0 (exact) | Maintained, Nitro / New Architecture, Expo config plugin. 16.1.0 was released the same day — upgrade after a device test. |
| `react-native-nitro-modules` | 0.37.1 (exact) | Peer of the HealthKit library (`>=0.35`). |
| `react-native-health-connect` | 4.1.3 (exact) | Expo plugin is bundled since v4 (`expo-health-connect` is deprecated — do not install it, duplicate class). |
| `expo-build-properties` | ~57.0.18 | `android.minSdkVersion: 26` (Health Connect). |
| `expo-background-task` + `expo-task-manager` | ~57.0.18 | iOS background sync. |

## Native config (in `mobile/app.json`)
- Plugin `@kingstinct/react-native-healthkit` with `NSHealthUpdateUsageDescription` set to a purpose string (EN in `app.json`, SL in `locales/sl.json` — App Store Connect ITMS-90683 rejected build 3.0.1 (15) without it on 2026-10-09, because the library references HealthKit write APIs; the app still never requests write access), `background: false` → entitlement `com.apple.developer.healthkit` only (no background-delivery), no write text.
- `ios.infoPlist.NSHealthShareUsageDescription` (en) + `locales/sl.json` (sl): read-only wording.
- Plugin `react-native-health-connect` → `ACTION_SHOW_PERMISSIONS_RATIONALE` intent filter on `MainActivity` + Android 14 `ViewPermissionUsageActivity` alias.
- `android.permissions` + `android.permission.health.READ_STEPS`.
- Plugins `expo-build-properties` (minSdk 26), `expo-background-task` (adds `UIBackgroundModes: processing` + `BGTaskSchedulerPermittedIdentifiers`).
- Old binaries / dev clients without the task modules don't crash: `backgroundSteps.ts` loads `expo-background-task` / `expo-task-manager` lazily only after `requireOptionalNativeModule('ExpoBackgroundTask' | 'ExpoTaskManager')` finds both; otherwise define / register / unregister are no-ops (tested with an absent-module probe). The health adapters are likewise required lazily in try/catch.
- `npx expo config --type public` resolves (checked 2026-10-07); introspection shows the HealthKit entitlement, `NSHealthUpdateUsageDescription` present (since 2026-10-09, ITMS-90683), BG modes `fetch` + `processing`.

## Before the build (David)
1. **New native EAS build is required** (iOS and Android) — new native modules + entitlement + manifest. An old binary keeps working on the sensor path (the adapter returns null without the native module).
2. iOS: EAS syncs capabilities on build; if it asks, allow enabling **HealthKit** on the App ID `com.datavallis.petprep`. App Store review: HealthKit apps must have a privacy policy and must not use health data for ads — describe "reads today's step count only".
3. Android / Google Play: **Health Connect declaration in Play Console** (health apps declaration, READ_STEPS, purpose "virtual pet walking goal"); approval can take ~7 days + allow-list propagation. Until then, Health Connect works for internal testing on test devices.
4. **Open point:** the Health Connect permission screen's "privacy policy" link fires `ACTION_SHOW_PERMISSIONS_RATIONALE`; today that just opens the app's start screen. Google expects a privacy-policy screen there → small follow-up (native intent handling or a route that shows `petprep.si` privacy policy) before Play release.

## Device checklist — iPhone (dev / preview build)
1. Child login → HUD → "Sprehod". Card **"Štej korake z aplikacijo Zdravje"** with the short explanation; no system sheet before tapping.
2. Tap **Poveži** → Apple Health sheet lists **only "Koraki" (read)**, nothing to write. Allow.
3. Card switches to **"Koraki iz aplikacije Zdravje"** (+ Settings hint). Count = Health app's steps today (from the family midnight; with an Apple Watch it should include watch steps and be ≥ the old motion number).
4. Kill the app, walk ~200 steps, reopen → count rises within seconds (foreground sync).
5. Background (best effort): keep the app closed for 30–60 min while walking, watch the parent dashboard — it may update without opening the child app (not guaranteed). Debug check in Xcode: pause and run `e -l objc -- (void)[[BGTaskScheduler sharedScheduler] _simulateLaunchForTaskWithIdentifier:@"com.expo.modules.backgroundtask.processing"]`, resume → a step POST appears in the server log.
6. Health denied but sensor undetermined: the walk overlay still shows **"Dovoli štetje korakov"** below the Health card; allowing it makes steps appear (max of both).
7. Denied path: Settings → Health → Data Access & Devices → PetPrep → turn Steps off → reopen: steps still come from the motion sensor (if allowed), never drop below what the server has.
8. Repeat "Osveži" quickly several times — no errors; parent sees one walk, not doubled.
9. Logout on the child phone → the background task is unregistered (no more step POSTs from that phone).

## Device checklist — Android (dev / preview build, Android 9+)
1. Android 14+: Health Connect is built in. Android 9–13 without the Health Connect app → card **"Najprej je treba namestiti ali posodobiti Health Connect"** → **Odpri Google Play** opens the Health Connect page.
2. "Sprehod" → card **"Štej korake s Health Connect"** → **Poveži** → Health Connect dialog asks for **Steps (read)** only. Allow → **"Koraki iz Health Connect"**, no "only while open" footnote.
3. Health Connect needs a step writer (Google Fit, Samsung Health, Fitbit, or Android 14+/16 built-in step recording on some phones). Without one, Health Connect returns 0 and the live counter keeps working (max).
4. Close the app, walk, reopen → today's Health Connect total arrives on open.
5. Deny in the dialog → card **"Health Connect še ne deli korakov…"** → **Odpri Health Connect** opens Health Connect; allow Steps there → back in the app it switches to connected (re-checked on return).
6. In the permission dialog tap the privacy-policy link → today the app opens (see open point above).
7. Older Android 7 devices can no longer install the build (minSdk 26).

## Known limits / unsure
- **App Review question:** `UIBackgroundModes` contains `fetch` (added by the `expo-task-manager` config plugin) besides `processing` (ours). We don't use background fetch; if review asks, remove it with a tiny config plugin or explain. Open point.
- iOS never tells an app whether READ access was denied; "connected" means "the sheet was answered". A denied read returns 0, the max keeps the sensor value.
- A shared phone (siblings) reports the phone owner's health steps for whichever child is signed in — same as the existing sensor path (open question for David, see ARCHITECTURE §7).
- **Watch late sync (accepted for now, David to confirm):** Apple Watch steps that reach Health hours later can exceed 200/min since the last sync; the server caps them and accepts the rest on later syncs only as real time passes (same family day only — near midnight some may be lost).
