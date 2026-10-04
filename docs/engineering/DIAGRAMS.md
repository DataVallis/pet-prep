# PetPrep — Diagrams (Mermaid)

Living diagrams of the system **as built**. Update in the same PR as the code. GitHub renders Mermaid natively; export to PNG/SVG for decks with `npx @mermaid-js/mermaid-cli -i DIAGRAMS.md`.

Legend: solid = built and deployed · dashed = planned (roadmap ID in label).

## 1. System context

```mermaid
flowchart LR
  subgraph Phones
    P["Parent app<br/>(Expo, dashboard)"]
    C["Child app<br/>(Expo, video HUD)"]
  end
  subgraph Hetzner["Hetzner CX23 · api.petprep.si"]
    CAD[Caddy TLS]
    APP["Laravel API<br/>+ Filament admin"]
    REV[Reverb WebSockets]
    Q[Queue worker]
    S["Scheduler<br/>pets:process-decay every minute"]
    PG[(PostgreSQL 18)]
    R[(Redis)]
  end
  FAL["fal.ai<br/>Flux image · Kling video"]
  RC[RevenueCat]
  HK["HealthKit / Health Connect"]
  P -- HTTPS --> CAD
  C -- HTTPS --> CAD
  P -. wss .- CAD
  C -. wss .- CAD
  CAD --> APP
  CAD --> REV
  APP --> PG
  APP --> R
  Q --> PG
  S --> PG
  Q -- "reference image (job)" --> FAL
  APP -- "video job" --> FAL
  FAL -- "signed webhook (ED25519)" --> APP
  RC -. "webhook (M3-08)" .-> APP
  C -. "steps (M3-04/05)" .- HK
```

## 2. Pairing (parent ↔ child)

```mermaid
sequenceDiagram
  autonumber
  actor Parent
  actor Child
  participant API as Laravel API
  participant DB as PostgreSQL
  participant Q as Queue
  participant FAL as fal.ai
  Note over Parent: "Dodaj otroka" card (dashboard, or Controls)<br/>shown only while no child is paired
  Parent->>API: POST /api/parent/generate-pin (5/min)
  API->>DB: store 6-digit PIN (15 min, replaces the previous one)
  API-->>Parent: PIN → shown as "734 912" + live countdown + "Nova koda"
  Note over Parent,Child: parent tells the child the PIN<br/>(child still signs in with e-mail first — PIN-only login is M2-02)
  loop every 5 s while the PIN is valid
    Parent->>API: GET /api/parent/dashboard
  end
  Child->>API: POST /api/child/pair {pin}
  API->>DB: transaction: lock parent, link child,<br/>create UNBORN pet (Pet DNA, born_at null), consume PIN
  API-->>Child: 201 pet (born_at null, awaiting_contract, media_status=pending|disabled)
  API->>Q: GeneratePetReferenceImage (after commit)
  Q->>FAL: fal.run Flux (seed + prompt anchor)
  FAL-->>Q: image URL (*.fal.media)
  Q->>DB: pet_dna.reference_image_url, media_status=ready
  Q-->>Child: PetUpdated "reference_image_ready" (Reverb)
  Parent->>API: GET /api/parent/dashboard → pet ≠ null, awaiting_contract
  Note over Parent: "Otrok je povezan!" → GET /api/user refreshes the session pet
  Note over Child,DB: unborn: no decay, hygiene events, day close or escalation;<br/>every child action except the contract → 423 contract_required
  Child->>API: POST /api/child/contract {signature} (Pogodba o odgovornosti)
  API->>DB: transaction: SELECT pet FOR UPDATE, pet_contracts row,<br/>birth: born_at = last_decay_at = last_step_reset_at = now,<br/>metrics 100 %, signed_contract row
  API-->>Child: 201 {status: accepted, state (unlocked)}
  API-->>Parent: PetUpdated "signed_contract" after commit (awaiting_contract false)
  Note over Child,DB: the dog is born — game loop runs from the signing moment (M1-07b)
```

