#!/bin/bash
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

TYPE="${1:-}"

case "$TYPE" in
  patch|minor|major) ;;
  *)
    echo "Usage: ./scripts/release.sh {patch|minor|major}"
    exit 1
    ;;
esac

# Require a clean working tree
if [ -n "$(git status --porcelain)" ]; then
  echo "ERROR: Working tree is not clean."
  echo "Commit or stash your changes before creating a release."
  exit 1
fi

# Read and increment Expo version
VERSION=$(node - "$TYPE" <<'NODE'
const fs = require('fs');
const type = process.argv[2];
const file = 'app.json';
const config = JSON.parse(fs.readFileSync(file, 'utf8'));

const match = config.expo.version.match(/^(\d+)\.(\d+)\.(\d+)$/);
if (!match) {
  throw new Error('Expected expo.version in MAJOR.MINOR.PATCH format');
}

let [major, minor, patch] = match.slice(1).map(Number);

if (type === 'major') {
  major++;
  minor = 0;
  patch = 0;
} else if (type === 'minor') {
  minor++;
  patch = 0;
} else {
  patch++;
}

const version = `${major}.${minor}.${patch}`;
config.expo.version = version;
fs.writeFileSync(file, JSON.stringify(config, null, 2) + '\n');
console.log(version);
NODE
)

TAG="v$VERSION"

# Avoid overwriting an existing tag
if git rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
  git checkout -- app.json
  echo "ERROR: Tag $TAG already exists."
  exit 1
fi

# Create release commit and annotated tag
git add app.json
git commit -m "chore(release): $TAG"
git tag -a "$TAG" -m "Release $TAG"

echo ""
echo "Release prepared successfully: $TAG"
echo "Expo version: $VERSION"
echo "Git tag: $TAG"
echo ""
echo "To push the commit and tag:"
echo "  git push origin HEAD"
echo "  git push origin $TAG"
echo ""
echo "To build iOS:"
echo "  eas build --platform ios --profile production"
