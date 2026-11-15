#!/usr/bin/env bash
# CI: regenerate mobile/src/api/schema.ts from the running backend and fail when the
# committed file differs (David 2026-11-15 — the file must never drift).
#
# Expects: backend/vendor installed, a migrated database, root node_modules (openapi-typescript).
# Same path as a developer's `npm run sail:up` + `npm run generate-api-types`, so CI and a local
# run produce the same bytes.
#
# When it fails, the job uploads the fresh file as the artifact `api-schema`. To take it:
#   gh run download <run id> -n api-schema -D /tmp/api-schema
#   cp /tmp/api-schema/schema.ts mobile/src/api/schema.ts
# then run `npx tsc --noEmit` in mobile/ and commit it on a NEW branch (never an open PR's).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
port="${API_TYPES_PORT:-8000}"
log="${RUNNER_TEMP:-/tmp}/api-types-serve.log"

cd "$root/backend"
php artisan serve --host=127.0.0.1 --port="$port" >"$log" 2>&1 &
serve_pid=$!
trap 'kill "$serve_pid" 2>/dev/null || true' EXIT

ready=0
for _ in $(seq 1 60); do
  if curl -fsS -o /dev/null "http://127.0.0.1:$port/docs/api.json"; then ready=1; break; fi
  if ! kill -0 "$serve_pid" 2>/dev/null; then break; fi
  sleep 1
done
if [ "$ready" != 1 ]; then
  echo "::error::The backend did not serve /docs/api.json within 60 s."
  cat "$log" || true
  exit 1
fi

cd "$root"
API_BASE_URL="http://localhost:$port" node scripts/generate-api-types.mjs

if git diff --quiet -- mobile/src/api/schema.ts; then
  echo "mobile/src/api/schema.ts is up to date."
  exit 0
fi

echo "::error file=mobile/src/api/schema.ts::schema.ts is out of date with the backend's OpenAPI spec. The fresh file is the artifact 'api-schema' of this run — download it, check \`npx tsc --noEmit\` in mobile/ and commit it."
git --no-pager diff --stat -- mobile/src/api/schema.ts
{
  echo "### API types out of date"
  echo
  echo "\`mobile/src/api/schema.ts\` differs from what the backend generates. Take the artifact **api-schema** of this run:"
  echo
  echo '```'
  echo "gh run download ${GITHUB_RUN_ID:-<run id>} -n api-schema -D /tmp/api-schema"
  echo "cp /tmp/api-schema/schema.ts mobile/src/api/schema.ts"
  echo '```'
} >>"${GITHUB_STEP_SUMMARY:-/dev/null}"
exit 1
