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
  SEG --> D["Decay: hunger 8|12 %/h · thirst 10|15 %/h<br/>quiet hours ×0.10 · hygiene 1.5 %/h (interim)"]
  D --> Z["Zero tracking & pet_state<br/>on displayed (rounded) values"]
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
  Phase3 --> Ill: hygiene or energy 0 % ≥ 6 h<br/>(outside quiet hours)
  Ill --> OK: after 12 h lockout
  Phase3 --> GameOver: any metric 0 % ≥ 24 h
  GameOver --> [*]: parent reset (M2-07)
  note right of Ill: frozen — no decay,<br/>neglect clocks paused
  state HardStop
  OK --> HardStop: parent hard stop
  HardStop --> OK: parent lifts (clocks resume)
```

## 5. Data model (core)

```mermaid
erDiagram
  USERS ||--o{ USERS : "parent_id (children)"
  USERS ||--o{ PETS : owns
  USERS ||--o| QUIET_HOURS : "parent sets"
  PETS ||--o{ ACTIVITIES_LOG : logs
  PETS ||--o{ PET_MEDIA_JOBS : "fal.ai requests"
  BREED_CONFIGS ||--o{ PETS : "rates by breed_slug"
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
    timestamp last_decay_at
    timestamp frozen_at
    string media_status
    jsonb pet_dna
    bool is_hard_stopped
    bool is_game_over
  }
  PET_MEDIA_JOBS {
    string request_id
    string status
    string pet_state
    text result_url
  }
```

## 6. Delivery pipeline

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
