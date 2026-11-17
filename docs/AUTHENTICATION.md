# Authentication API reference

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

## Base URL and headers

Use `https://<tenant-slug>.<base-domain>/api/v1`. For example, a configured
base domain of `dev.example.com` and an existing tenant `acme` gives
`https://acme.dev.example.com/api/v1`. The example domain is a placeholder;
DNS, HTTPS, and `TENANT_BASE_DOMAIN` must match your environment. The bare base
domain does not select a tenant. A token from one tenant cannot authenticate on another.

Send `Accept: application/json` on every request and `Content-Type: application/json`
when sending a JSON body. Login does not require authorization. Protected requests use:

```http
Authorization: Bearer <access_token>
```

The token is opaque, not a JWT. Use employee credentials provisioned for the selected
tenant, not PostgreSQL or server credentials. Public registration, password recovery,
token refresh, and capability-list endpoints are not implemented.

## POST /api/v1/login

Required JSON fields:

| Field | Rules |
| --- | --- |
| `email` | String, valid RFC email, maximum 254 characters; account lookup is case-insensitive within the tenant |
| `password` | Nonempty string, maximum 1024 characters; must match the employee's password |

Example request (synthetic credentials):

```json
{
  "email": "admin@example.com",
  "password": "Synthetic example password"
}
```

Successful response: **200 OK**. This synthetic token is illustrative and cannot be used:

```json
{
  "access_token": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
  "token_type": "Bearer",
  "expires_at": "2026-11-19T12:00:00.000000Z",
  "user": {
    "id": "42",
    "tenant_id": "7",
    "name": "Example Administrator",
    "email": "admin@example.com",
    "role": "administrator"
  }
}
```

`expires_at` is a UTC timestamp 48 hours after issuance (the example assumes issuance
on November 17 at 12:00 UTC). Each successful login creates a separate token.
The user object excludes password hashes; IDs are strings. Roles are
`administrator`, `manager`, or `technician`.

## GET /api/v1/me

Requires bearer authorization and no request body. Returns **200 OK** with
`{"user": {...}}`, using the same user fields shown above. Account data is loaded
again on each request. The response does not include a capabilities list, and
calling this endpoint does not extend token expiry.

## POST /api/v1/logout

Requires bearer authorization and no request body. Returns **204 No Content** with
an empty body. Only the presented token is revoked. Calling `/me` or `/logout`
again with that token returns **401**. Other tokens are unaffected by this logout.

## Errors and retry behavior

| Status | Applies to | Meaning / client action |
| --- | --- | --- |
| `401` | Login | Incorrect credentials or inactive/deleted employee; show the generic error |
| `401` | Me, logout | Missing, invalid, expired, revoked, or wrong-tenant token; account inactive/deleted or password changed; require a fresh login |
| `404` | All three | Invalid host or unknown/inactive tenant; check the URL and tenant configuration |
| `422` | Login | Invalid request fields; show the field errors and correct the input |
| `429` | Login | Rate limit reached; wait the number of seconds in `Retry-After` before retrying |

Login's generic credential error is:

```json
{"message": "Invalid credentials."}
```

Protected requests normally return `{"message":"Unauthenticated."}` for 401.
Validation errors include a field-indexed `errors` object; for example, omitting
email while supplying a password:

```json
{
  "message": "The email field is required.",
  "errors": {"email": ["The email field is required."]}
}
```

Use status codes and field keys rather than matching framework error text, which
can vary. Tenant resolution happens before authentication and login throttling,
so an invalid tenant host returns 404 even when credentials or tokens are missing.
A 500 indicates a server failure, not invalid credentials; inspect server logs.

## Manual Postman walkthrough

You can import [openapi.json](openapi.json) using Postman's Import action, or create
the three requests manually. Replace the example server variables with your actual
tenant and base domain before sending any request.

For manual requests, create a local `baseUrl` variable containing your full tenant
base URL, including `/api/v1` and no trailing slash. Then:

1. Create `POST {{baseUrl}}/login`. Set Authorization to **No Auth**, add
   `Accept: application/json`, and select **Body > raw > JSON**. Enter your actual
   employee email and password. Expect **200** and copy `access_token`.
2. Create `GET {{baseUrl}}/me`. Set Authorization to **Bearer Token** and paste
   only the token value (Postman adds the `Bearer` prefix). Add the Accept header.
   Expect **200** and confirm the returned user and tenant.
3. Create `POST {{baseUrl}}/logout` with the same bearer authorization and Accept
   header, and no body. Expect **204** with an empty response.
4. Resend the `/me` request with the old token. Expect **401**.

Additional checks: `/me` without a token should return 401; a wrong password should
return 401; login without an email should return 422. Login is limited to five
attempts per tenant/email/minute and thirty per IP/minute, including successes.
Do not repeatedly retry a 429; follow `Retry-After`.

Keep real credentials and tokens out of committed collections, shared examples,
screenshots, and logs. The examples in this guide and the contract are synthetic.

## Keeping this reference current

For every endpoint addition or behavior change, update [openapi.json](openapi.json)
and the relevant Markdown guide in the same task. Include the method/path, tenant
and authentication rules, request/response schemas and examples, validation,
status codes, and any retry or token-lifecycle behavior. Check the contract against
routes, controller validation, middleware, and meaningful HTTP tests. JSON parsing
alone does not prove that the contract matches runtime behavior.

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
