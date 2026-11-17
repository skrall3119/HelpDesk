# CaseLantern internal runbook

For the team operating our Fedora server. This is not a public installation guide.
Endpoint usage belongs in [the API reference](AUTHENTICATION.md); schemas and
database invariants belong in [the database reference](../database/README.md).

## Environment inventory

Last reported by the owner on 2026-11-17; recheck before changing a target.

| Item | Personal development environment |
| --- | --- |
| Checkout | `/home/ajanci/projects/caselantern` |
| Database | `caselantern` (do not rename to the previously proposed `caselantern_alex`) |
| Tenant | `jancitech` |
| Base domain | `alex.dev.alexjanci.com` |
| Tenant URL | `https://jancitech.alex.dev.alexjanci.com` |
| Runtime / migration roles | `caselantern_app` / `caselantern_migrator` |
| PHP-FPM | Worker `apache`; upstream `php-fpm` uses `/run/php-fpm/www.sock` |
| Host security | SELinux enforcing; Nginx and PHP-FPM running |
| Auth verification | Auth migration applied; HTTPS `/api/v1/me` returned 401 without a token |

The separate `/var/www/caselantern/alex` directory was an older application copy
without Git metadata. Do not overwrite it or mistake it for the active checkout.
The proposed friend/integration environments are not confirmed provisioned.

## Working rules

- Preserve `.env`, `APP_KEY`, tenant data, and existing uncommitted files. Do not
  run `migrate:fresh`, reset the database, or regenerate an existing application key.
- Run migrations with the owner connection `pgsql_migrator`; web requests use the
  restricted runtime account. Do not save migration passwords in web configuration.
- Apply forward migrations; do not rewrite migrations already applied to shared data.
- Keep credentials, private keys, database dumps, and personal helper files out of Git.
- Use only disposable `_test` databases for fixtures and SQL regression tests.
- Update API contracts and endpoint guides in the same task as endpoint changes.

## Updating our checkout

Run `git status --short` before switching branches. Preserve local changes, then
fetch and switch to the reviewed branch. Personal development is updated manually;
integration automation must never target it. Use the committed dependency lockfiles:

```bash
cd ~/projects/caselantern
git status --short
git fetch origin
composer install --no-interaction --prefer-dist
composer check-platform-reqs
npm ci
npm run build
```

Run dependency/build commands when the checkout needs them. The established
toolchain is PHP 8.5, Composer 2, Node 22, and PostgreSQL 18; PHP needs `pdo_pgsql`
in addition to Composer's platform requirements. No Docker setup is required.
`composer setup` installs/builds dependencies and copies `.env.example` only if
`.env` is missing; it does not apply migrations or generate an application key.

Before editing configuration, save a private copy outside the repository:

```bash
backup_dir="$HOME/.config/caselantern-backups"
install -d -m 700 "$backup_dir"
install -m 600 .env "$backup_dir/env-$(date +%Y%m%d-%H%M%S)"
```

Personal environment values (preserve other settings and secrets):

```dotenv
APP_URL=https://jancitech.alex.dev.alexjanci.com
APP_DEBUG=false
TENANT_BASE_DOMAIN=alex.dev.alexjanci.com
DEPLOYMENT_ENVIRONMENT=developer
DB_CONNECTION=pgsql
DB_DATABASE=caselantern
DB_HOST=127.0.0.1
DB_URL=
```

After editing, run `php artisan config:clear`. This clears generated configuration,
not `.env` or database data. Cache configuration only outside a migration-credential
subshell. Each checkout must have its own storage/cache; do not share those directories.
If one environment later spans multiple application instances, use a shared cache
for login rate limits, with an environment-specific namespace.

## Database administration and migrations

Inspect the configured database before issuing commands. For the existing personal site:

```bash
sudo -u postgres psql -d caselantern
```

Useful read-only queries inside psql:

