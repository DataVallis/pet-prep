-----

# 🚀 Master Prompt (System Architecture Overview)

``` text
You are an expert Senior Full-Stack Engineer and Software Architect tasked with building the Minimum Viable Product (MVP) for "PetPrep" (an AI pet simulation application that teaches children responsibility before acquiring a real pet). 

### System Overview & Tech Stack
- Frontend: Mobile application built with React Native (Expo SDK) and styled using NativeWind (Tailwind CSS).
- Backend: PHP Laravel API with Laravel Filament for administrative management.
- Database: PostgreSQL.
- Real-time Synchronization: WebSockets via Laravel Reverb.
- In-App Purchases: RevenueCat SDK integration.
- Health & Motion Tracking: Apple HealthKit and Google Fit APIs.

### Core Architecture & Dual-Profile Model
The system operates on a dual-profile model:
1. Parent Profile (Controller/Dashboard): Analytical UI, generates PINs, monitors metrics in real time via WebSockets, manages silent hours, purchases breeds via RevenueCat, and enforces hard stops.
2. Child Profile (Executor): Video HUD interface, performs daily pet maintenance tasks (feeding, watering, cleaning poop, walking), tracks step counts, and receives escalation alerts.

Please adhere strictly to the modular phase instructions provided below.

```

-----

# 📦 Phase 1: Database Architecture & Backend Setup (Laravel & PostgreSQL)

``` text
Act as a Principal Backend Engineer. Build the complete backend infrastructure for the PetPrep MVP using Laravel, PostgreSQL, and Laravel Reverb.

### 1. PostgreSQL Database Schema
Implement the following database schema using Laravel migrations:

1. `users` table:
   - `id` (Primary Key, BigIncrements)
   - `role` (Enum: 'parent', 'child')
   - `parent_id` (Nullable Foreign Key -> users.id; NULL for parents, required for child profiles)
   - `pairing_pin` (String, nullable, 6-digit temporary pairing code)
   - `revenuecat_id` (String, nullable, unique customer identifier for IAP verification)
   - `created_at`, `updated_at`

2. `pets` table:
   - `id` (Primary Key, BigIncrements)
   - `user_id` (Foreign Key -> users.id of the child user)
   - `breed_type` (Enum: 'mutt', 'border_collie')
   - `hunger_level` (Integer 0–100, defaults to 100)
   - `energy_level` (Integer 0–100, defaults to 100; synced via pedometer)
   - `hygiene_level` (Integer 0–100, defaults to 100)
   - `born_at` (Timestamp; used to compute virtual age: 1 real week = 1 virtual month)
   - `is_active` (Boolean, default true; indicates active simulation session)
   - `created_at`, `updated_at`

3. `activities_log` table:
   - `id` (Primary Key, BigIncrements)
   - `pet_id` (Foreign Key -> pets.id)
   - `activity_type` (Enum: 'fed_pet', 'watered_pet', 'walked_pet', 'cleaned_poop', 'ignored_warning')
   - `value` (Integer, stores exact metrics such as step counts or timestamp values)
   - `created_at` (Timestamp)

4. `breed_configs` table:
   - `id` (Primary Key, BigIncrements)
   - `breed_slug` (String, unique, e.g., 'mutt', 'border-collie')
   - `daily_steps_required` (Integer; 4,000 for mutt, 10,000 for Border Collie)
   - `hunger_decay_rate` (Float; -8%/hr for mutt, -12%/hr for Border Collie)
   - `premium_unlock` (Boolean; true for paid breeds)

5. `pets` table — **Pet DNA & AI Media Fields** (added for fal.ai integration):
   - `pet_dna` (JSONB, required): Stores the pet's unique visual identity payload:
     - `seed` (Unsigned BigInteger): Fixed random seed for image/video generation.
     - `visual_traits` (JSON): Color scheme, markings, eye color, fur texture, unique physical markers.
     - `prompt_anchor` (Text): Base prompt describing the exact visual features of this specific pet.
     - `reference_image_url` (String, nullable): Public URL of the generated base canonical photo of the pet used as the Image-to-Video / Image-to-Image reference anchor.
   - `current_video_url` (String, nullable): URL of the currently active Kling 3.0 video loop for the child UI.

### 2. Pet State Enum & fal.ai Service
- Implement `PetStateEnum` (Enum: 'idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing') — each state maps to a state-specific video prompt modifier.
- Implement `FalAiService.php` to encapsulate all calls to the fal.ai REST/WebSocket API:
  - `generateInitialPetDna(BreedType $breed): array` — Generates a unique seed, prompt anchor, visual traits, and calls fal.ai (Flux) to generate the initial reference image.
  - `generatePetVideoState(Pet $pet, PetStateEnum $state): ?string` — Triggers a Kling 3.0 Image-to-Video generation job on fal.ai using `pet.reference_image_url` and `pet_dna.seed` to maintain exact pet appearance consistency.
  - Asynchronous Webhook Handling: `POST /api/webhooks/fal-ai` — Handles webhooks from fal.ai when video rendering completes, updating `pets.current_video_url` and broadcasting the update via Laravel Reverb to the parent & child UI.

### 3. Pairing & API Endpoints
- Implement `POST /api/parent/generate-pin`: Generates a temporary 6-digit PIN linked to the parent account.
- Implement `POST /api/child/pair`: Accepts a 6-digit PIN, pairs the child user to the parent (`parent_id`), and initializes the child's pet session. During pairing, `FalAiService::generateInitialPetDna()` is called to create the pet's visual identity (seed, prompt anchor, reference image) and stored in `pets.pet_dna`.
- Implement Laravel Reverb WebSocket Broadcasts: Trigger real-time updates to the parent dashboard (`pet.updated.{petId}` channel) whenever any metric changes, an entry is saved to `activities_log`, or a fal.ai video rendering webhook completes.

```

