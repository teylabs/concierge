#!/usr/bin/env bash
# End-to-end proof of the main install path on a real app: the official Vue
# starter kit, `composer require` of this checkout, then concierge:install.
# The other kits are covered by fixtures in tests/Setup.
#
# Usage: tests/Install/prove-vue-kit.sh [work-dir]
set -euo pipefail

package="$(cd "$(dirname "$0")/../.." && pwd)"
work="${1:-$(mktemp -d)}"
app="$work/app"

step() { printf '\n== %s\n' "$1"; }
fail() { printf 'FAILED: %s\n' "$1" >&2; exit 1; }

step 'Create a Vue starter kit app'
rm -rf "$app"
(cd "$work" && laravel new app --vue --pest --database=sqlite --no-node --no-boost --no-interaction)
cd "$app"

step 'Require this concierge checkout'
composer config repositories.concierge "{\"type\": \"path\", \"url\": \"$package\", \"options\": {\"symlink\": false}}"
composer config minimum-stability dev
composer config prefer-stable true
composer require tey/concierge:@dev --no-interaction --no-progress

step 'Before setup: migrate creates no concierge tables'
php artisan migrate --force --no-interaction
php artisan about --only=concierge | grep -q 'Not set up: run php artisan concierge:install' || fail 'about does not say concierge is not set up'
[ ! -f config/concierge.php ] || fail 'config/concierge.php exists before install'

step 'First run: stops at the user model and prints the lines to add'
set +e
php artisan concierge:install --no-interaction --json > first.json
status=$?
set -e
[ "$status" -ne 0 ] || fail 'first run should stop at the user model'
php -r '$r = json_decode(file_get_contents("first.json"), true); foreach ($r["steps"] as $s) { if ($s["step"] === "user-model" && $s["status"] === "manual") exit(0); } exit(1);' || fail 'first run did not report the user model as manual'

step 'Apply the printed model lines'
php -r '
$path = "app/Models/User.php";
$source = file_get_contents($path);
$member = "\\\\Tey\\\\Concierge\\\\Contracts\\\\Member";
$source = preg_match("/class User extends Authenticatable implements [^{\n]+/", $source)
    ? preg_replace("/(class User extends Authenticatable implements [^{\n]+?)(\s*\n\{)/", "$1, $member$2", $source, 1, $classes)
    : preg_replace("/class User extends Authenticatable\b/", "class User extends Authenticatable implements $member", $source, 1, $classes);
$source = preg_replace("/(class User extends[^{]*\{\n)/", "$1    use \\\\Spatie\\\\Permission\\\\Traits\\\\HasRoles, \\\\Tey\\\\Concierge\\\\Concerns\\\\InteractsWithMemberships;\n\n", $source, 1, $traits);
if ($classes !== 1 || $traits !== 1) { fwrite(STDERR, "Could not edit $path\n"); exit(1); }
file_put_contents($path, $source);
'

step 'Second run: sets concierge up'
php artisan concierge:install --no-interaction --json > second.json
php -r '$r = json_decode(file_get_contents("second.json"), true); exit($r["set_up"] === true && $r["detected"]["starter_kit"] === "vue" ? 0 : 1);' || { cat second.json; fail 'second run did not set concierge up on the Vue kit'; }

step 'Third run: everything already in place'
php artisan concierge:install --no-interaction --json > third.json
php -r '
$r = json_decode(file_get_contents("third.json"), true);
foreach ($r["steps"] as $s) {
    if (! in_array($s["step"], ["first-owner", "frontend"], true) && $s["status"] !== "already") { fwrite(STDERR, "{$s["step"]}: {$s["status"]}\n"); exit(1); }
}
' || fail 'third run changed something'

step 'After setup: about, tables and the kit login'
php artisan about --only=concierge | grep -q 'Done' || fail 'about does not say concierge is set up'
php artisan tinker --execute 'echo Schema::hasTable("concierge_invitations") && Spatie\Permission\Models\Role::where("name", "owner")->exists() ? "concierge-ready" : "concierge-missing";' | grep -q 'concierge-ready' || fail 'concierge tables or roles missing'
# No Node build here: point Vite at a dev server so pages render without a manifest.
echo 'http://localhost:5173' > public/hot
php artisan test --filter=Authentication || fail 'the starter kit login tests fail with concierge installed'

step 'Proved'