```sql
SELECT slug, active FROM public.tenants;
SELECT rolname FROM pg_roles
WHERE rolname IN ('caselantern_migrator', 'caselantern_app', 'caselantern_tenant_lookup');
```

Exit with `\q`. The cluster roles already exist on our personal server. The lookup
role is required before migration 003; only if it is missing on another intended
target, run this once from the checkout:

```bash
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d postgres < database/setup/authentication-role.sql
```

Shell redirection lets the checkout owner read the file without granting the
`postgres` OS user access to a private home directory. The script deliberately
fails if the role exists. Do not rerun the cluster role bootstrap on this server.

For pending migrations, inspect status first, review the pending changes, and
take the appropriate database backup before applying them. Use hidden input and
process-scoped migration credentials:

```bash
php artisan config:clear
(
  export DB_MIGRATION_USERNAME=caselantern_migrator
  read -rsp "Migration database password: " DB_MIGRATION_PASSWORD
  echo
  export DB_MIGRATION_PASSWORD
  php artisan migrate:status --database=pgsql_migrator
  # After reviewing the pending migration list:
  # php artisan migrate --database=pgsql_migrator --force
)
```

Uncomment the migrate command only when applying the reviewed changes. Use the
PostgreSQL migration-role password, not the Linux/sudo or employee password.
Apply the reusable runtime grants to the intended migrated database when required:

```bash
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d caselantern < database/setup/runtime-grants.sql
```

Use Laravel's migration tracking for application databases, not direct SQL plus
Laravel on the same schema. Baseline adoption is only for a separately reviewed
preexisting schema. Never enable it merely to bypass a migration error.

## Tenant administration

Provision only a new organization; do not rerun provisioning for `jancitech`.
Apply pending migrations first. Inside the same credential subshell used above,
run `php artisan caselantern:provision-tenant` instead of the migration command.

The command prompts for organization name, slug, administrator name/email, and a
hidden password/confirmation. It requires the table-owner connection, creates the
tenant and administrator atomically under transaction-local tenant context, and
rejects existing slugs. Noninteractive provisioning is unsupported. Slugs are one
lowercase DNS label (up to 63 characters); passwords require at least 12 characters
and at most 72 bytes. Email is normalized to lowercase.

Provisioning does not create DNS or certificates. Password recovery and employee
management APIs are not implemented. Passwords are hashes and cannot be decrypted;
an administrative reset must target the exact tenant and employee, hash through
Laravel, and verify one updated row in a transaction. A password change invalidates
that employee's existing bearer tokens. Do not put real passwords into SQL history.

## Web serving and HTTPS

The personal site uses Cloudflare DNS `*.alex.dev` pointing at the server's public
IPv4, with **DNS only**. The configured certificate is for the individual hostname
`jancitech.alex.dev.alexjanci.com`, not every tenant covered by that DNS wildcard.
An additional tenant needs matching Nginx routing and certificate coverage.

