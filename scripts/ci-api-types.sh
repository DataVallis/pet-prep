#!/usr/bin/env bash
# CI: regenerate mobile/src/api/schema.ts from the backend's OpenAPI spec and fail when the
# committed file differs (David 2026-11-15 — the file must never drift).
#
# Expects: backend/vendor installed, a migrated database (Scramble reads model columns),
# root node_modules (openapi-typescript).
#
# The spec comes from `php artisan scramble:export` (no web server: `php artisan serve` died on
# the first request — Scramble's analysis needs more than the CLI server's memory). The
# generator writes the same header as a local `npm run generate-api-types`, so the bytes match.
#
# When it fails, the fresh file is (1) the artifact `api-schema` of the run and (2) printed
# gzip + base64 between API_SCHEMA_B64_BEGIN / API_SCHEMA_B64_END in the log (for agents whose
# network cannot reach the artifact store). Take it, run `npx tsc --noEmit` in mobile/ and commit
# it on a NEW branch (never an open PR's):
#   gh run download <run id> -n api-schema -D /tmp/api-schema && cp /tmp/api-schema/schema.ts mobile/src/api/schema.ts
#   or: gh api repos/<owner>/<repo>/actions/jobs/<job id>/logs | sed -n '/API_SCHEMA_B64_BEGIN/,/API_SCHEMA_B64_END/p' …
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
spec="${RUNNER_TEMP:-/tmp}/petprep-openapi.json"

cd "$root/backend"
php -d memory_limit=-1 artisan scramble:export --path="$spec" --no-interaction
if [ ! -s "$spec" ]; then
  echo "::error::scramble:export wrote no spec ($spec)."
  exit 1
fi

cd "$root"
API_SPEC_FILE="$spec" node scripts/generate-api-types.mjs

if git diff --quiet -- mobile/src/api/schema.ts; then
  echo "mobile/src/api/schema.ts is up to date."
  exit 0
fi

echo "::error file=mobile/src/api/schema.ts::schema.ts is out of date with the backend's OpenAPI spec. The fresh file is the artifact 'api-schema' of this run (also gzip+base64 in this log) — take it, check \`npx tsc --noEmit\` in mobile/ and commit it."
git --no-pager diff --stat -- mobile/src/api/schema.ts
echo "API_SCHEMA_B64_BEGIN"
gzip -9c mobile/src/api/schema.ts | base64 -w 1000
echo "API_SCHEMA_B64_END"
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
