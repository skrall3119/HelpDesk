# Ticket collection and editing API

Tenant-host base URL: `/api/v1`. All operations require a current bearer token.
See [shared conventions](../API.md) and [OpenAPI contract](../openapi/tickets.json).
IDs are strings; timestamps include fractional seconds. Send mutation bodies as JSON.

All three roles read/create/edit tickets. Ticket creation starts Open with Normal priority unless a different priority is supplied. A technician can initially assign only themselves; assigning another employee requires manager/admin capability. Generic updates reject status, assignment, creator, tenant, identity, number and completion timestamps.

New or changed client/contact relationships must be active, live, in this tenant and mutually matching. Changing client requires an explicit contact in the same request. Unrelated edits preserve historical inactive relationships. Detail always returns current contact information. Descriptions are plain text, max 50,000 characters; subject max 255. Creation/meaningful updates produce one database-triggered activity entry.

Dashboard filters combine with AND. Defaults hide On Hold, Closed and deleted tickets. `status=all` includes all live statuses; deleted records remain separate. `view=my` restricts to current employee; `unassigned` requires no assignee. `q` recognizes an exact positive ticket number or CL-prefixed number; otherwise PostgreSQL English full-text search covers subject and description only, not comments or arbitrary substrings. Ordering is creation time then ID, descending by default or ascending for `oldest`. Lists omit descriptions and nested details to bound payloads.

## GET /tickets

Search and filter the ticket dashboard.

Success: **200**. Paginated collection; page size 25, maximum 100.

Query parameters: `view`: {"type":"string","enum":["all","my","unassigned"],"default":"all"}; `status`: {"type":"string","enum":["open","in_progress","waiting_on_client","on_hold","closed","all"]}; `priority`: {"type":"string","enum":["low","normal","high","critical"]}; `client_id`: {"type":"string","pattern":"^[1-9][0-9]*$","example":"42"}; `assigned_user_id`: {"type":"string","pattern":"^[1-9][0-9]*$","example":"42"}; `q`: {"type":"string","minLength":1,"maxLength":255}; `sort`: {"type":"string","enum":["newest","oldest"],"default":"newest"}.

Response example:
```json
{
  "data": [
    {
      "id": "44",
      "ticket_number": "1",
      "client_id": "42",
      "contact_id": "43",
      "assigned_user_id": null,
      "created_by_user_id": "7",
      "subject": "Network issue",
      "status": "open",
      "priority": "normal",
      "created_at": "2026-11-17T12:00:00.123456Z",
      "updated_at": "2026-11-17T12:00:00.123456Z",
      "closed_at": null
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
## POST /tickets

Create an open ticket.

Success: **201**.

Request:
```json
{
  "client_id": "42",
  "contact_id": "43",
  "subject": "Network issue",
  "description": "Router cannot connect"
}
```

- `client_id`: required; {"type":"string","pattern":"^[1-9][0-9]*$","example":"42"}
- `contact_id`: required; {"type":"string","pattern":"^[1-9][0-9]*$","example":"42"}
- `subject`: required; {"type":"string","minLength":1,"maxLength":255}
- `description`: required; {"type":"string","minLength":1,"maxLength":50000}
- `priority`: optional; {"type":"string","enum":["low","normal","high","critical"]}
- `assigned_user_id`: optional; {"anyOf":[{"type":"string","pattern":"^[1-9][0-9]*$","example":"42"},{"type":"null"}]}

Response example:
```json
{
  "data": {
    "id": "44",
    "ticket_number": "1",
    "client_id": "42",
    "contact_id": "43",
    "assigned_user_id": null,
    "created_by_user_id": "7",
    "subject": "Network issue",
    "status": "open",
    "priority": "normal",
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z",
    "closed_at": null,
    "description": "Router cannot connect",
    "client": {
      "id": "42",
      "name": "Example client",
      "active": true,
      "deleted_at": null
    },
    "contact": {
      "id": "43",
      "name": "Example contact",
      "email": "contact@example.com",
      "phone": null,
      "job_title": null,
      "active": true,
      "deleted_at": null
    }
  }
}
```
## GET /tickets/{ticket}

Read ticket and current contact information.

Success: **200**.

Response example:
```json
{
  "data": {
    "id": "44",
    "ticket_number": "1",
    "client_id": "42",
    "contact_id": "43",
    "assigned_user_id": null,
    "created_by_user_id": "7",
    "subject": "Network issue",
    "status": "open",
    "priority": "normal",
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z",
    "closed_at": null,
    "description": "Router cannot connect",
    "client": {
      "id": "42",
      "name": "Example client",
      "active": true,
      "deleted_at": null
    },
    "contact": {
      "id": "43",
      "name": "Example contact",
      "email": "contact@example.com",
      "phone": null,
      "job_title": null,
      "active": true,
      "deleted_at": null
    }
  }
}
```
## PATCH /tickets/{ticket}

Edit ticket content, priority or client/contact relationship.

Success: **200**.

Request:
```json
{
  "description": "Corrected investigation description",
  "updated_at": "2026-11-17T12:00:00.123456Z"
}
```

- `client_id`: optional; {"type":"string","pattern":"^[1-9][0-9]*$","example":"42"}
- `contact_id`: optional; {"type":"string","pattern":"^[1-9][0-9]*$","example":"42"}
- `subject`: optional; {"type":"string","minLength":1,"maxLength":255}
- `description`: optional; {"type":"string","minLength":1,"maxLength":50000}
- `priority`: optional; {"type":"string","enum":["low","normal","high","critical"]}
- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}

Response example:
```json
{
  "data": {
    "id": "44",
    "ticket_number": "1",
    "client_id": "42",
    "contact_id": "43",
    "assigned_user_id": null,
    "created_by_user_id": "7",
    "subject": "Network issue",
    "status": "open",
    "priority": "normal",
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z",
    "closed_at": null,
    "description": "Router cannot connect",
    "client": {
      "id": "42",
      "name": "Example client",
      "active": true,
      "deleted_at": null
    },
    "contact": {
      "id": "43",
      "name": "Example contact",
      "email": "contact@example.com",
      "phone": null,
      "job_title": null,
      "active": true,
      "deleted_at": null
    }
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