-----

# ⏱️ Phase 2: Game Loop, Metric Decay & Escalation Logic

``` text
Act as a Lead Game Logic & Backend Developer. Implement the core simulation game loop, scheduled tasks, decay algorithms, and push notification escalation matrices in Laravel.

### 1. Time Asymmetry Calculation
- 1 real week = 1 virtual month.
- Total MVP session duration: 12 real weeks = 12 virtual months (1 year of pet life). Upon reaching 12 weeks with satisfactory performance, generate a "Responsibility Certificate".

### 2. Scheduled Cron Job Logic (`app/Console/Kernel.php`)
Create a minutely scheduled job to calculate metric decay for active pets:
- Check configured "Quiet Hours" (e.g., 08:00–13:00 during school and 22:00–06:00 during sleep) set by parents. Pause or reduce decay rates by 90% during these periods.
- Decay Rates (Non-Quiet Hours):
  * Mutt: Hunger -8%/hr (0 in 12h), Thirst -10%/hr (0 in 10h), Hygiene triggers random drop to 0% once daily. Daily step goal: 4,000 steps.
  * Border Collie: Hunger -12%/hr (0 in 8h), Thirst -15%/hr (0 in 6.6h), Hygiene triggers random drop to 0% twice daily. Daily step goal: 10,000 steps.
- Reset step counts daily at midnight.

### 3. Escalation Matrix & Push Notifications
1. Phase 1 (Soft Warning at 30% metric level): Send a standard push notification to the child ("Your pet is looking at its food bowl...").
2. Phase 2 (Critical Alert at 10% metric level): Send high-priority push notification with sound/vibration ("Critical warning: Your pet is starving!...").
3. Phase 3 (Parent Intervention at 0% for >1 hour): Trigger Laravel Reverb WebSocket alarm directly to the parent's device ("Your child has neglected their pet!").

### 4. Severe Neglect & Game Over Mechanics
- Illness State: If hygiene or movement stays at 0% for >6 hours outside quiet hours, place the pet in "Illness/Vet State" (render greyed-out state and apply a 12-hour action lock out).
- Game Over State: If any metric remains at 0% for 24 continuous hours, trigger the "Virtual Shelter Protocol", lock the child's interface, and require explicit parent intervention/reset.

```

