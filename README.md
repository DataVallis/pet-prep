# PetPrep MVP

An AI pet simulation application that teaches children responsibility before acquiring a real pet.

## Overview

PetPrep uses a **dual-profile model**:
- **Parent Profile** — Analytical dashboard, generates PINs, monitors metrics in real time, manages quiet hours, purchases breeds, enforces hard stops.
- **Child Profile** — Video HUD interface, performs daily pet maintenance tasks (feeding, watering, cleaning, walking), tracks step counts, receives escalation alerts.

## Tech Stack
- **Frontend:** React Native (Expo SDK 57), styled with React Native `StyleSheet` (NativeWind removed 2026-10-05, see ADR-002)
- **Backend:** Laravel 11 API (PHP 8.5) + Laravel Filament (admin)
- **Database:** PostgreSQL 18
- **Real-time:** Laravel Reverb WebSockets
- **IAP:** RevenueCat SDK
- **Health Tracking:** Apple HealthKit & Google Fit
- **Dev Environment:** Laravel Sail (Docker)

## Project Structure
```
PetPrep/
├── backend/        # Laravel 11 API (PHP 8.5, PostgreSQL, Reverb, Filament, Scramble)
│   ├── compose.yaml    # Laravel Sail Docker Compose config
│   ├── sail            # Sail launcher script
│   └── docker/         # Published Sail Dockerfiles (PHP 8.0–8.5, pgsql, mysql, mariadb)
├── mobile/         # React Native / Expo SDK 57 app (StyleSheet, TanStack Query, Zustand)
├── scripts/        # TypeScript SDK generation script (openapi-typescript)
├── HANDOFF.md      # Continuous handoff & state directory (all 9 phases documented)
├── ACCESS.md       # Credentials, URLs, webhook testing, database commands
├── README.md       # This file
├── package.json    # Root monorepo scripts (sail:*, dev:*, test:*, generate-api-types)
└── prompt.md       # Full project specification (9 phases)
```

## Local Development Setup

### Prerequisites
- **Docker Desktop** (macOS / Windows / Linux) — must be running
- Node.js 20+ and npm
- Expo CLI (for mobile app)

> **No need to install PHP, Composer, or PostgreSQL locally** — Laravel Sail runs everything inside Docker containers.

### 1. Backend Setup (Laravel API via Sail)

```bash
# Navigate to backend
cd backend

# Install PHP dependencies (only needed once, requires local PHP/Composer OR use Sail)
composer install

# Configure environment
cp .env.example .env
php artisan key:generate

# ── Start all Docker containers (PostgreSQL, Redis, Selenium, Mailpit, Laravel app) ──
# First run: builds the sail-8.5/app image (takes a few minutes)
./vendor/bin/sail up -d

# Run database migrations + seeders (inside the container)
./vendor/bin/sail artisan migrate:fresh --seed

# Start the Reverb WebSocket server (keep running in its own terminal)
./vendor/bin/sail artisan reverb:start

# (Optional) Start the queue worker for async push notifications
./vendor/bin/sail artisan queue:work
```

**Or use the convenience scripts from the workspace root:**
```bash
npm run sail:up        # cd backend && ./vendor/bin/sail up -d
npm run sail:migrate   # cd backend && ./vendor/bin/sail artisan migrate:fresh --seed
npm run sail:reverb    # cd backend && ./vendor/bin/sail artisan reverb:start
npm run sail:queue     # cd backend && ./vendor/bin/sail artisan queue:work
npm run sail:down      # cd backend && ./vendor/bin/sail down
npm run sail:logs      # cd backend && ./vendor/bin/sail logs
npm run sail:shell     # cd backend && ./vendor/bin/sail shell
npm run sail:tinker    # cd backend && ./vendor/bin/sail artisan tinker
npm run sail:test      # cd backend && ./vendor/bin/sail artisan test
```

#### Services exposed by Sail

