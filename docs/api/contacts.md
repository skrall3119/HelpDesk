# Contacts API

Tenant-host base URL: `/api/v1`. All operations require a current bearer token.
See [shared conventions](../API.md) and [OpenAPI contract](../openapi/contacts.json).
IDs are strings; timestamps include fractional seconds. Send mutation bodies as JSON.

All roles can read/create/edit contacts and explicitly change primary contact. Only managers/administrators deactivate/restore. Contact IDs must belong to the path client and tenant; mismatches return 404. Contacts cannot be moved between clients. Inactive contacts remain readable; `active=1` supplies lookup options. Lists sort primary first, then name and ID; `q` is literal case-insensitive name search.

A contact always requires a nonblank name and email or phone. Patches validate the resulting merged record, so clearing the final contact method fails. Phone formatting is preserved. Contact creation is non-primary; use the explicit primary action. Primary changes lock the parent client, require both contact `updated_at` and `client_updated_at`, demote the previous primary and promote the selected active contact atomically. Reload the client afterward to obtain its new timestamp. Never deactivate the primary before explicitly choosing its replacement. Restoration rechecks primary uniqueness. Every mutation locks the parent before the contact, serializing concurrent lifecycle changes.

## GET /clients/{client}/contacts

List contacts and dependent lookup options.

Success: **200**. Paginated collection; page size 25, maximum 100.

Query parameters: `q`: {"type":"string","minLength":1,"maxLength":255}; `active`: {"type":"boolean"}.

Response example:
```json
{
  "data": [
    {
      "id": "43",
      "client_id": "42",
      "name": "Example contact",
      "email": "contact@example.com",
      "phone": null,
      "job_title": null,
      "is_primary": false,
      "active": true,
      "created_at": "2026-11-17T12:00:00.123456Z",
      "updated_at": "2026-11-17T12:00:00.123456Z"
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
## POST /clients/{client}/contacts

Create a contact.

Success: **201**.

Request:
```json
{
  "name": "Example contact",
  "email": "contact@example.com"
}
```

- `name`: required; {"type":"string","minLength":1,"maxLength":255}
- `email`: optional; {"anyOf":[{"type":"string","format":"email","maxLength":254},{"type":"null"}]}
- `phone`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":50},{"type":"null"}]}
- `job_title`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":255},{"type":"null"}]}

Response example:
```json
{
  "data": {
    "id": "43",
    "client_id": "42",
    "name": "Example contact",
    "email": "contact@example.com",
    "phone": null,
    "job_title": null,
    "is_primary": false,
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z"
  }
}
```
## GET /clients/{client}/contacts/{contact}

Read current contact details.

Success: **200**.

Response example:
```json
{
  "data": {
    "id": "43",
    "client_id": "42",
    "name": "Example contact",
    "email": "contact@example.com",
    "phone": null,
    "job_title": null,
    "is_primary": false,
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z"
  }
}
```
## PATCH /clients/{client}/contacts/{contact}

Edit contact details.

Success: **200**.

Request:
```json
{
  "phone": "555-0100",
  "updated_at": "2026-11-17T12:00:00.123456Z"
}
```

- `name`: optional; {"type":"string","minLength":1,"maxLength":255}
- `email`: optional; {"anyOf":[{"type":"string","format":"email","maxLength":254},{"type":"null"}]}
- `phone`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":50},{"type":"null"}]}
- `job_title`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":255},{"type":"null"}]}
- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}

Response example:
```json
{
  "data": {
    "id": "43",
    "client_id": "42",
    "name": "Example contact",
    "email": "contact@example.com",
    "phone": null,
    "job_title": null,
    "is_primary": false,
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z"
  }
}
```
## POST /clients/{client}/contacts/{contact}/primary

primary contact action.

Success: **200**.

Request:
```json
{
  "updated_at": "2026-11-17T12:00:00.123456Z",
  "client_updated_at": "2026-11-17T12:00:00.123456Z"
}
```

- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}
- `client_updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}

Response example:
```json
{
  "data": {
    "id": "43",
    "client_id": "42",
    "name": "Example contact",
    "email": "contact@example.com",
    "phone": null,
    "job_title": null,
    "is_primary": false,
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z"
  }
}
```
## POST /clients/{client}/contacts/{contact}/deactivate

deactivate contact action.

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
    "id": "43",
    "client_id": "42",
    "name": "Example contact",
    "email": "contact@example.com",
    "phone": null,
    "job_title": null,
    "is_primary": false,
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z"
  }
}
```
## POST /clients/{client}/contacts/{contact}/restore

restore contact action.

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
    "id": "43",
    "client_id": "42",
    "name": "Example contact",
    "email": "contact@example.com",
    "phone": null,
    "job_title": null,
    "is_primary": false,
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
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
