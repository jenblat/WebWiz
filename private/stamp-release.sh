#!/bin/bash
# Writes the commit currently checked out into private/RELEASE so PHP can report it
# to Sentry as part of the release. Safe to run any time; called by the git
# post-merge and post-checkout hooks.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SHA="$(git -C "$ROOT" describe --tags --always --dirty 2>/dev/null || echo unknown)"
printf '%s\n' "$SHA" > "$ROOT/private/RELEASE"
echo "[stamp-release] $ROOT/private/RELEASE = $SHA"
