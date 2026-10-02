#!/usr/bin/env bash
# Clean consumer install proof: a scratch host that installs only its own npm
# dependencies, receives the package the way Composer places it in
# vendor/tey/concierge (tracked files only, no node_modules), then type-checks
# and builds. Fails if any package import is undeclared by the host.
set -euo pipefail

package_root="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/concierge-consumer.XXXXXX")"
trap 'rm -rf "$work"' EXIT

cp -R "$package_root/tests/Consumer/host/." "$work/"
mkdir -p "$work/vendor/tey/concierge"
(cd "$package_root" && git ls-files --cached --others --exclude-standard -z \
    | xargs -0 tar -cf -) | tar -xf - -C "$work/vendor/tey/concierge"

cd "$work"
npm install --no-audit --no-fund --loglevel=error
npm run types:check
npm run build

css="$(ls dist/assets/*.css)"
grep -q -- '--concierge-surface' "$css"
grep -q 'var(--concierge-primary-solid)' "$css"
grep -q '#0f766e' "$css"
# The local-login button is imported and rendered by the host, so its markup must be in the bundle.
grep -q 'data-concierge' dist/assets/*.js
grep -q 'local-login' dist/assets/*.js
echo "Consumer proof passed: $(du -h "$css" | cut -f1) CSS, $(ls dist/assets/*.js | wc -l | tr -d ' ') JS chunk(s)."
