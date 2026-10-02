---
description: Run every quality gate for backend and mobile and report results
---

Run all PetPrep quality gates and report a pass/fail table with the exact failing output.

Backend (needs Docker running; start with `npm run sail:up` if containers are down):
- `cd backend && ./vendor/bin/sail test`
- `cd backend && ./vendor/bin/sail pint --test`

Mobile:
- `cd mobile && npx tsc --noEmit`
- `cd mobile && yarn test --ci`

Contract:
- If any file under `backend/routes` or `backend/app/Http` changed vs `main`, run `npm run generate-api-types` and report whether `mobile/src/api/schema.ts` changed (uncommitted drift = fail).

If a gate can't run (e.g. Docker not available), say so explicitly — never report it as passed.