| Service | URL | Notes |
|---------|-----|-------|
| **Laravel API** | `http://localhost:8000` | `APP_PORT=8000` in `.env` |
| **Reverb WebSocket** | `ws://localhost:8080` | Run `sail artisan reverb:start` |
| **PostgreSQL** | `localhost:5432` | User: `sail`, Password: `password`, DB: `petprep` |
| **Redis** | `localhost:6379` | |
| **Mailpit dashboard** | `http://localhost:8025` | Local email testing |
| **Filament Admin** | `http://localhost:8000/admin` | `admin@petprep.io` / `Password123!` |
| **OpenAPI / Swagger docs** | `http://localhost:8000/docs/api` | Interactive API documentation |

#### Without Docker (alternative — requires local PHP + PostgreSQL)

If you prefer to run the backend without Sail/Docker, you still can:

```bash
cd backend
php artisan serve          # API on http://localhost:8000
php artisan reverb:start   # WebSocket on ws://localhost:8080
php artisan queue:work     # Queue worker
```

Make sure your local PostgreSQL is running and `.env` points to `DB_HOST=127.0.0.1`.

### 2. Frontend Setup (React Native / Expo)

```bash
# Navigate to mobile
cd mobile

# Install dependencies (if not already done)
npm install --legacy-peer-deps

# Start the Expo dev server
npx expo start

# Run on iOS simulator
npx expo start --ios

# Run on Android emulator
npx expo start --android
```

The mobile app connects to:
- **API:** `http://localhost:8000` (`EXPO_PUBLIC_API_URL`)
- **Reverb WebSocket:** `localhost:8080` (`EXPO_PUBLIC_REVERB_HOST` / `EXPO_PUBLIC_REVERB_PORT`)

### 3. Generate TypeScript API Types

```bash
# Requires the backend to be running (Sail or local)
# From the workspace root:
npm run sail:up                # make sure backend is up
npm run generate-api-types     # fetches openapi.json → generates mobile/src/api/schema.ts
```

## Running Tests

### Backend (Pest — 82 tests)
```bash
# Via Sail (recommended)
cd backend
./vendor/bin/sail artisan test
# or from workspace root:
npm run sail:test

# Without Sail (requires local PHP + PostgreSQL)
cd backend
php artisan test
```

### Frontend (Jest — 72 tests)
```bash
cd mobile
npm test                      # run all tests
npm run test:watch            # watch mode
# or from workspace root:
npm run test:mobile
```

### Full Suite (154 tests total)
```bash
# From workspace root
npm run sail:test             # backend Pest tests (via Sail)
npm run test:mobile           # mobile Jest tests
```

## Architecture Decision Records (ADR)

### ADR-001: Laravel Reverb over Socket.io
**Status:** Accepted
**Context:** PetPrep needs real-time synchronization between the child's app and the parent dashboard with <500ms latency.
**Decision:** Use Laravel Reverb (first-party WebSocket server) instead of Socket.io.
**Consequences:** Tighter Laravel integration, shared auth via Sanctum, no need for a separate Node.js process. Reverb is purpose-built for Laravel broadcasting.

### ADR-002: NativeWind over StyleSheet
**Status:** Superseded (2026-10-05) — NativeWind `className` styles were silently dropped in the production (TestFlight) build because babel-preset-expo (SDK 57) adds the `react-native-worklets` Babel plugin automatically, which conflicts with NativeWind's `jsxImportSource`. Only 4 files still used it; they were converted to `StyleSheet` and NativeWind / Tailwind removed. The app is styled with `StyleSheet` only (guard: `mobile/src/__tests__/noClassName.test.ts`). See `docs/product/DECISIONS.md`.
**Context:** The child app uses complex glassmorphism HUD overlays and dynamic color states (traffic-light system).
**Decision:** Use NativeWind (Tailwind CSS for React Native) instead of raw StyleSheet.
**Consequences:** Faster styling iteration, consistent design tokens, utility-first classes map directly to the design system specified in Phase 5.

