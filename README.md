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
├── backend/        # Laravel 11 API (PHP 8.3)
├── mobile/         # React Native / Expo app (Phase 3 — not yet created)
├── HANDOFF.md      # Continuous handoff & state directory
├── README.md       # This file
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

### 2. Frontend Setup (React Native — Phase 3)

```bash
# Will be initialized during Phase 3
cd mobile
npx expo start
```

## Running Tests

### Backend (Pest)
```bash
cd backend
php artisan test              # run all tests
php artisan test --parallel   # run tests in parallel
composer test                 # alternative invocation
```

### Frontend (Jest — Phase 3+)
```bash
cd mobile
npm test
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
**Context:** Multiple enum-like fields (user roles, breed types, activity types).
**Decision:** Use PHP 8.3 backed enums stored as strings in PostgreSQL.
**Consequences:** Type safety in PHP, self-documenting code, IDE autocompletion.

## Documentation
- `prompt.md` — Full 9-phase project specification
- `HANDOFF.md` — Current project state, progress log, and next steps (updated after every phase)
