# Development setup

Use a separate development database and credentials per developer. This guide
targets the native Linux/PHP/PostgreSQL workflow already used on the project;
Docker is not required. GitHub Actions supplies an isolated PostgreSQL service.

## Prerequisites

Git, PHP 8.5 with PDO PostgreSQL/SQLite, DOM/XML/XMLWriter, fileinfo, mbstring and
the extensions checked by Composer; Composer 2; Node 22/npm; PostgreSQL 18 client
and server. The owner verified PHP 8.5.12/Laravel 13.39.0/PostgreSQL 18.6. Use the
lockfiles, not unreviewed dependency updates. `composer check-platform-reqs`
identifies missing PHP requirements; `pdo_pgsql` is additionally required even
though the framework can install without it.

## Existing owner server: preserve it

The existing checkout is `/home/ajanci/projects/caselantern`. Its database,
application key, migrations, runtime account, and Janci Tech tenant already exist.
**Do not run the fresh-role/database commands below on that setup.**

Before changing branch, run `git status --short`. Preserve untracked server files;
do not use `git clean`, reset, or overwrite them. Fetch and switch to the reviewed
feature branch. Run `composer install`, `npm ci`, and `npm run build` only when
dependencies/assets need installing; no database reset or key rotation is needed.

## Fresh checkout

```bash
git clone git@github.com:skrall3119/HelpDesk.git caselantern
cd caselantern
composer install
cp .env.example .env
```

Copy `.env.example` only when `.env` does not already exist. Set the runtime
credentials locally; never paste them into chat or commit them:

```dotenv
APP_NAME=CaseLantern
APP_ENV=local
APP_DEBUG=false
APP_URL=http://localhost:8000
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=caselantern
DB_USERNAME=caselantern_app
DB_PASSWORD="YOUR_LOCAL_RUNTIME_PASSWORD"
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
MAIL_MAILER=log
```

Leave migration credentials out of persistent web configuration. Generate an
application key **once on a new checkout with an empty APP_KEY**:

```bash
php artisan key:generate
composer check-platform-reqs
php -m
```

Confirm `pdo_pgsql` is listed. Preserve an existing application key and its secrets.

## Fresh PostgreSQL roles and database

These commands intentionally fail if the roles/database already exist. Inspect an
existing installation rather than replacing its credentials, owners, or data.
They assume local `sudo -u postgres` administration; use the equivalent authorized
cluster administrator on another development machine.

```bash
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d postgres \
  -f - < database/setup/development-roles.sql
sudo -u postgres psql -X -d postgres -c '\password caselantern_migrator'
sudo -u postgres psql -X -d postgres -c '\password caselantern_app'
sudo -u postgres createdb -O caselantern_migrator caselantern
```

The password prompts are hidden. Use different passwords for the two accounts;
the runtime password must match the local `.env` value. Both roles are explicitly
non-superuser/non-BYPASSRLS, and the runtime role must never own application tables.

Find the authentication file with `SHOW hba_file;`. Before a general localhost
Ident rule, add narrowly scoped SCRAM rules for these development databases/roles:

```text
host caselantern,caselantern_provisioning_test caselantern_migrator,caselantern_app 127.0.0.1/32 scram-sha-256
```

Back up the file before editing. On the owner's Fedora server it is
`/var/lib/pgsql/data/pg_hba.conf`; do not assume that path on another installation.
Check `pg_hba_file_rules` for errors before `SELECT pg_reload_conf();`. Never
replace general authentication with `trust`. The first matching rule wins.
The IPv4 rule matches the configured `DB_HOST=127.0.0.1`; add an equally narrow
IPv6 rule only if using `::1` instead.

## Apply migrations through the dedicated connection

Run `php artisan config:clear` if configuration was cached. On a fresh database:

```bash
read -rsp "Migration database password: " CASELANTERN_MIGRATION_PASSWORD
echo

DB_MIGRATION_USERNAME=caselantern_migrator \
DB_MIGRATION_PASSWORD="$CASELANTERN_MIGRATION_PASSWORD" \
php artisan migrate --database=pgsql_migrator

unset CASELANTERN_MIGRATION_PASSWORD
```

Use the same process-scoped variables and `--database=pgsql_migrator` for
`migrate:status`. This keeps the Laravel migration repository and both migration
wrappers on the owner connection. Do not save migration passwords in `.env` or
cache them into the application. Existing applied migrations are not reapplied.