### ADR-003: Service Layer + Form Requests
**Status:** Accepted
**Context:** The app has complex business logic (decay calculations, pairing transactions, escalation matrices).
**Decision:** Controllers stay thin; all business logic lives in dedicated Service classes (`PairingService`, `PetDecayService`); validation in Form Request classes.
**Consequences:** Improved testability, separation of concerns, prevents controller bloat.

### ADR-004: Native PHP 8.5 Enums
**Status:** Accepted
**Context:** Multiple enum-like fields (user roles, breed types, activity types, pet states).
**Decision:** Use PHP 8.5 backed enums stored as strings in PostgreSQL with DB check constraints.
**Consequences:** Type safety in PHP, self-documenting code, IDE autocompletion.

### ADR-005: Pet DNA Architecture (fal.ai)
**Status:** Accepted
**Context:** Every pet must be 100% visually consistent across all AI-generated images and videos.
**Decision:** Store a JSONB `pet_dna` payload (seed, visual_traits, prompt_anchor, reference_image_url) on each pet. Use the reference image as Image-to-Video anchor for Kling 3.0.
**Consequences:** Reproducible generation, visual consistency, graceful degradation when API key not set.

### ADR-006: Elapsed-Time Decay (not Fixed Per-Tick)
**Status:** Accepted
**Context:** Minutely cron job may miss ticks; fixed per-tick decay causes drift.
**Decision:** `PetDecayService` calculates decay based on elapsed time since last `updated_at`, not a fixed per-minute amount.
**Consequences:** Resilient to missed cron ticks, self-correcting decay rates.

### ADR-007: Dual-Theme Design System
**Status:** Accepted
**Context:** Child interface needs immersive glassmorphism; parent dashboard needs analytical clarity.
**Decision:** Child HUD uses dark glassmorphic theme (`bg-slate-900/40`, `backdrop-blur-lg`); Parent Dashboard uses clean light theme (`bg-slate-50`, `bg-white` cards, `text-slate-900`).
**Consequences:** Clear visual distinction between profiles, optimized for each use case.

### ADR-008: Zustand + TanStack Query
**Status:** Accepted
**Context:** Global UI state and API caching needed for React Native app.
**Decision:** Zustand for global state (auth, WebSocket, pet data, lock state); TanStack Query for API data fetching/caching.
**Consequences:** Minimal boilerplate, good performance, no provider nesting issues.

### ADR-009: Filament Admin via FilamentUser
**Status:** Accepted
**Context:** Admin panel needs strict access control.
**Decision:** `is_superadmin` boolean on User model + `FilamentUser` interface with `canAccessPanel()` method.
**Consequences:** Only superadmin-flagged users can access `/admin`; regular parents/children cannot.

### ADR-010: OpenAPI → TypeScript SDK Pipeline
**Status:** Accepted
**Context:** Mobile app needs type-safe API client matching backend exactly.
**Decision:** `dedoc/scramble` generates OpenAPI 3.1 spec → `openapi-typescript` CLI generates `mobile/src/api/schema.ts` via `npm run generate-api-types`.
**Consequences:** Single source of truth (backend), auto-generated types prevent drift, 812 lines of TypeScript interfaces.

### ADR-011: Laravel Sail for Dev Environment
**Status:** Accepted
**Context:** The backend requires PHP 8.5, PostgreSQL 18, Redis, and a mail testing service. Manually installing and configuring each on developer machines is error-prone and inconsistent.
**Decision:** Use Laravel Sail (Laravel's official Docker-based dev environment) with a `compose.yaml` that runs PostgreSQL (pgsql), Redis, Selenium, and Mailpit. Root `package.json` exposes `sail:*` convenience scripts.
**Consequences:** Zero local dependency installation beyond Docker Desktop; identical environment across all machines; one-command `sail up -d` starts the full stack. Developers can still run without Docker using local PHP + PostgreSQL.

## Documentation
- `prompt.md` — Full 9-phase project specification
- `HANDOFF.md` — Current project state, progress log, and next steps (all phases complete, 100%)
- `ACCESS.md` — URLs, credentials, database commands, webhook testing procedures
