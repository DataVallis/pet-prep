# PetPrep MVP

An AI pet simulation application that teaches children responsibility before acquiring a real pet.

## Overview

PetPrep uses a **dual-profile model**:
- **Parent Profile** — Analytical dashboard, generates PINs, monitors metrics in real time, manages quiet hours, purchases breeds, enforces hard stops.
- **Child Profile** — Video HUD interface, performs daily pet maintenance tasks (feeding, watering, cleaning, walking), tracks step counts, receives escalation alerts.

## Tech Stack
- **Frontend:** React Native (Expo SDK) + NativeWind (Tailwind CSS)
- **Backend:** Laravel 11 API + Laravel Filament (admin)
- **Database:** PostgreSQL
- **Real-time:** Laravel Reverb WebSockets
- **IAP:** RevenueCat SDK
- **Health Tracking:** Apple HealthKit & Google Fit

## Project Structure
```
PetPrep/
├── backend/        # Laravel 11 API (PHP 8.3, PostgreSQL, Reverb, Filament, Scramble)
├── mobile/         # React Native / Expo SDK 57 app (NativeWind, TanStack Query, Zustand)
├── scripts/        # TypeScript SDK generation script (openapi-typescript)
├── HANDOFF.md      # Continuous handoff & state directory (all 9 phases documented)
├── ACCESS.md       # Credentials, URLs, webhook testing, database commands
├── README.md       # This file
├── package.json    # Root monorepo scripts (generate-api-types, dev, test)
└── prompt.md       # Full project specification (9 phases)
```

## Local Development Setup

### Prerequisites
- PHP 8.3+
- Composer 2.x
- PostgreSQL 15+
- Node.js 20+ and npm
- Expo CLI (for mobile app)

### 1. Backend Setup (Laravel API)

```bash
# Start PostgreSQL (macOS Homebrew)
brew services start postgresql@15

# Create the database
createdb petprep

# Navigate to backend
cd backend

# Install dependencies
composer install

# Configure environment
cp .env.example .env
php artisan key:generate

# Edit .env to use PostgreSQL:
#   DB_CONNECTION=pgsql
#   DB_HOST=127.0.0.1
#   DB_PORT=5432
#   DB_DATABASE=petprep
#   DB_USERNAME=<your_pg_user>
#   DB_PASSWORD=<your_pg_password>
#   BROADCAST_CONNECTION=reverb

# Run migrations and seeders
php artisan migrate --seed

# Start the API server
php artisan serve

# Start the queue worker (for async push notifications)
php artisan queue:work

# Start the Reverb WebSocket server
php artisan reverb:start
```

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

### 3. Generate TypeScript API Types

```bash
# From the workspace root — requires backend running on localhost:8000
cd backend && php artisan serve &
npm run generate-api-types

# This fetches openapi.json and generates mobile/src/api/schema.ts
```

## Running Tests

### Backend (Pest — 82 tests)
```bash
cd backend
php artisan test              # run all tests
php artisan test --parallel   # run tests in parallel
```

### Frontend (Jest — 72 tests)
```bash
cd mobile
npm test                      # run all tests
npm run test:watch            # watch mode
```

### Full Suite (154 tests total)
```bash
# From workspace root
npm run test:backend          # backend Pest tests
cd mobile && npm test         # mobile Jest tests
```

## Architecture Decision Records (ADR)

### ADR-001: Laravel Reverb over Socket.io
**Status:** Accepted
**Context:** PetPrep needs real-time synchronization between the child's app and the parent dashboard with <500ms latency.
**Decision:** Use Laravel Reverb (first-party WebSocket server) instead of Socket.io.
**Consequences:** Tighter Laravel integration, shared auth via Sanctum, no need for a separate Node.js process. Reverb is purpose-built for Laravel broadcasting.

### ADR-002: NativeWind over StyleSheet
**Status:** Accepted
**Context:** The child app uses complex glassmorphism HUD overlays and dynamic color states (traffic-light system).
**Decision:** Use NativeWind (Tailwind CSS for React Native) instead of raw StyleSheet.
**Consequences:** Faster styling iteration, consistent design tokens, utility-first classes map directly to the design system specified in Phase 5.

### ADR-003: Service Layer + Form Requests
**Status:** Accepted
**Context:** The app has complex business logic (decay calculations, pairing transactions, escalation matrices).
**Decision:** Controllers stay thin; all business logic lives in dedicated Service classes (`PairingService`, `PetDecayService`); validation in Form Request classes.
**Consequences:** Improved testability, separation of concerns, prevents controller bloat.

### ADR-004: Native PHP 8.3 Enums
**Status:** Accepted
**Context:** Multiple enum-like fields (user roles, breed types, activity types, pet states).
**Decision:** Use PHP 8.3 backed enums stored as strings in PostgreSQL with DB check constraints.
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

## Documentation
- `prompt.md` — Full 9-phase project specification
- `HANDOFF.md` — Current project state, progress log, and next steps (all phases complete, 100%)
- `ACCESS.md` — URLs, credentials, database commands, webhook testing procedures
