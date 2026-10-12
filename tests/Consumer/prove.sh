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

# Plain tsc, with no Vue plugin or ambient declarations: exercise both mod's
# existing wildcard path and package.json exports against the same page props.
cat > page-props.ts <<'TS'
import type {
    MembersPageProps,
    MemberPageProps,
    InviteMemberPageProps,
    AcceptInvitationPageProps,
} from '@tey/concierge/types';

export type PageProps = MembersPageProps | MemberPageProps | InviteMemberPageProps | AcceptInvitationPageProps;
TS
node --input-type=module <<'JS'
import { readFileSync, writeFileSync } from 'node:fs';

const { compilerOptions } = JSON.parse(readFileSync('tsconfig.json', 'utf8'));
const config = {
    compilerOptions: { ...compilerOptions, types: [], skipLibCheck: false },
    include: ['page-props.ts'],
};
writeFileSync('tsconfig.types-paths.json', JSON.stringify(config));
config.compilerOptions.paths = {};
writeFileSync('tsconfig.types-exports.json', JSON.stringify(config));
JS
mkdir -p node_modules/@tey
ln -s "$work/vendor/tey/concierge" node_modules/@tey/concierge
for resolution in paths exports; do
    node_modules/.bin/tsc --project "tsconfig.types-$resolution.json" --listFiles > "types-$resolution.log" 2>&1 || {
        cat "types-$resolution.log"
        exit 1
    }
    if grep -Eq '\.vue$|/node_modules/(vue|@vue|@inertiajs)/' "types-$resolution.log"; then
        cat "types-$resolution.log"
        echo "Types-only import pulled in Vue or Inertia."
        exit 1
    fi
done
echo "Types-only proof passed: plain tsc via wildcard paths and package exports, no Vue or Inertia files."

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
