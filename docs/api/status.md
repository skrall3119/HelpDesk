# Ticket status API

Tenant-host base URL: `/api/v1`. All operations require a current bearer token.
See [shared conventions](../API.md) and [OpenAPI contract](../openapi/status.json).
IDs are strings; timestamps include fractional seconds. Send mutation bodies as JSON.

All three roles (`tickets.status`) may change any live tenant ticket status. The ticket is locked and its version checked; closing requires an assignee. Status changes preserve assignment. Entering On Hold or Closed, or reopening Closed, requires a nonblank reason of at most 2,000 characters. A same-status request succeeds without changing timestamps or creating another event.

| From | Allowed next statuses |
| --- | --- |
| Open | In Progress, Waiting on Client, On Hold, Closed |
| In Progress | Waiting on Client, On Hold, Closed |
| Waiting on Client | In Progress, On Hold, Closed |
| On Hold | In Progress, Waiting on Client, Closed |
| Closed | In Progress |

No transition returns to Open. Closing sets `closed_at`; reopening clears it while prior closures remain in activity. Invalid transitions, missing reasons, and closure without assignment return 422. Stale updates return 409. Creator, assignment, completion timestamps and other fields cannot be supplied here.

## POST /tickets/{ticket}/status

Change ticket status with a workflow reason.

Success: **200**.

Request:
```json
{
  "status": "closed",
  "reason": "Replaced failed network cable",
  "updated_at": "2026-11-17T12:00:00.123456Z"
}
```

- `status`: required; {"type":"string","enum":["open","in_progress","waiting_on_client","on_hold","closed"]}
- `reason`: optional; {"type":"string","minLength":1,"maxLength":2000}
- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}

Response example:
```json
{
  "data": {
    "id": "44",
    "status": "closed",
    "assigned_user_id": "7",
    "closed_at": "2026-11-17T12:00:00.123456Z",
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