-----

# 📱 Phase 3: Child Mobile Application (React Native & NativeWind)

``` text
Act as a Lead React Native Mobile Engineer. Build the Child Application UI/UX using React Native, Expo, NativeWind (Tailwind CSS), and Expo Notifications.

### 1. UI Layout & HUD Design
- Full-Screen Video Background: Render a dynamic background video player (displaying state-dependent assets for idle, sleeping, low energy, hungry, or sick).
- Top Status Bar (Glassmorphism): Display current virtual age calculated from `born_at` (e.g., "Age: 2 months").
- Right-Side Progress Bars: 4 vertical sliders displaying 0–100% status for Hunger, Thirst, Movement, and Hygiene. Color shifts dynamically from green to red based on severity.
- Bottom Action Buttons: Semi-transparent icon buttons for Feed (briketi), Water, Walk (povodec), and Clean (metla). Disable buttons outside valid action windows.

### 2. Feature Workflows
- Onboarding & Pairing: Screen with a 6-digit PIN input field. Upon successful pairing, display a digital "Responsibility Contract" requiring a touch gesture signature before initializing the pet.
- Walk Tracking Module:
  * Integrate Apple HealthKit (iOS) & Google Fit API (Android).
  * Display step tracker overlay ("X / 5,000 daily steps").
  * Read steps from device sensors, update local state, sync step logs to backend (`POST /api/activity/walk`), and restore `energy_level`.
- Interactive Cleaning Mini-Game: When hygiene drops to 0%, cover the screen with a dirt overlay asset. Block all actions until the user performs swipe gestures to swipe away dirty spots.
- Hard Stop Locked Screen: Render a black overlay screen when the parent triggers a hard stop or game over state ("Simulation paused. Speak with your parents.").

```

-----

# 🛡️ Phase 4: Parent Dashboard & RevenueCat Monetization Integration

``` text
Act as a Senior Frontend & Mobile Engineer. Build the Parent Dashboard UI in React Native / NativeWind and integrate RevenueCat SDK for monetization.

### 1. Parent Dashboard UI Layout
- Overview Traffic Light Indicator: Large banner at the top displaying overall status:
  * Green: All routines met consistently.
  * Yellow: Missed >2 tasks today.
  * Red: Critical neglect / alert active.
- Real-Time Live Meters: 4 status meters updated instantaneously via Laravel Reverb WebSockets without manual screen refreshes.
- Activity Log & Timeline: Chronological timeline rendering entries fetched from `activities_log` (e.g., "✓ 07:15 - Pet fed", "✗ 14:00 - Missed cleaning poop").
- Weekly Performance Chart: Bar chart highlighting consistency patterns and low-motivation days.

### 2. Controls & Interventions
- Quiet Hours Manager: Time picker interface allowing parents to define school hours (e.g., 08:00–13:00) and bedtime.
- Hard Stop Button: Emergency red toggle button sending a real-time signal via WebSockets to immediately lock/unlock the child's app interface.

### 3. RevenueCat IAP Integration
- Set up RevenueCat SDK with Apple App Store & Google Play Billing.
- Implement Breed Selection Screen:
  * Free Tier: Mutt (standard decay parameters).
  * Premium Paid Tier: Border Collie (4.99 EUR one-time unlock).
- Handle non-consumable purchase verification via RevenueCat, updating `users.revenuecat_id` and unlocking the premium breed in PostgreSQL upon successful receipt verification.

```

-----

### Key Highlights of this Prompt Suite

- **Zero Ambiguity:** Defines explicit schema fields, API routes, decay percentages, time conversion formulas, and UI states.
- **Tech Stack Compliance:** Fully aligned with React Native (Expo), NativeWind, Laravel, PostgreSQL, Reverb, and RevenueCat.
- **Modular Design:** Can be fed to an AI coding agent either all at once using the Master Prompt or phase-by-phase for iterative development.

