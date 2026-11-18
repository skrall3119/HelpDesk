# Employee administration API

Tenant-host base URL: `/api/v1`. All operations require a current bearer token.
See [shared conventions](../API.md) and [OpenAPI contract](../openapi/employees.json).
IDs are strings; timestamps include fractional seconds. Send mutation bodies as JSON.

Administrator only (`users.manage`). Names are nonblank (max 255); emails are tenant-unique and case-insensitive (max 254), including deleted users. Passwords require eight characters and at most 72 UTF-8 bytes; passwords cannot be changed through generic edits. New employees are active. Recovery/setup/email is deferred. Lists sort by name then ID; `q` matches name/email literally, case-insensitively. `deleted=1` selects only deleted employees.

All employee writes lock the tenant before checking invariants. The last active administrator cannot be demoted, deactivated, or deleted (409). Deactivation and soft deletion revoke all bearer tokens and preserve attribution/assignments. Restoration keeps the email reserved and leaves the account inactive until a separate activation. Generic edits reject password, active, tenant and identity fields.

## GET /admin/users

List employees.

Success: **200**. Paginated collection; page size 25, maximum 100.

Query parameters: `q`: {"type":"string","minLength":1,"maxLength":255}; `active`: {"type":"boolean"}; `deleted`: {"type":"boolean","default":false}.

Response example:
```json
{
  "data": [
    {
      "id": "42",
      "name": "Example employee",
      "email": "employee@example.com",
      "role": "technician",
      "active": true,
      "created_at": "2026-11-17T12:00:00.123456Z",
      "updated_at": "2026-11-17T12:00:00.123456Z",
      "deleted_at": null
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 1,
    "last_page": 1
  }
}
```
## POST /admin/users

Create an employee with an initial password.

Success: **201**.

Request:
```json
{
  "name": "Example employee",
  "email": "employee@example.com",
  "role": "technician",
  "password": "Example123!"
}
```

- `name`: required; {"type":"string","minLength":1,"maxLength":255}
- `email`: required; {"type":"string","format":"email","maxLength":254}
- `role`: required; {"type":"string","enum":["administrator","manager","technician"]}
- `password`: required; {"type":"string","minLength":8,"maxLength":72,"writeOnly":true,"description":"Maximum 72 UTF-8 bytes"}

Response example:
```json
{
  "data": {
    "id": "42",
    "name": "Example employee",
    "email": "employee@example.com",
    "role": "technician",
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z",
    "deleted_at": null
  }
}
```
## GET /admin/users/{user}

Read an employee.

Success: **200**.

Query parameters: `deleted`: {"type":"boolean","default":false}.

Response example:
```json
{
  "data": {
    "id": "42",
    "name": "Example employee",
    "email": "employee@example.com",
    "role": "technician",
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z",
    "deleted_at": null
  }
}
```
## PATCH /admin/users/{user}

Update employee name, email or role.

Success: **200**.

Request:
```json
{
  "name": "Updated employee",
  "updated_at": "2026-11-17T12:00:00.123456Z"
}
```

- `name`: optional; {"type":"string","minLength":1,"maxLength":255}
- `email`: optional; {"type":"string","format":"email","maxLength":254}
- `role`: optional; {"type":"string","enum":["administrator","manager","technician"]}
- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}

Response example:
```json
{
  "data": {
    "id": "42",
    "name": "Example employee",
    "email": "employee@example.com",
    "role": "technician",
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z",
    "deleted_at": null
  }
}
```
## POST /admin/users/{user}/activate

activate employee.

Success: **200**.

Request:
```json
{
  "updated_at": "2026-11-17T12:00:00.123456Z"
}
```

- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}

Response example:
```json
{
  "data": {
    "id": "42",
    "name": "Example employee",
    "email": "employee@example.com",
    "role": "technician",
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z",
    "deleted_at": null
  }
}
```
## POST /admin/users/{user}/deactivate

deactivate employee.

Success: **200**.

Request:
```json
{
  "updated_at": "2026-11-17T12:00:00.123456Z"
}
```

- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}

Response example:
```json
{
  "data": {
    "id": "42",
    "name": "Example employee",
    "email": "employee@example.com",
    "role": "technician",
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z",
    "deleted_at": null
  }
}
```
## POST /admin/users/{user}/restore

restore employee.

Success: **200**.

Request:
```json
{
  "updated_at": "2026-11-17T12:00:00.123456Z"
}
```

- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}

Response example:
```json
{
  "data": {
    "id": "42",
    "name": "Example employee",
    "email": "employee@example.com",
    "role": "technician",
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z",
    "deleted_at": null
  }
}
```
## DELETE /admin/users/{user}

delete employee.

Success: **204**.

Request:
```json
{
  "updated_at": "2026-11-17T12:00:00.123456Z"
}
```

- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}

## Errors

401 authentication failure; 403 insufficient capability; 404 missing/cross-tenant resource;
409 stale version or business invariant conflict; 413 oversized body; 422 validation
(including forbidden fields); 500 generic internal failure. Errors use `code`, `message`,
and optional field `errors`, except pre-route tenant-host failures retain the existing
tenant 404 shape. A conflict never silently overwrites the concurrent change.

```json
{"code":"validation_failed","message":"The given data was invalid.","errors":{"name":["The name field is required."]}}
```
