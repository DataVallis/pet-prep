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
  Parent->>API: POST /api/parent/generate-pin
  API->>DB: store 6-digit PIN (15 min)
  API-->>Parent: PIN
  Note over Parent,Child: parent tells the child the PIN<br/>(no parent "add child" screen yet — M2-02)
  Child->>API: POST /api/child/pair {pin}
  API->>DB: transaction: lock parent, link child, create pet (Pet DNA), consume PIN
  API-->>Child: 201 pet (media_status=pending|disabled)
  API->>Q: GeneratePetReferenceImage (after commit)
  Q->>FAL: fal.run Flux (seed + prompt anchor)
  FAL-->>Q: image URL (*.fal.media)
  Q->>DB: pet_dna.reference_image_url, media_status=ready
  Q-->>Child: PetUpdated "reference_image_ready" (Reverb)
```

## 3. Game loop tick (every minute)

```mermaid
flowchart TD
  T([Scheduler tick]) --> IDS[Load active pet IDs]
  IDS --> L{{For each pet: transaction + row lock}}
  L --> F{Frozen?<br/>hard stop · ill · game over}
  F -- yes --> FC["Advance last_decay_at<br/>(no catch-up, neglect clocks paused)"]
  F -- no --> SEG["Split elapsed time since last_decay_at<br/>into normal / quiet segments<br/>(family timezone, DST-aware)"]
  SEG --> D["Decay from breed_configs:<br/>hunger 8|12 %/h · thirst 10|15 %/h<br/>quiet hours ×0.10"]
  D --> MN{"New family-local day?"}
  MN -- yes --> RS["Steps → 0, energy → 0 %<br/>(no time decay otherwise)"]
  MN -- no --> HS
  RS --> HS["Schedule today's hygiene events<br/>(poops_per_day, outside quiet hours)"]
  HS --> HA{"Pending event in<br/>(last_decay_at, now]?"}
  HA -- yes --> H0["Hygiene → 0 %<br/>zero_since = event time"]
  HA -- "no / skipped" --> Z
  H0 --> Z["Zero tracking & pet_state<br/>on displayed (rounded) values"]
  Z --> W{Displayed value changed?}
  W -- yes --> B[Save + PetUpdated broadcast after commit]
  W -- no --> QS[Save quietly]
  B --> E
  QS --> E
  FC --> E[Escalation service]
```

## 4. Escalation & neglect

```mermaid
stateDiagram-v2
  [*] --> OK
  OK --> Phase1: displayed metric ≤ 30 %
  Phase1 --> Phase2: ≤ 10 %
  Phase2 --> Phase3: any metric 0 % for > 1 h<br/>(parent alarm)
  Phase1 --> OK: all ≥ 31 %
  Phase2 --> OK: all ≥ 31 %
  Phase3 --> Ill: hygiene or energy 0 % ≥ 6 h<br/>(counted outside quiet hours only)
  Ill --> OK: after 12 h lockout
  Phase3 --> GameOver: any metric 0 % ≥ 24 h
  GameOver --> [*]: parent reset (M2-07)
  note right of Ill: frozen — no decay, no hygiene events,<br/>neglect clocks paused, actions refused.<br/>(D) on thaw the clock still shows ≥ 6 h<br/>→ ill again (DECISIONS.md)
  state HardStop
  OK --> HardStop: parent hard stop
  HardStop --> OK: parent lifts (clocks resume)
```

## 5. Child actions: steps and cleaning (service layer built, endpoints M1-07)

```mermaid
sequenceDiagram
  autonumber
  actor Child
  participant App as Child app
  participant API as Child API (M1-07, planned)
  participant S as PetActivityService
  participant DB as PostgreSQL
  Child->>App: walks with the phone
  App-->>API: POST steps {steps_today, recorded_at} (planned)
  API->>S: recordSteps(pet, steps_today, recorded_at)
  S->>DB: BEGIN · SELECT pet FOR UPDATE
  alt hard stop / ill / game over
    S-->>API: LOCKED (→ 423)
  else
    S->>S: midnight reset if new local day
    S->>S: increment = steps_today − stored (≤ 0 → UNCHANGED)<br/>cap at 200/min since last sync or local midnight
    S->>DB: steps, energy = min(100, steps/goal), walked_pet row
    S-->>API: ACCEPTED / CAPPED / REJECTED / STALE
  end
  S-->>App: PetUpdated "walked_pet" after commit (Reverb)
  Note over Child,DB: Cleaning: clean(pet) settles due hygiene events,<br/>hygiene → 100 %, cleaned_poop row, PetUpdated "cleaned_poop"
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