The intended site file is `/etc/nginx/conf.d/caselantern-alex.conf`, with document
root `/home/ajanci/projects/caselantern/public`. Inspect `sudo nginx -T` before
editing; retain Certbot's existing TLS configuration. The Laravel locations are:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_pass php-fpm;
}
location ~ \.php$ { return 404; }
location ~ /\. { deny all; }
```

Serve only `public`, never the repository root. Keep the Authorization forwarding.
Validate before reload: `sudo nginx -t && sudo systemctl reload nginx`.
Inbound 80/443 must be permitted by the host's active firewalld zone and provider
firewall; PostgreSQL and development HTTP ports remain private.

Certificates use Fedora's `certbot` and `python3-certbot-nginx` packages. Check
`sudo certbot certificates`, `sudo certbot renew --dry-run`, and
`systemctl list-timers --all | grep -i certbot`. If no renewal timer exists,
configure the installed package's renewal service before relying on automation.
For an approved new hostname, `sudo certbot --nginx -d HOSTNAME --redirect`
obtains and installs its certificate after DNS/Nginx are ready. Back up the site
configuration before certificate or routing changes.

### Permissions and SELinux

Nginx needs traversal through the checkout's parent directories and read access
to public assets. PHP-FPM (`apache`) needs traversal, read access to application
code/dependencies and `.env`, and write access only to `storage` and `bootstrap/cache`.
Use named ACLs rather than opening the entire home directory or using mode 777.

Inspect `namei -l public/index.php`, `getfacl .env storage bootstrap/cache`, and
`ls -Zd . storage bootstrap/cache` from the checkout. Preserve default ACLs granting
`ajanci` and `apache` access to newly created runtime files. CLI-created compiled
views can still have ownership-sensitive timestamp behavior: clear them with
`php artisan view:clear` and let PHP-FPM regenerate them if `touch(): Utime failed`
occurs. If the error persists, inspect file ownership and SELinux denials first.

Persistent SELinux rules should label the checkout `httpd_sys_content_t`, with
`storage` and `bootstrap/cache` as `httpd_sys_rw_content_t`. Parent traversal also
needs appropriate policy; the home-based site uses `httpd_enable_homedirs`, and
PostgreSQL access uses `httpd_can_network_connect_db`. Inspect existing rules with
`sudo semanage fcontext -l -C` before adding or modifying them; use `restorecon`
after changing rules. Keep SELinux enforcing. Shared PHP-FPM reloads affect other
sites; coordinate them rather than reloading it for every documentation change.

## Verification and troubleshooting

```bash
curl --fail https://jancitech.alex.dev.alexjanci.com/up
curl -i -H 'Accept: application/json' https://jancitech.alex.dev.alexjanci.com/api/v1/me
```

Expect 200 from `/up` and 401 from `/me` without a token. `/up` only checks app boot;
complete the [Postman workflow](AUTHENTICATION.md#manual-postman-walkthrough) to
verify login, identity, logout, and rejection of the revoked token.

| Symptom | Investigation / action |
| --- | --- |
| 404 on auth routes | Check hostname, `TENANT_BASE_DOMAIN`, tenant slug and active state |
| `public.lookup_tenant` missing | Check auth migration status and lookup-role prerequisite |
| Migrator attempts user `ajanci` | Supply migration credentials in an uncached CLI process |
| Ident authentication failure | Inspect `SHOW hba_file;`; use narrow SCRAM rules for intended database/role pairs before general Ident rules; validate `pg_hba_file_rules` and reload PostgreSQL configuration; never switch to `trust` |
| `psql -f` permission denied in home checkout | Use shell input redirection as shown above |
| Certbot cannot import `pyparsing` | Install Fedora `python3-pyparsing`, then run `sudo certbot plugins`; if unresolved, inspect executable path/package versions |
| 500 or access denied | Inspect Laravel logs, Nginx error log, PHP-FPM journal, ACLs and SELinux audit denials; keep debug disabled |

Useful diagnostics: `grep -n -A 5 'ERROR' storage/logs/laravel*.log | tail -50`,
`sudo tail -40 /var/log/nginx/error.log`, and
`sudo journalctl -u php-fpm --since '10 minutes ago' --no-pager -n 40`.
Redact secrets and personal data before sharing logs.

## Tests and CI

Use `caselantern_provisioning_test` with synthetic fixtures, separate owner/runtime
roles, and all migrations applied. Create it only if missing, as an empty database
owned by `caselantern_migrator`; do not clone a database containing real tenants.
For initial preparation, use `DB_DATABASE=caselantern_provisioning_test` with the
migration subshell above, then apply `database/setup/runtime-grants.sql` to that
test database. Both roles need SCRAM access to it. Clear cached config first.

Inside the migration credential subshell, run:

```bash
DB_CONNECTION=pgsql DB_DATABASE=caselantern_provisioning_test DB_URL='' \
CASELANTERN_TEST_DATABASE=caselantern_provisioning_test \
vendor/bin/phpunit --fail-on-skipped
```

Runtime credentials come from the checkout's existing restricted account. Tests
reject superuser/BYPASSRLS runtime identities and clean up their own A/B fixtures.
The ordinary `php artisan test` command may skip PostgreSQL tests without this
configuration; skips are not a passing integration result. Also run:

```bash
vendor/bin/pint --test app tests
npm run build
```

[CI](../.github/workflows/ci.yml) is the executable reference for disposable role
bootstrap, fresh migrations, and SQL schema/upgrade regressions. The SQL upgrade
suite requires a separate disposable baseline-only database; do not run its seeded
fixtures against application data. CI also requires PostgreSQL PHPUnit without
skips, formatting, shell syntax validation, and the asset build. Record actual
results; a documented command is not evidence that it passed.

## Integration deployment

This is the intended separate shared environment, not a claim it is running.
Its checkout is `/var/www/caselantern/development`, database
`caselantern_integration`, and suggested private listener `127.0.0.1:8002`.
The friend environment likewise needs its own checkout/database. Shared database
roles do not isolate developers who possess those credentials.

When commissioning integration, create only missing resources; preserve the
personal database. Install locked dependencies/assets in the new checkout, use
a new private `.env` and application key only for that new instance, and set
`DEPLOYMENT_ENVIRONMENT=integration`, `DB_DATABASE=caselantern_integration`, and
`DB_URL=`. Use `TENANT_BASE_DOMAIN=localhost` and `APP_URL=http://localhost:8002`
for the private listener. Provide runtime credentials persistently and migration
credentials to the deployment process. Apply migrations and runtime grants using
the procedures above with the integration database explicitly selected.

