# Clients API

Tenant-host base URL: `/api/v1`. All operations require a current bearer token.
See [shared conventions](../API.md) and [OpenAPI contract](../openapi/clients.json).
IDs are strings; timestamps include fractional seconds. Send mutation bodies as JSON.

All three roles can read/create/edit clients. Only managers/administrators can deactivate or restore. Deactivation uses `active=false`, preserves contacts/tickets, and does not hard-delete data. Existing inactive clients remain readable. Restoration requires exactly one active primary contact. Lists sort by name then ID; `q` is a literal case-insensitive name search. Use `active=1` for new-ticket lookup options.

Creation requires a primary contact with a nonblank name and at least one email/phone. The client and contact share one transaction. Names/address fields max 255, email max 254, phone max 50, postal code max 32. Country uses the application ISO alpha-2 allowlist. Optional contact/address fields may be null. Phone/postal formatting is preserved. Unknown fields, nested contact identity/primary flags, blank required strings and invalid emails are rejected.

Forward migration adds a NOT VALID contact-method constraint: historical records remain intact, while every new or changed contact must satisfy it. Existing invalid contacts need an explicit data correction before the constraint can be validated globally; no arbitrary historical data is fabricated. The existing partial unique index and serialized contact actions protect primary-contact changes.

## GET /clients

List or look up clients.

Success: **200**. Paginated collection; page size 25, maximum 100.

Query parameters: `q`: {"type":"string","minLength":1,"maxLength":255}; `active`: {"type":"boolean"}; `client_type`: {"type":"string","enum":["individual","business"]}.

Response example:
```json
{
  "data": [
    {
      "id": "42",
      "name": "Example business",
      "client_type": "business",
      "email": null,
      "phone": null,
      "address_line_1": null,
      "address_line_2": null,
      "city": null,
      "state": null,
      "postal_code": null,
      "country": null,
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
## POST /clients

Create client and primary contact atomically.

Success: **201**.

Request:
```json
{
  "name": "Example business",
  "client_type": "business",
  "primary_contact": {
    "name": "Primary person",
    "email": "primary@example.com"
  }
}
```

- `name`: required; {"type":"string","minLength":1,"maxLength":255}
- `client_type`: required; {"type":"string","enum":["individual","business"]}
- `email`: optional; {"anyOf":[{"type":"string","format":"email","maxLength":254},{"type":"null"}]}
- `phone`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":50},{"type":"null"}]}
- `address_line_1`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":255},{"type":"null"}]}
- `address_line_2`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":255},{"type":"null"}]}
- `city`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":255},{"type":"null"}]}
- `state`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":255},{"type":"null"}]}
- `postal_code`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":32},{"type":"null"}]}
- `country`: optional; {"anyOf":[{"type":"string","pattern":"^[A-Z]{2}$","description":"Assigned ISO 3166-1 alpha-2 code"},{"type":"null"}]}
- `primary_contact`: required; {"type":"object","additionalProperties":false,"properties":{"name":{"type":"string","minLength":1,"maxLength":255},"email":{"anyOf":[{"type":"string","format":"email","maxLength":254},{"type":"null"}]},"phone":{"anyOf":[{"type":"string","minLength":1,"maxLength":50},{"type":"null"}]},"job_title":{"anyOf":[{"type":"string","minLength":1,"maxLength":255},{"type":"null"}]}},"required":["name"],"anyOf":[{"required":["email"],"properties":{"email":{"type":"string","minLength":1}}},{"required":["phone"],"properties":{"phone":{"type":"string","minLength":1}}}]}

Response example:
```json
{
  "data": {
    "id": "42",
    "name": "Example business",
    "client_type": "business",
    "email": null,
    "phone": null,
    "address_line_1": null,
    "address_line_2": null,
    "city": null,
    "state": null,
    "postal_code": null,
    "country": null,
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z"
  }
}
```
## GET /clients/{client}

Read a client including current address.

Success: **200**.

Response example:
```json
{
  "data": {
    "id": "42",
    "name": "Example business",
    "client_type": "business",
    "email": null,
    "phone": null,
    "address_line_1": null,
    "address_line_2": null,
    "city": null,
    "state": null,
    "postal_code": null,
    "country": null,
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z"
  }
}
```
## PATCH /clients/{client}

Edit client details.

Success: **200**.

Request:
```json
{
  "name": "Updated business",
  "updated_at": "2026-11-17T12:00:00.123456Z"
}
```

- `name`: optional; {"type":"string","minLength":1,"maxLength":255}
- `client_type`: optional; {"type":"string","enum":["individual","business"]}
- `email`: optional; {"anyOf":[{"type":"string","format":"email","maxLength":254},{"type":"null"}]}
- `phone`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":50},{"type":"null"}]}
- `address_line_1`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":255},{"type":"null"}]}
- `address_line_2`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":255},{"type":"null"}]}
- `city`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":255},{"type":"null"}]}
- `state`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":255},{"type":"null"}]}
- `postal_code`: optional; {"anyOf":[{"type":"string","minLength":1,"maxLength":32},{"type":"null"}]}
- `country`: optional; {"anyOf":[{"type":"string","pattern":"^[A-Z]{2}$","description":"Assigned ISO 3166-1 alpha-2 code"},{"type":"null"}]}
- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}

Response example:
```json
{
  "data": {
    "id": "42",
    "name": "Example business",
    "client_type": "business",
    "email": null,
    "phone": null,
    "address_line_1": null,
    "address_line_2": null,
    "city": null,
    "state": null,
    "postal_code": null,
    "country": null,
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z"
  }
}
```
## POST /clients/{client}/deactivate

deactivate client.

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
    "name": "Example business",
    "client_type": "business",
    "email": null,
    "phone": null,
    "address_line_1": null,
    "address_line_2": null,
    "city": null,
    "state": null,
    "postal_code": null,
    "country": null,
    "active": true,
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z"
  }
}
```
## POST /clients/{client}/restore

restore client.

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
    "name": "Example business",
    "client_type": "business",
    "email": null,
    "phone": null,
    "address_line_1": null,
    "address_line_2": null,
    "city": null,
    "state": null,
    "postal_code": null,
    "country": null,
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