Tukaj je podroben **UI/UX Design & Visual System Prompt** v angleščini, ki ga lahko posreduješ AI agentu (ali priložiš k prej pripravljenim promptom). Agentu bo natančno določil vizualni jezik, barvno paleto, tipografijo, mikro-interakcije in postavitev komponent za oba profila.

-----

🎨 **Phase 5: Design System & UI/UX Specification Prompt**

``` markdown
You are a Principal UI/UX Designer and Lead Design Engineer. Implement the complete Visual Design System and Layout Architecture for the PetPrep MVP using React Native, NativeWind (Tailwind CSS), and Lucide Icons.

### 1. Dual-Aesthetics Design Philosophy
- Child Profile (Immersive Video HUD): Gamified, transparent Heads-Up Display (HUD) floating over full-bleed video/image assets. High-contrast translucent glassmorphism overlays to keep the AI pet centered.
- Parent Profile (Fintech/Health Analytics): Clean, highly structured, minimal dark/light analytical dashboard (similar to modern banking or fitness apps). Low friction, high scannability.

---

### 2. Design Tokens & Color System

#### Color Palette (Tailwind / NativeWind mapping)
- Status & Metric Dynamics (Traffic Light System):
  - Excellent / Healthy: Emerald Green (`emerald-500` / `#10B981`)
  - Warning / Moderate: Amber Yellow (`amber-500` / `#F59E0B`)
  - Critical / Danger: Rose Red (`rose-500` / `#EF4444`)
- Child App Overlays (HUD Dark/Glass Theme):
  - Glass Container Background: `bg-slate-900/40` or `bg-black/30` with `backdrop-blur-lg`
  - Border Highlights: `border-white/20`
  - Text: Primary (`text-white`), Secondary (`text-slate-300`)
- Parent App Dashboard (Clean Theme):
  - Screen Background: `bg-slate-50` (`#F8FAFC`) or Dark Mode `bg-slate-900` (`#0F172A`)
  - Card Backgrounds: White (`#FFFFFF`) or `bg-slate-800`
  - Primary Accent: Indigo (`indigo-600` / `#4F46E5`)
  - Text: Primary (`text-slate-900`), Secondary (`text-slate-500`)

#### Typography Hierarchy
- Font Family: System Default (SF Pro for iOS, Roboto for Android).
- Headings: Bold, Tracking-tight (`text-2xl font-bold tracking-tight`).
- Badges & Timestamps: Medium, Monospace/Caps (`font-mono text-xs uppercase tracking-wider`).

---

### 3. Child Interface (Video HUD Architecture)

1. Full-Bleed Viewport Container:
   - Media Viewport: `absolute inset-0 z-0` rendering background video loop.
   - Dynamic States: Idle, Sleeping, Low Energy, Scratched Bowl (Hungry), Sick (Grey Tinted).

2. Top Status Bar (Glassmorphism Pill):
   - Position: `absolute top-12 left-4 right-4 z-10 flex-row justify-between items-center`.
   - Style: `bg-slate-900/40 backdrop-blur-md rounded-2xl px-4 py-3 border border-white/10`.
   - Components: 
     - Pet Name & Virtual Age Badge: Pill container with monospace text (e.g., `AGE: 2 MONTHS`).
     - Connection Status Dot: Pulse animation indicator (Green for synced backend connection).

3. Right-Edge Metric Sliders (Vertical Progress Bars):
   - Position: `absolute right-4 top-32 z-10 flex-col gap-4`.
   - Bar Style: 4 vertical progress tracks (width 12px, height 120px) for **Hunger, Thirst, Movement, Hygiene**.
   - Metric Fill: Interpolates color dynamically from Green (100%) -> Yellow (50%) -> Red (<20%). Icon badges anchored above each bar (Bowl, Water Drop, Footsteps, Broom).

