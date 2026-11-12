# Bearer authentication

Owner decision (2026-11-11): bearer authentication with a **fixed 48-hour lifetime** replaces the cookie-session default in the project plan for these APIs. No refresh token or sliding expiry. An expired token requires email/password login again. Recovery/setup and frontend login screens are separate work.

All routes use `{tenant_slug}.{TENANT_BASE_DOMAIN}/api/v1`. The host selects the tenant; submitted tenant/user IDs never select identity. Use HTTPS outside a loopback SSH tunnel. Only `Authorization: Bearer <token>` authenticates; cookies, query parameters, and request-body tokens do not. No CSRF bootstrap is needed for these header-only endpoints.

| Method | Path | Result |
| --- | --- | --- |
| POST | `/api/v1/login` | Email/password → token, token type, expiry, safe user fields |
| GET | `/api/v1/me` | Current active employee, tenant ID, and role |
| POST | `/api/v1/logout` | Revoke the presented token; return 204 |

See [openapi.json](openapi.json) for the request/response contract. IDs are JSON strings to preserve bigint precision. Unknown/inactive tenant or invalid host returns 404. Invalid credentials, missing/expired/revoked/wrong-tenant token, inactive/deleted employee, or changed password returns 401. Validation returns 422; login throttling returns 429 with Retry-After. Login limits are five attempts per tenant/email/minute and thirty per IP/minute, including successful attempts. Use a shared cache when running multiple application instances.

Tokens contain 256 random bits. Only their SHA-256 hashes are stored. Each request reloads the active employee in the resolved tenant, verifies the password fingerprint, and sets transaction-local actor context. Password changes invalidate existing tokens. Logout revokes the current token; other device tokens remain valid until their own expiry or account/password invalidation. Expired records grant no access even if retained; scheduled retention cleanup may be added separately.

For a browser client, prefer keeping bearer tokens in memory; persistent browser storage exposes them to injected JavaScript. Never put tokens in URLs or logs. A later persistent-login design needs an explicit storage decision. The 48-hour server expiry remains fixed regardless of client storage.

## Existing development server: one-time setup

Keep the current `.env`, `APP_KEY`, database and domain records. Do not rerun the fresh database/role bootstrap or use `migrate:fresh`.

From the repository directory, as an administrator create the restricted lookup role **once** (it deliberately fails if already present):

```bash
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d postgres < database/setup/authentication-role.sql
```

The role has no login, superuser, bypass-RLS, table ownership or domain-write privileges. Migration 003 grants only tenant ID/slug/active reads and installs a function returning ID/active for an exact slug. Its fixed search path and EXECUTE grants keep the bootstrap narrow.

Set `TENANT_BASE_DOMAIN` in the existing server `.env` to the domain suffix served by Nginx (without protocol, tenant prefix or port). Keep the restricted `DB_USERNAME`; use existing `DB_MIGRATION_USERNAME`/`DB_MIGRATION_PASSWORD` for migrations only.

```bash
composer install --no-interaction --prefer-dist
php artisan config:clear
php artisan migrate --database=pgsql_migrator --force
php artisan route:list --path=api/v1
php artisan config:cache
sudo systemctl reload php-fpm
```

## Verification on the development server

Use the existing **disposable** `_test` database and owner/runtime credentials described in [DEVELOPMENT.md](DEVELOPMENT.md). Do not run fixture tests or schema scripts against the application database. Apply migration 003 to the disposable database as well. The cluster role setup above is needed only once per PostgreSQL cluster.

```bash
vendor/bin/pint --test app tests
# Existing disposable database, using the same cluster's restricted and owner roles.
# Clear cached application config so test environment overrides take effect.
php artisan config:clear
(
  export DB_CONNECTION=pgsql DB_DATABASE=caselantern_provisioning_test DB_URL=''
  export CASELANTERN_TEST_DATABASE=caselantern_provisioning_test
  php artisan migrate --database=pgsql_migrator --force &&
  vendor/bin/phpunit --fail-on-skipped
)
php artisan config:cache
npm ci
npm run build
```

GitHub CI already provides separate runtime/migration identities and disposable databases. It now sets up the lookup role before migrations and runs the authentication tests along with existing checks. These new checks have not been run in the Windows authoring workspace by owner request.

Use an HTTP client to POST `{"email":"your-email","password":"your-password"}` to the tenant login endpoint; send the returned token in the Authorization header to `/me` and `/logout`. After logout, `/me` with the same token must return 401. Avoid typing real credentials into shell command history.