## 2b. Mobile app launch — session restore (M1-12)

```mermaid
flowchart TD
  L[App start] --> S["Splash 'Nalagam …'<br/>bootStatus = restoring"]
  S --> T{Token in SecureStore?}
  T -- no --> LOGIN[PairingScreen — login]
  T -- yes --> U[GET /api/user]
  U -- 200 --> R{role}
  R -- parent --> PD[ParentDashboardScreen]
  R -- "child + pet" --> HUD["ChildHudScreen<br/>(+ LockedScreen if game over / ill)"]
  R -- "child, no pet" --> PIN[PairingScreen — PIN step]
  U -- 401 --> CLR[delete token] --> LOGIN
  U -- "network / 5xx" --> OFF["Splash 'Ni povezave'<br/>token kept"]
  OFF -- "Poskusi znova" --> U
  OFF -- Odjava --> OUT
  PD & HUD & PIN -- "Odjava / any later 401" --> OUT["logout(): POST /api/logout (best effort)<br/>→ delete token → clear query cache → reset store"]
  OUT --> LOGIN
```

## 3. Game loop tick (every minute)

```mermaid
flowchart TD
  T([Scheduler tick]) --> IDS["Load IDs of active, born pets<br/>(unborn = born_at null are skipped, M1-07b)"]
  IDS --> L{{For each pet: transaction + row lock}}
  L --> RC{"Illness over?<br/>(illness_until ≤ now)"}
  RC -- yes --> REC["Fresh start at illness_until:<br/>hygiene 100 %, neglect clocks restart,<br/>escalation 0"]
  RC -- no --> F
  REC --> F{Frozen?<br/>hard stop · ill · game over}
  F -- yes --> FC["Advance last_decay_at<br/>(no catch-up, neglect clocks paused)<br/>midnight: close day, no walk illness"]
  F -- no --> SEG["Split elapsed time since last_decay_at<br/>into normal / quiet segments<br/>(family timezone, DST-aware)"]
  SEG --> D["Decay from breed_configs:<br/>hunger 8|12 %/h · thirst 10|15 %/h<br/>quiet hours ×0.10"]
  D --> MN{"New family-local day?"}
  MN -- yes --> RS["Close yesterday: pet_daily_walks row<br/>energy showed 0 % → walk_illness_due_at<br/>= end of the night's quiet hours<br/>Steps → 0, energy → 0 %"]
  MN -- no --> HS
  RS --> HS["Schedule today's hygiene events<br/>(poops_per_day, outside quiet hours)"]
  HS --> HA{"Pending event in<br/>(last_decay_at, now]?"}
  HA -- yes --> H0["Hygiene → 0 %<br/>zero_since = event time"]
  HA -- "no / skipped" --> Z
  H0 --> Z
  Z["Zero tracking (hunger · thirst · hygiene — not energy)<br/>& pet_state on displayed (rounded) values"] --> W{Displayed value changed?}
  W -- yes --> B[Save + PetUpdated broadcast after commit]
  W -- no --> QS[Save quietly]
  B --> E
  QS --> E
  FC --> E[Escalation service]
```

## 4. Escalation & neglect

```mermaid
stateDiagram-v2
  [*] --> Unborn: pairing (PIN)
  Unborn --> OK: contract signed = birth<br/>(born_at = now, metrics 100 %)
  note left of Unborn: waiting for the contract —<br/>no decay, no hygiene events,<br/>no day close, no escalation;<br/>actions 423 contract_required
  OK --> Phase1: displayed metric ≤ 30 %<br/>(energy only outside quiet hours)
  Phase1 --> Phase2: ≤ 10 %
  Phase2 --> Phase3: hunger / thirst / hygiene 0 % for > 1 h<br/>(parent alarm — never from energy)
  Phase1 --> OK: all ≥ 31 %
  Phase2 --> OK: all ≥ 31 %
  Phase3 --> Ill: hygiene 0 % ≥ 6 h<br/>(counted outside quiet hours only)
  OK --> Ill: no walk yesterday (energy showed 0 % at midnight)<br/>→ ill from the end of the night's quiet hours
  Ill --> OK: after 12 h lockout<br/>hygiene 100 %, clocks reset (fresh start)
  Phase3 --> GameOver: hunger / thirst / hygiene 0 % ≥ 24 h
  GameOver --> [*]: parent reset (M2-07)
  note right of Ill: frozen — no decay, no hygiene events,<br/>neglect clocks paused, actions refused.<br/>Recovery: hygiene 100 %, every running<br/>neglect clock restarts at recovery,<br/>hunger / thirst unchanged (David 2026-10-03)
  state HardStop
  OK --> HardStop: parent hard stop
  HardStop --> OK: parent lifts (clocks resume)
```

