# Organization settings API

Tenant-host base URL: `/api/v1`. All operations require a current bearer token.
See [shared conventions](../API.md) and [OpenAPI contract](../openapi/settings.json).
IDs are strings; timestamps include fractional seconds. Send mutation bodies as JSON.

Administrator only (`settings.manage`). Only the display name is editable. Slug, active state, tenant identity, and ticket counter remain controlled by provisioning. Updates lock the tenant and compare `updated_at` before writing. Whitespace-only names and names longer than 255 characters are invalid.

## GET /admin/settings

Read organization display name.

Success: **200**.

Response example:
```json
{
  "data": {
    "id": "7",
    "name": "Example support",
    "updated_at": "2026-11-17T12:00:00.123456Z"
  }
}
```
## PATCH /admin/settings

Change organization display name.

Success: **200**.

Request:
```json
{
  "name": "Updated support",
  "updated_at": "2026-11-17T12:00:00.123456Z"
}
```

- `name`: required; {"type":"string","minLength":1,"maxLength":255}
- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}

Response example:
```json
{
  "data": {
    "id": "7",
    "name": "Updated support",
    "updated_at": "2026-11-17T12:00:00.123456Z"
  }
}
```

## Errors

401 authentication failure; 403 insufficient capability; 404 missing/cross-tenant resource;
409 stale version or business invariant conflict; 413 oversized body; 422 validation
(including forbidden fields); 500 generic internal failure. Errors use `code`, `message`,
and optional field `errors`, except pre-route tenant-host failures retain the existing
tenant 404 shape. A conflict never silently overwrites the concurrent change.

```json
{"code":"validation_failed","message":"The given data was invalid.","errors":{"name":["The name field is required."]}}
```