Apply runtime grants to the intended migrated database:

```bash
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d caselantern \
  -f - < database/setup/runtime-grants.sql
```

The grant script is reusable after migrations and grants only the current domain
access/sequence usage. It does not grant migration-table access, DDL, hard DELETE,
TRUNCATE, ownership, or BYPASSRLS. It does not remove previously overbroad role
memberships/grants: tests flag that misconfiguration for explicit review.

Use either Laravel migration tracking or the direct SQL route documented in
`database/README.md`, never both on the same schema. Do not set the baseline
adoption flag unless the preexisting schema has actually been reviewed.

## Assets and browser access

```bash
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

For a remote server, use the SSH tunnel in the root README from your own computer.
Open `http://localhost:8000`; `curl -I --max-time 10 http://127.0.0.1:8000`
on the server checks Laravel independently of SSH. The SSH `-N` command intentionally
has no shell prompt. Do not expose the development HTTP server publicly.

`npm run build` avoids needing a second forwarded Vite port. A local Vite watcher
may be added when useful, but it is not required for Day 1. The optional fontaine
message does not fail the current build. Public storage linking is not required
before a feature actually uses that disk. Recovery mail capture is a later task.

## PostgreSQL tests

The default PHPUnit suite includes SQLite smoke/validation checks. Real database
tests require the explicit disposable database `caselantern_provisioning_test`.
Never point them at `caselantern`, real tenant data, or production.

For a **new test database only**, create and migrate it without cloning real data:

```bash
sudo -u postgres createdb -O caselantern_migrator caselantern_provisioning_test

read -rsp "Migration database password: " CASELANTERN_MIGRATION_PASSWORD
echo

DB_DATABASE=caselantern_provisioning_test \
DB_MIGRATION_USERNAME=caselantern_migrator \
DB_MIGRATION_PASSWORD="$CASELANTERN_MIGRATION_PASSWORD" \
php artisan migrate --database=pgsql_migrator

unset CASELANTERN_MIGRATION_PASSWORD
```

The owner already has a separate migrated test database created from the empty
schema-check database. Do not recreate it or blindly adopt/reapply the baseline.
Both paths need runtime grants and SCRAM access for **both** roles on the test DB:

```bash
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d caselantern_provisioning_test \
  -f - < database/setup/runtime-grants.sql

read -rsp "Migration database password: " CASELANTERN_MIGRATION_PASSWORD
echo

CASELANTERN_TEST_DATABASE=caselantern_provisioning_test \
DB_MIGRATION_USERNAME=caselantern_migrator \
DB_MIGRATION_PASSWORD="$CASELANTERN_MIGRATION_PASSWORD" \
vendor/bin/phpunit --fail-on-skipped

unset CASELANTERN_MIGRATION_PASSWORD
```

Runtime test credentials are taken from `.env`'s normal `DB_USERNAME`/`DB_PASSWORD`;
only the test connection's database is overridden. A `DB_URL` cannot redirect it.
The new fixture tests reject superusers, BYPASSRLS, and runtime hard-delete or
provisioning privileges. No passwords are shared between developers.

`Tests\Support\TenantFixtures` creates synthetic A/B tenants with the same email,
wraps reads/writes in transaction-local tenant/actor context, and cleans up only
its own tenant/admin accounts. Tests creating additional domain data must roll
those changes back before account cleanup. It is not a production seeder or
a helper for deleting audited ticket history.

Additional checks:

```bash
vendor/bin/pint --test app tests
php artisan test
npm run build
```

The SQL suite additionally needs a disposable database and a superuser to create
its transactional non-login test role. See `database/README.md` for schema/upgrade
checks. Never run the seeded SQL suite in a live application's database.

## CI and collaboration

`.github/workflows/ci.yml` runs on main pushes and PRs using synthetic disposable
credentials, PostgreSQL 18, separate non-owner runtime and owner roles, fresh
Laravel migrations, SQL upgrade/schema checks, mandatory PostgreSQL PHPUnit tests,
Pint, and asset build. It needs no server credentials or SSH access. The initial
run is an acceptance gate, not something file creation proves has passed.

Use short branches and reviewed PRs; each developer owns their environment.
Coordinate schema migrations, preserve unrelated changes, and do not edit already
applied migrations. Keep future work aligned with the daily sprint schedule.