### Daily walk rule (energy, David 2026-10-03)

```mermaid
flowchart LR
  M([Family-local midnight<br/>tick or first step sync]) --> R["pet_daily_walks row<br/>steps · goal · achieved"]
  R --> Q{"Yesterday's energy<br/>showed 0 %?"}
  Q -- "no, goal reached" --> OKD[Walk done]
  Q -- "no, below goal" --> MISS[Missed goal — recorded only]
  Q -- yes --> X{"Birth day · frozen at midnight ·<br/>more than one midnight passed?"}
  X -- yes --> NONE[No illness]
  X -- no --> DUE["walk_illness_due_at =<br/>end of the night's quiet hours<br/>(e.g. 06:00)"]
  DUE --> FZ{"Frozen when due?"}
  FZ -- yes --> DROP[Dropped]
  FZ -- no --> ILL["Illness 12 h<br/>(same mechanism as hygiene)"]
  R --> RST["Steps → 0, energy → 0 %<br/>(= today's walk not done yet, not neglect)"]
```

## 5. Child actions (M1-07: `/api/child/pet/*`, `/api/child/contract`)

### 5a. Feed / water / clean / contract

```mermaid
sequenceDiagram
  autonumber
  actor Child
  participant App as Child app
  participant API as ChildPetController
  participant S as PetActivityService
  participant C as CareScheduleService
  participant DB as PostgreSQL
  Child->>App: taps Feed (or Water / Clean / signs contract)
  App->>API: POST /api/child/pet/feed (Bearer, child token)
  API->>API: ChildPetRequest → PetPolicy (child only, else 403)<br/>currentPet() (none → 404 no_pet)
  API->>S: feed(pet)
  S->>DB: BEGIN · SELECT pet FOR UPDATE
  S->>S: illness over? → fresh start (recoverFromIllnessIfDue)
  alt game over / inactive / hard stop / contract required (unborn) / ill
    S-->>API: LOCKED + reason → 423 {reason, locked_until, state}
  else
    S->>S: catch up decay since last tick (PetDecayService::catchUpLocked)
    alt hygiene shows 0 %
      S-->>API: REFUSED needs_cleaning → 422
    else
      S->>C: feeding(pet, breed, now) — windows in family tz,<br/>last fed_pet row in activities_log
      alt outside window / already fed in this window
        S-->>API: REFUSED + next window start → 422 {reason, next_allowed_at}
      else
        S->>DB: hunger 100 %, hunger_zero_since null, pet_state<br/>fed_pet row (value = hunger before)
        S-->>API: ACCEPTED → 200 {status, state}
      end
    end
  end
  S->>DB: COMMIT
  S-->>App: one PetUpdated after commit ("fed_pet",<br/>or "metric_changed" if only the catch-up changed what shows)
  Note over S,C: Water: same flow, CareScheduleService::water —<br/>water_times_per_day per local day, ≥ water_min_gap_minutes real minutes<br/>since the last refill (also across midnight) → thirst 100 %, watered_pet row
  Note over S,DB: Clean: settles due hygiene events, hygiene 100 %, cleaned_poop row (no window).<br/>Contract: allowed while contract_required; once per pet → pet_contracts row + signed_contract row → 201<br/>(unborn pet: birth in the same transaction, M1-07b); again → 409
```

