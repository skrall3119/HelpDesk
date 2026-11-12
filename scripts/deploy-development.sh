#!/usr/bin/env bash
# Executed by CI over SSH using the exact source revision which passed checks.
set -euo pipefail

deploy_path=${1:?Repository directory required}
deploy_sha=${2:?Verified commit required}
restart_command=${3:?Restart command required}
integration_database=${4:?Integration database required}
health_url=${5:?Loopback health URL required}
[[ "$health_url" =~ ^http://127\.0\.0\.1:[0-9]+/up$ ]] || { echo 'Use a loopback port health URL ending in /up.'; exit 1; }
[[ "$deploy_path" = /* && "$deploy_sha" =~ ^[a-f0-9]{40}$ ]]
cd -- "$deploy_path"
[[ "$(git rev-parse --show-toplevel)" = "$(pwd -P)" ]]
[[ "$(git branch --show-current)" = main ]] || { echo 'Deployment requires the main branch.'; exit 1; }
test -z "$(git status --porcelain --untracked-files=no)" || { echo 'Tracked server edits must be preserved and resolved before deployment.'; exit 1; }
test -f .env || { echo 'Configure the server .env before deployment.'; exit 1; }
for tool in php composer npm curl flock; do command -v "$tool" >/dev/null; done

# Also serialize manual invocations on the server, not just workflow jobs.
exec 9>storage/framework/development-deploy.lock
flock -n 9 || { echo 'Another deployment is running.'; exit 1; }
git fetch --no-tags origin main
if [[ "$(git rev-parse origin/main)" != "$deploy_sha" ]]; then
    echo 'Skipping an obsolete deployment: main has advanced.'
    exit 0
fi
git merge-base --is-ancestor HEAD "$deploy_sha" || { echo 'Server checkout has diverged; refusing to overwrite it.'; exit 1; }

# Fail closed before maintenance or checkout changes. Bootstrap this command once manually.
php artisan caselantern:check-deployment "$integration_database"

# A failed deployment remains in maintenance mode for inspection; no automatic database rollback.
php artisan down --retry=60
trap 'echo "Deployment failed. Inspect the error; app remains in maintenance mode. Fix and redeploy before artisan up." >&2' ERR
git merge --ff-only "$deploy_sha"
composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader
composer check-platform-reqs
npm ci
npm run build
php artisan config:clear
php artisan caselantern:check-deployment "$integration_database"
php artisan migrate --database=pgsql_migrator --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
bash -lc "$restart_command"
php artisan up
if ! curl --fail --silent --show-error --max-time 15 "$health_url"; then
    php artisan down --retry=60
    echo 'Health check failed; application returned to maintenance mode.' >&2
    exit 1
fi
echo "Deployed $deploy_sha to development."