4. Bottom Action Bar (Floating Control Dock):
   - Position: `absolute bottom-8 left-6 right-6 z-10 flex-row justify-around items-center`.
   - Action Buttons: 4 circular semi-transparent buttons (`w-16 h-16 rounded-full bg-white/20 border border-white/30 items-center justify-center backdrop-blur-md active:scale-95`).
   - Disabled State: When feeding/watering is outside time windows, apply `opacity-40` and `bg-slate-800/60`.

5. Screen Overlays & Modals:
   - Cleaning Mini-game Overlay: `absolute inset-0 z-20 bg-amber-950/60` covered with 5 randomly generated dirt/splatter SVGs. Requires touch swipe gestures to scrub away dirt spots.
   - Hard Stop Locked Screen: `absolute inset-0 z-50 bg-black flex-col items-center justify-center p-6`. Displays red lock icon, bold notice text (*"Simulation paused. Speak with your parents."*), and parent PIN unlock field.

---

### 4. Parent Dashboard Interface

1. Top Status Banner (Traffic Light System):
   - Large hero card (`rounded-3xl p-6 shadow-sm border`).
   - Green State: Light emerald container with green check icon and text: *"Simulation Healthy - 100% Routine Compliance"*.
   - Yellow State: Amber background notification (*"2 routines missed today"*).
   - Red State: Dark rose container with warning alert icon and pulsing notification (*"Critical Neglect - Pet Health Compromised"*).

2. Real-Time Live Metric Grid:
   - 2x2 grid of metric cards displaying real-time values updated via WebSockets.
   - Each card contains: Icon, Metric Name, Percentage Bar, and real-time status text (e.g., *"Fed 2h ago"*).

3. Activity Timeline Widget:
   - Vertical timeline stream listing recent activities from `activities_log`.
   - Timeline Node Icons:
     - `✓` Green badge for completed routines.
     - `✗` Red badge for missed alerts or neglected tasks.
     - Step count entries displaying pre-formatted numbers (e.g., *"5,120 steps recorded via HealthKit"*).

4. Quiet Hours & Emergency Controls:
   - Time range slider / dual time-picker for setting quiet school/sleep hours.
   - Prominent **Emergency Hard Stop Button**: Full-width red button (`bg-rose-600 hover:bg-rose-700 text-white font-semibold py-4 rounded-xl shadow-lg active:scale-98`).

5. RevenueCat Breed Paywall Cards:
   - Card 1 (Mutt): Border gray, free badge, standard stats summary.
   - Card 2 (Border Collie): Border indigo with "RECOMMENDED / PREMIUM" badge, high-energy stats indicator, and native Apple Pay / Google Pay button displaying `4.99 €`.

```

Za preprečitev nastanka krhkega koda (t. i. *vibe coding* pasti, kjer aplikacija deluje le na prvem demonstracijskem testu, ob skaliranju ali izpadu povezave pa se poruši), sem pripravil štiri nove **inženirske in dokumentacijske module**.

Ti moduli definirajo **tehnične omejitve, arhitekturne standarde, protokole testiranja in sistem za neprekinjeno dokumentiranje (`HANDOFF.md`)**, kar kateremukoli razvijalcu ali AI agentu omogoča takojšnje nadaljevanje dela brez izgube konteksta.

-----

### ⛔ Faza 6: Omejitve in obvezne funkcionalnosti (Boundaries & Scope Prompt)

``` 
You are the Lead Systems Architect. Enforce strict scope boundaries, mandatory capabilities, and functional exclusions for the PetPrep MVP build.

### 1. STRICTLY EXCLUDED (DO NOT IMPLEMENT IN MVP)
To ensure MVP stability and prevent over-engineering, you MUST NOT write code for:
- Augmented Reality (AR) camera features or 3D ball throwing.
- Real-time GPS Map Tracking or Map Rendering (Use pedometer step counting ONLY).
- Real-time Weather API integrations.
- AI Vision / LLM Veterinary Triage (Pinecone/pgvector/GPT-4o integrations are strictly Phase 2).
- B2B Store / Affiliate QR code validation engines.
- Multi-child or Multi-pet support per parent (MVP supports strictly 1 Parent -> 1 Child -> 1 Pet).

