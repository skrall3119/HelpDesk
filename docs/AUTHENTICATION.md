# Authentication API reference

The API uses bearer tokens with a **fixed 48-hour lifetime**. No refresh token or sliding expiry is available; an expired token requires email/password login again.

All routes use `{tenant_slug}.{TENANT_BASE_DOMAIN}/api/v1`. The host selects the tenant; submitted tenant/user IDs never select identity. Use HTTPS outside a loopback SSH tunnel. Only `Authorization: Bearer <token>` authenticates; cookies, query parameters, and request-body tokens do not. No CSRF bootstrap is needed for these header-only endpoints.

| Method | Path | Result |
| --- | --- | --- |
| POST | `/api/v1/login` | Email/password → token, token type, expiry, safe user fields |
| GET | `/api/v1/me` | Current active employee, tenant ID, and role |
| POST | `/api/v1/logout` | Revoke the presented token; return 204 |

See [openapi.json](openapi.json) for the request/response contract. IDs are JSON strings to preserve bigint precision. Unknown/inactive tenant or invalid host returns 404. Invalid credentials, missing/expired/revoked/wrong-tenant token, inactive/deleted employee, or changed password returns 401. Validation returns 422; login throttling returns 429 with Retry-After. Login limits are five attempts per tenant/email/minute and thirty per IP/minute, including successful attempts.

Tokens are opaque and contain 256 random bits. Password changes invalidate existing tokens. Inactive or deleted employees cannot authenticate with existing tokens. Logout revokes only the current token; other device tokens remain valid until expiry or account/password invalidation.

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
and token refresh are not implemented. See [API conventions](API.md#get-capabilities)
for the separate capability-discovery endpoint.

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
