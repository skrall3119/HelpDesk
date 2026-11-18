# Ticket assignment API

Tenant-host base URL: `/api/v1`. All operations require a current bearer token.
See [shared conventions](../API.md) and [OpenAPI contract](../openapi/assignment.json).
IDs are strings; timestamps include fractional seconds. Send mutation bodies as JSON.

All three active roles are eligible assignees and may self-claim. Claim derives the assignee from authentication, accepts no actor or tenant fields, and uses a conditional UPDATE; only one claimant wins. A loser receives 409 with the current `assigned_user_id`. Claims do not require a previously loaded timestamp.

Only managers/administrators (`tickets.assign`) can assign/reassign/unassign. Assignment targets must be active, live and in the tenant. Assignment does not alter status. Unassignment of a closed ticket requires a nonblank `reopen_reason` (max 2,000 characters) and atomically reopens it to In Progress before clearing assignment; closed tickets never become unassigned. Each assignment mutation is audited once by the database. Lookup results expose only ID/name/role and sort by name then ID.

## GET /assignees

List active eligible assignees.

Success: **200**. Paginated collection; page size 25, maximum 100.

Response example:
```json
{
  "data": [
    {
      "id": "7",
      "name": "Example employee",
      "role": "technician"
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
## POST /tickets/{ticket}/claim

Atomically claim an unassigned ticket for the current employee.

Success: **200**.

Request:
```json
{}
```



Response example:
```json
{
  "data": {
    "id": "44",
    "assigned_user_id": "7",
    "status": "open",
    "closed_at": null,
    "updated_at": "2026-11-17T12:00:00.123456Z"
  }
}
```
## POST /tickets/{ticket}/assignment

Assign or reassign a ticket as manager or administrator.

Success: **200**.

Request:
```json
{
  "assigned_user_id": "7",
  "updated_at": "2026-11-17T12:00:00.123456Z"
}
```

- `assigned_user_id`: required; {"type":"string","pattern":"^[1-9][0-9]*$","example":"42"}
- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}

Response example:
```json
{
  "data": {
    "id": "44",
    "assigned_user_id": "7",
    "status": "open",
    "closed_at": null,
    "updated_at": "2026-11-17T12:00:00.123456Z"
  }
}
```
## POST /tickets/{ticket}/unassign

Unassign a ticket as manager or administrator.

Success: **200**.

Request:
```json
{
  "updated_at": "2026-11-17T12:00:00.123456Z",
  "reopen_reason": "Further investigation needed"
}
```

- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}
- `reopen_reason`: optional; {"type":"string","minLength":1,"maxLength":2000}

Response example:
```json
{
  "data": {
    "id": "44",
    "assigned_user_id": null,
    "status": "in_progress",
    "closed_at": null,
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