Use [the Nginx template](../deploy/nginx-development.conf) only for integration,
after checking the port is free and SELinux allows it. Apply the read/write ACL
and SELinux model above to that checkout. Provision its test tenant explicitly.
For private access, forward port 8002 over the team's normal SSH connection and
use `<tenant>.localhost:8002`. Never bind the development server publicly.

Before enabling automation, run `php artisan caselantern:check-deployment caselantern_integration`
with migration credentials available. It checks both actual database targets and
refuses developer/production environments or a database without the `_integration`
suffix. Keep the integration checkout on `main` with no tracked local edits.

GitHub environment `development`, restricted to main, supplies:

| Variable / secret | Purpose |
| --- | --- |
| `DEV_HOST`, `DEV_USER`, `DEV_PORT` | Verified server SSH target (port defaults to 22) |
| `DEV_PATH` | `/var/www/caselantern/development` |
| `DEV_DATABASE` | `caselantern_integration` |
| `DEV_HEALTH_URL` | `http://127.0.0.1:8002/up` |
| `DEV_RESTART_COMMAND` | Reviewed restart action; `true` only with verified suitable OPcache timestamp checks |
| `DEV_SSH_KEY` (secret) | Dedicated deployment key |
| `DEV_KNOWN_HOSTS` (secret) | Independently verified SSH host keys |

The [workflow](../.github/workflows/deploy-development.yml) deploys only successful
main-push CI revisions. The [script](../scripts/deploy-development.sh) locks the
checkout, rejects divergence, enters maintenance, builds, migrates, caches, and
checks `/up`. Its current SSH invocation does not inject migration credentials:
secure process-scoped delivery must be arranged and verified before enabling it.
Do not solve this by caching owner credentials into the web application.

Failed deployments remain in maintenance mode. Inspect the failure, fix it, and
rerun the reviewed deployment; do not blindly roll back the database or bring a
broken application up. Confirm auth separately from `/up`. Automatic deployment
must not migrate personal or friend databases.

## Backup and recovery boundary

Keep private configuration backups separate from database backups. Verify a
recoverable database backup before risky migrations; restore rehearsals use a
separate database, never overwrite current tenant data as a troubleshooting step.
No backup schedule, restore test, production release, or recovery target is
claimed verified here. Those operational decisions remain tracked in the
[project plan](PROJECT_PLAN.md#17-deployment-and-operations).