### 5b. Step sync

```mermaid
sequenceDiagram
  autonumber
  actor Child
  participant App as Child app
  participant API as ChildPetController
  participant S as PetActivityService
  participant DB as PostgreSQL
  Child->>App: walks with the phone
  App->>API: POST /api/child/pet/steps {steps_today, source, recorded_at}
  API->>S: recordSteps(pet, steps_today, recorded_at)
  S->>DB: BEGIN · SELECT pet FOR UPDATE
  alt hard stop / ill / game over
    S-->>API: LOCKED (→ 423)
  else
    S->>S: new local day → close yesterday (walk row,<br/>maybe walk illness), steps → 0
    S->>S: increment = steps_today − stored (≤ 0 → UNCHANGED)<br/>cap at 200/min since last sync or local midnight
    S->>DB: steps, energy = min(100, steps/goal)<br/>walked_pet row only when the goal is first reached
    S-->>API: ACCEPTED / CAPPED / REJECTED / STALE
  end
  API-->>App: 200 {status, accepted_steps, steps_today, energy_level, state} (423 when locked)
  S-->>App: PetUpdated "walked_pet" after commit (Reverb)
```

## 6. Data model (core)

```mermaid
erDiagram
  USERS ||--o{ USERS : "parent_id (children)"
  USERS ||--o{ PETS : owns
  USERS ||--o| QUIET_HOURS : "parent sets"
  PETS ||--o{ ACTIVITIES_LOG : logs
  PETS ||--o{ PET_MEDIA_JOBS : "fal.ai requests"
  PETS ||--o{ PET_HYGIENE_EVENTS : "random messes"
  PETS ||--o{ PET_DAILY_WALKS : "closed days"
  PETS ||--o| PET_CONTRACTS : "signed once"
  BREED_CONFIGS ||--o{ PETS : "tunables by breed_slug"
  USERS {
    bigint id
    string role "parent or child"
    bigint parent_id
    string timezone
    string pairing_pin
  }
  PETS {
    bigint id
    string breed_type
    double hunger_level
    double thirst_level
    double energy_level
    double hygiene_level
    int daily_step_count
    timestamp last_step_reset_at
    timestamp last_step_sync_at
    timestamp last_decay_at
    timestamp frozen_at
    date hygiene_scheduled_through
    timestamp walk_illness_due_at
    timestamp born_at "null = unborn (M1-07b)"
    string media_status
    jsonb pet_dna
    bool is_hard_stopped
    bool is_game_over
  }
  BREED_CONFIGS {
    string breed_slug
    int daily_steps_required
    float hunger_decay_rate
    float thirst_decay_rate
    smallint poops_per_day
    jsonb feed_windows
    smallint water_times_per_day
    smallint water_min_gap_minutes
  }
  PET_HYGIENE_EVENTS {
    date local_date
    timestamp scheduled_at
    string status "pending applied skipped"
    timestamp cleaned_at
  }
  PET_DAILY_WALKS {
    date local_date
    int steps
    int goal
    bool achieved
    bool birth_day
    timestamp illness_due_at
    timestamp illness_started_at
  }
  PET_CONTRACTS {
    bigint pet_id "unique"
    bigint user_id "the child"
    string signature_format "svg_path or png"
    text signature
    timestamp signed_at "server time"
  }
  PET_MEDIA_JOBS {
    string request_id
    string status
    string pet_state
    text result_url
  }
```

## 7. Delivery pipeline

```mermaid
flowchart LR
  BR[Feature branch] --> PR[Pull request]
  PR --> CI1["Backend tests<br/>Pest on PostgreSQL"]
  PR --> CI2["Mobile checks<br/>tsc + Jest"]
  CI1 & CI2 --> REV["Independent AI review<br/>(qa-reviewer)"]
  REV --> M[Merge to main]
  M --> DEP["Deploy job<br/>rsync → backup DB → migrate → restart"]
  DEP --> PROD[(api.petprep.si)]
```