### 2. MANDATORY MVP CRITICAL PATH (MUST WORK 100%)
The following workflows MUST be fully functional, edge-case resilient, and thoroughly validated:
1. PIN Pairing System: Parent generates 6-digit PIN with a 15-minute expiration window. Child enters PIN -> Atomic DB transaction pairs accounts and sets `parent_id`.
2. Metric Decay Loop: Minutely Laravel cron job accurately calculates decay based on breed decay rates, respecting parent-defined Quiet Hours.
3. Sensor Syncing: Reads step counts from Apple HealthKit / Google Fit, validates steps against basic rate limits (anti-cheat: ignore step spikes >200 steps/min), updates `energy_level`, and logs to `activities_log`.
4. WebSockets via Laravel Reverb: Instant state synchronization between Child UI actions and Parent Dashboard (<500ms latency).
5. 3-Tier Escalation Matrix: Triggering Phase 1 (Soft Push), Phase 2 (Critical Sound/Vibration Push), and Phase 3 (Parent WebSocket Alarm + Hard Stop option).
6. RevenueCat IAP Flow: Verification of receipt for "Border Collie" unlock, fallback to "Mutt" if unpaid.

```

-----

### 🏗️ Faza 7: Inženirska arhitektura in zaščita pred skaliranjem (Production Engineering Prompt)

``` 
Act as a Principal Software Engineer. Implement enterprise-grade architectural patterns to prevent technical debt, race conditions, state desynchronization, and performance bottlenecks.

### 1. Backend Architecture (Laravel Standards)
- Type Safety & Validation: Use Laravel Form Request Validation classes for ALL incoming API endpoints. Do not validate inside controllers.
- Controller Layer: Controllers MUST be thin (Invokable or maximum 5 resource methods). Business logic MUST reside in dedicated Service classes (e.g., `PetDecayService`, `PairingService`).
- Database Integrity & Transactions:
  * Wrap all multi-table DB operations in `DB::transaction()`.
  * Add DB indexes on high-frequency query columns: `users(parent_id)`, `pets(user_id, is_active)`, `activities_log(pet_id, created_at)`, `users(pairing_pin)`.
- Asynchronous Processing: Push notifications and external service calls MUST be dispatched via Laravel Queues (`ShouldQueue` interface) to prevent blocking HTTP API responses.
- API Rate Limiting: Apply Sanctum authentication and rate limiters (`throttle:60,1` for general APIs, `throttle:5,1` for PIN pairing requests).

### 2. Frontend Architecture (React Native & TypeScript)
- Strict TypeScript: Set `compilerOptions.strict: true`. The use of `any` is strictly prohibited. Define explicit types/interfaces for all API payloads, DB models, and navigation parameters.
- State Management & Caching: Use TanStack Query (React Query) for API data fetching/caching and Zustand for global UI state (e.g., active pairing status, WebSocket connection state).
- Connection Resilience & Fallback: Implement automatic retry logic for WebSocket reconnection in Laravel Reverb. If WebSockets drop, fall back to background polling every 10 seconds until reconnected.
- Error Boundaries & Offline Support: Wrap major UI roots in React Error Boundaries. Gracefully display offline warning banners when network connection is lost.

```

-----

### 🧪 Faza 8: Testiranje in kontrola kakovosti (Testing & QA Prompt)

``` 
Act as a Lead QA & Test Automation Engineer. Write automated tests covering unit, integration, and critical user journeys for PetPrep.

### 1. Backend Automated Testing (Pest / PHPUnit)
- Unit Tests (`tests/Unit`):
  * Test metric decay calculations across mutt vs. Border Collie profiles.
  * Test Quiet Hours calculation logic (ensure decay drops by 90% during specified time slots).
  * Test virtual age calculation (`born_at` timestamp -> 1 real week = 1 virtual month).
