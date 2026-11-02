# Tenant provisioning

`caselantern:provision-tenant` creates one tenant and its first active administrator.
It does not implement browser login, public signup, password recovery, or DNS setup.
Tenant accounts remain independent even when their email addresses match.

## Preconditions

- Apply both existing database migrations first.
- Use the dedicated `pgsql_migrator` connection in `config/database.php`.
  Set `DB_MIGRATION_USERNAME`/`DB_MIGRATION_PASSWORD` for this CLI process only;
  do not replace the application's `DB_USERNAME`/`DB_PASSWORD`.
- The connection must own `public.tenants` and `public.users`. The command does
  not fall back to runtime credentials, disable RLS, or change grants.
- Configuration must be uncached when supplying command-scoped credentials.
  Run `php artisan config:clear` if needed; do not cache migration credentials
  into the web application's configuration.

## First run

From the Laravel project root, prompt for the migration role password:

```bash
read -rsp "Migration database password: " CASELANTERN_MIGRATION_PASSWORD
echo

DB_MIGRATION_USERNAME=caselantern_migrator \
DB_MIGRATION_PASSWORD="$CASELANTERN_MIGRATION_PASSWORD" \
php artisan caselantern:provision-tenant

unset CASELANTERN_MIGRATION_PASSWORD
```

The command asks for the organization name, subdomain slug, administrator name,
email, password, and password confirmation. Password input is hidden, with no
visible fallback, password argument, or default password. Do not paste passwords
into chat. Noninteractive mode is deliberately unsupported.

Names are trimmed. Email is trimmed and normalized to lowercase. The slug must
be one lowercase DNS label, at most 63 characters, with internal hyphens allowed.
Passwords require at least 12 characters and at most 72 bytes (bcrypt limit).
The application hashes the password before it reaches the database.

The nonsecret fields can alternatively be supplied as options:

```bash
php artisan caselantern:provision-tenant \
  --name="Acme Corporation" --slug=acme \
  --admin-name="Alex" --admin-email="alex@example.com"
```

Supply the same process-scoped migration credentials for this invocation.
Choose the real organization's details when prompted; Acme is only an example.
The slug will identify its future tenant subdomain. Browser login is a later slice.

## Transaction and isolation

The action reserves a tenant identity from its PostgreSQL sequence, establishes
transaction-local `app.tenant_id`, and inserts the tenant with
`OVERRIDING SYSTEM VALUE`. It then inserts the administrator on the same connection
and transaction. This satisfies existing forced row-level security without
temporarily disabling policies. Any write failure rolls back both records;
sequence gaps are normal. Existing slugs fail rather than updating accounts.

The action requires a standalone transaction on the dedicated migration
connection; do not invoke it inside an existing transaction or a browser route.
Transaction-local context ends on commit/rollback. No migration or runtime
permissions are broadened. Console output never includes passwords or hashes.

## Verification

Run application checks and formatting before provisioning a real organization:

```bash
vendor/bin/pint --test app/Actions/Tenants/ProvisionTenant.php \
  app/Console/Commands/ProvisionTenant.php \
  tests/Feature/ProvisionTenantCommandTest.php \
  tests/Feature/ProvisionTenantPostgresTest.php
php artisan test
```

The normal suite tests validation and console behavior without accessing a real
database. The PostgreSQL tests explicitly skip unless a test database is supplied;
skipped integration tests are not evidence that provisioning works.

Use a dedicated database ending in `_test`. With the already-upgraded, unused
`caselantern_schema_check` database as a template, create one on the server:

```bash
sudo -u postgres createdb -O caselantern_migrator \
  -T caselantern_schema_check caselantern_provisioning_test
```

If that name already exists, inspect it rather than dropping/recreating it. The
template must have both SQL migrations applied and no active connections during
cloning. Use the non-superuser, non-BYPASSRLS migration role for the test connection.
No runtime-role password is required for these owner/forced-RLS tests; the separate
SQL schema suite already exercises the runtime role policies.

```bash
read -rsp "Migration database password: " CASELANTERN_MIGRATION_PASSWORD
echo

CASELANTERN_TEST_DATABASE=caselantern_provisioning_test \
DB_MIGRATION_USERNAME=caselantern_migrator \
DB_MIGRATION_PASSWORD="$CASELANTERN_MIGRATION_PASSWORD" \
php artisan test --filter=ProvisionTenant

unset CASELANTERN_MIGRATION_PASSWORD
```

The test database override applies only to the dedicated migration connection;
the default PHPUnit SQLite configuration is unchanged. Tests clean up only their
own fixtures and never run `migrate:fresh`, truncate tables, or reset sequences.
They verify password hashing, duplicate email across tenants, tenant isolation,
context cleanup, duplicate slug rejection, and rollback when administrator insert
fails. They are not login or password-recovery tests.

These checks must be executed on the server: the authoring workspace has neither
PHP nor PostgreSQL available. Record actual results before claiming verification.

## Framework references

- [Artisan commands and hidden input](https://laravel.com/framework/docs/artisan/commands)
- [Laravel console testing](https://laravel.com/framework/docs/console-tests)
- [PostgreSQL identity columns](https://www.postgresql.org/docs/18/ddl-identity-columns.html)
- [PostgreSQL row security](https://www.postgresql.org/docs/18/ddl-rowsecurity.html)
