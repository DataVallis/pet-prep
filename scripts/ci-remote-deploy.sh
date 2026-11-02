#!/usr/bin/env bash
# Upload the checked-out commit to a server and run its deploy script — the last step
# of the job "Deploy to Hetzner Production" in .github/workflows/deploy-production.yml
# (D18; kept separate so the harness can test it and a second server can reuse it).
#
# Upload goes to a staging dir, NOT into /opt/petprep/repo: repo/ holds the compose
# file, the Caddyfile (bind-mounted into caddy) and the ops scripts, so writing there
# would switch them before maintenance mode / migrations. deploy-production.sh syncs
# incoming/ → repo/ itself and reverts on a pre-migration failure (DEPLOYMENT.md D10).
#
# Prebuilt images (D28): with IMAGE_OWNER + REGISTRY_USER + REGISTRY_TOKEN (the job's
# GITHUB_TOKEN, packages: read) the server logs in to ghcr.io over ssh stdin (the token
# never appears in a command line), deploy-production.sh pulls
# ghcr.io/<owner>/petprep-{app,web}:be-<git tree of backend/> and the server logs out
# again in the same ssh command. Missing env / no git tree / failed login → the server
# builds the images itself (old behaviour).
#
# Env: SSH_PRIVATE_KEY, SSH_HOST, SSH_USER (default deploy), COMMIT_SHA, EXPECT_DOMAIN
#      (the API domain the server must have — deploy-production.sh --expect-domain),
#      KEY_SECRET (name of the GitHub secret, for the error message), TARGET (label).
# Test hook: CI_REMOTE_SSH_DIR (default ~/.ssh).
set -Eeuo pipefail

TARGET="${TARGET:-server}"
KEY_SECRET="${KEY_SECRET:-SSH key secret}"
SSH_USER="${SSH_USER:-deploy}"
COMMIT_SHA="${COMMIT_SHA:?COMMIT_SHA is required}"
EXPECT_DOMAIN="${EXPECT_DOMAIN:?EXPECT_DOMAIN is required}"
SSH_DIR="${CI_REMOTE_SSH_DIR:-${HOME}/.ssh}"

if [ -z "${SSH_PRIVATE_KEY:-}" ]; then
  echo "ERROR: the ${KEY_SECRET} secret is not set in the GitHub repository settings (${TARGET})."
  exit 1
fi
if [ -z "${SSH_HOST:-}" ]; then
  echo "ERROR: no host for ${TARGET}."
  exit 1
fi
# Both reach the remote shell verbatim: plain tokens only.
if ! [[ "$COMMIT_SHA" =~ ^[0-9a-f]{7,40}$ ]] || ! [[ "$EXPECT_DOMAIN" =~ ^[a-z0-9.-]+$ ]]; then
  echo "ERROR: COMMIT_SHA / EXPECT_DOMAIN have unexpected characters."
  exit 1
fi

mkdir -p "$SSH_DIR"
KEY="${SSH_DIR}/deploy_key"
echo "$SSH_PRIVATE_KEY" > "$KEY"
chmod 600 "$KEY"

# Add the remote host key to known_hosts (first contact also accepted below).
ssh-keyscan -H "$SSH_HOST" >> "${SSH_DIR}/known_hosts" 2>/dev/null || true
SSH_OPTS=(-i "$KEY" -o StrictHostKeyChecking=accept-new)

echo "1. Uploading exact commit files to the staging dir on the ${TARGET} server..."
ssh "${SSH_OPTS[@]}" "${SSH_USER}@${SSH_HOST}" "mkdir -p /opt/petprep/incoming"
rsync -az --delete \
  --exclude '.git' \
  --exclude '.idea' \
  --exclude 'backend/storage' \
  --exclude 'backend/vendor' \
  --exclude 'mobile/node_modules' \
  -e "ssh ${SSH_OPTS[*]}" \
  ./ "${SSH_USER}@${SSH_HOST}:/opt/petprep/incoming/"

IMAGE_ARGS=""
LOGIN_CMD=""
if [ -n "${IMAGE_OWNER:-}" ] && [ -n "${REGISTRY_USER:-}" ] && [ -n "${REGISTRY_TOKEN:-}" ]; then
    owner="$(printf '%s' "$IMAGE_OWNER" | tr '[:upper:]' '[:lower:]')"
    user_re='^[][A-Za-z0-9_.-]+$' # also "github-actions[bot]" (the daily deploy's dispatch)
    if ! [[ "$owner" =~ ^[a-z0-9-]+$ ]] || ! [[ "$REGISTRY_USER" =~ $user_re ]]; then
        echo "ERROR: IMAGE_OWNER / REGISTRY_USER have unexpected characters."
        exit 1
    fi
    if be_tree="$(git rev-parse HEAD:backend 2>/dev/null)" && [[ "$be_tree" =~ ^[0-9a-f]{40}$ ]]; then
        IMAGE_ARGS="--registry ghcr.io/${owner} --image-tag be-${be_tree}"
        LOGIN_CMD="docker login ghcr.io -u '${REGISTRY_USER}' --password-stdin > /dev/null 2>&1 || echo 'WARNING: ghcr.io login failed - the images are built on the server.'; "
        echo "   Prebuilt images: ghcr.io/${owner}/petprep-{app,web}:be-${be_tree}"
    else
        echo "   WARNING: no git tree for backend/ — the server builds the images."
    fi
fi

echo "2. Triggering the deployment script on the ${TARGET} server for commit: ${COMMIT_SHA}..."
DEPLOY_CMD="/opt/petprep/incoming/scripts/deploy-production.sh --from /opt/petprep/incoming --expect-domain ${EXPECT_DOMAIN}${IMAGE_ARGS:+ ${IMAGE_ARGS}} ${COMMIT_SHA}"
# shellcheck disable=SC2029  # expanded here on purpose (validated tokens above)
if [ -n "$LOGIN_CMD" ]; then
  printf '%s\n' "$REGISTRY_TOKEN" | ssh "${SSH_OPTS[@]}" "${SSH_USER}@${SSH_HOST}" \
    "${LOGIN_CMD}${DEPLOY_CMD} < /dev/null; rc=\$?; docker logout ghcr.io > /dev/null 2>&1; exit \$rc"
else
  ssh "${SSH_OPTS[@]}" "${SSH_USER}@${SSH_HOST}" "$DEPLOY_CMD"
fi