- Integration Tests (`tests/Feature`):
  * Test PIN generation and child-parent pairing flow (`POST /api/child/pair`).
  * Test metric status API endpoints and database mutations.
  * Test Reverb event broadcasting triggers upon activity log creation.
  * Test RevenueCat webhook handling and database status updates.

### 2. Frontend Component & Hook Testing (Jest + React Native Testing Library)
- Test step counter calculations and hook integration.
- Test metric fill color interpolation (Green -> Yellow -> Red).
- Test hard stop lock overlay rendering when lock state is received.

### 3. E2E Workflow Testing (Detox / Maestro Script Specification)
Create an automated E2E workflow testing script for the critical path:
1. Launch Parent App -> Tap "Add Child" -> Record 6-digit PIN.
2. Launch Child App -> Enter 6-digit PIN -> Complete Sign-on Contract -> Initialize Pet.
3. Simulate Step Input -> Verify step count update on Child UI -> Verify real-time update on Parent Dashboard via WebSocket.
4. Trigger Hard Stop on Parent App -> Verify Child App immediately locks UI.

```

-----

### 📝 Faza 9: Dokumentiranje in sistem predaje dela (Handoff & README Prompt)

```` 
Act as a Technical Documentation Specialist and Engineering Lead. Establish a continuous documentation system to guarantee seamless handoff between developers or AI agents.

### 1. Mandatory `HANDOFF.md` File (Must be updated after EVERY implementation task)
Maintain a root-level `HANDOFF.md` file using the exact structure below:

```markdown
# PetPrep MVP - Project Handoff & State Directory

## 1. Executive Summary & Current Status
- Current Phase: [e.g., Phase 2 - Game Loop & Decay Logic]
- Overall Progress: [e.g., 45% Complete]
- Last Updated: [Timestamp]

## 2. Implemented Features & Architecture Log
- [x] Database Migrations (`users`, `pets`, `activities_log`, `breed_configs`)
- [x] Parent PIN Generation & Child Pairing API
- [ ] Reverb WebSocket Broadcasting (In Progress)

## 3. Active Technical Debt & Known Bugs
- [Bug #01]: WebSocket reconnects slowly on Android emulator when waking from sleep.
- [Tech Debt]: Step count rate-limiter logic currently hardcoded in `WalkController.php`; needs extraction to `AntiCheatService.php`.

## 4. Environment & Configuration Requirements
- Required `.env` variables added: `REVERB_APP_KEY`, `REVENUECAT_SECRET_KEY`.
- Pending integrations: Apple HealthKit production entitlement certificate.

## 5. Next Immediate Steps (Priority Queue for Next Agent/Developer)
1. Complete Laravel Reverb WebSocket broadcast event listener in React Native.
2. Implement Expo local notification triggers for Phase 1 & 2 metric alerts.
3. Write Pest feature test for `POST /api/child/pair`.

````

### 2\. Master `README.md` Specification

Generate a clean `README.md` providing:

- Step-by-step local development setup instructions (Docker / Laravel Sail setup for PHP/PostgreSQL/Reverb).
- Expo React Native setup instructions (`npx expo start`).
- Commands for executing test suites (`php artisan test`, `npm test`).
- Architecture Decision Records (ADR) section explaining key technical choices (e.g., Why Laravel Reverb over Socket.io, Why NativeWind over StyleSheet).

<!-- end list -->

``` 

---

### 💡 Kako uporabiti te module?

1. **Če razvijate celotno aplikacijo v enem koraku:** Vse faze (od 1 do 9) združite v eno krovno dokumentacijo in jo predajte AI agentu.
2. **Če razvijate postopoma (priporočeno):** 
   - Agentu najprej podajte **Master Prompt + Faza 1 + Faza 6 + Faza 9**.
   - Ob zaključku vsake faze od agenta zahtevajte: *"Update `HANDOFF.md` before moving to the next phase."*
   - Nato mu predajte naslednjo fazo skupaj z obstoječim `HANDOFF.md` fajlom. S tem boste preprečili halucinacije in izgubo tehničnega konteksta.

```



