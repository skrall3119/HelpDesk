# Ticket history and restoration API

Tenant-host base URL: `/api/v1`. All operations require a current bearer token.
See [shared conventions](../API.md) and [OpenAPI contract](../openapi/history.json).
IDs are strings; timestamps include fractional seconds. Send mutation bodies as JSON.

All roles read live-ticket activity. Only managers/administrators delete, inspect deleted tickets, or restore. Deleted collection is ordered by deletion timestamp then ID descending; activity is chronological by creation timestamp then ID. Deleted-ticket activity requires `deleted_ticket=1` and restoration capability; without it, deleted parents return 404.

Deletion/restoration lock the row and require its current version. Neither permanently deletes data nor changes status, assignment, comments or prior history. Restoration of a live ticket and deletion of an already deleted ticket return 404. Deleted detail supplies current contact data, including inactive historical contacts.

Activity exposes the existing database-triggered `created`/`updated` events with before/after snapshots, actor and workflow reason. Consumers derive labels from changed fields; no duplicate application events are inserted. Snapshot bigint identifiers are serialized as strings; historical legacy fields such as `resolved_at` remain readable. Snapshots may contain previous descriptions and are internal-only; never copy their content into application logs.

## GET /deleted-tickets

List soft-deleted tickets.

Success: **200**. Paginated collection; page size 25, maximum 100.

Response example:
```json
{
  "data": [
    {
      "id": "44",
      "ticket_number": "1",
      "client_id": "42",
      "contact_id": "43",
      "assigned_user_id": "7",
      "created_by_user_id": "7",
      "subject": "Network issue",
      "status": "open",
      "priority": "normal",
      "created_at": "2026-11-17T12:00:00.123456Z",
      "updated_at": "2026-11-17T12:00:00.123456Z",
      "closed_at": null,
      "deleted_at": "2026-11-17T12:00:00.123456Z"
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
## GET /deleted-tickets/{ticket}

Read a deleted ticket and its current contact.

Success: **200**.

Response example:
```json
{
  "data": {
    "id": "44",
    "ticket_number": "1",
    "client_id": "42",
    "contact_id": "43",
    "assigned_user_id": "7",
    "created_by_user_id": "7",
    "subject": "Network issue",
    "status": "open",
    "priority": "normal",
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z",
    "closed_at": null,
    "deleted_at": "2026-11-17T12:00:00.123456Z",
    "description": "Investigation",
    "contact": {
      "id": "43",
      "name": "Contact",
      "email": "contact@example.com",
      "phone": null,
      "active": true,
      "deleted_at": null
    }
  }
}
```
## DELETE /tickets/{ticket}

Soft-delete a live ticket.

Success: **204**.

Request:
```json
{
  "updated_at": "2026-11-17T12:00:00.123456Z"
}
```

- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}
## POST /tickets/{ticket}/restore

Restore a soft-deleted ticket.

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
    "id": "44",
    "ticket_number": "1",
    "client_id": "42",
    "contact_id": "43",
    "assigned_user_id": "7",
    "created_by_user_id": "7",
    "subject": "Network issue",
    "status": "open",
    "priority": "normal",
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z",
    "closed_at": null,
    "deleted_at": null,
    "description": "Investigation",
    "contact": {
      "id": "43",
      "name": "Contact",
      "email": "contact@example.com",
      "phone": null,
      "active": true,
      "deleted_at": null
    }
  }
}
```
## GET /tickets/{ticket}/activity

Read bounded ticket audit history.

Success: **200**. Paginated collection; page size 25, maximum 100.

Query parameters: `deleted_ticket`: {"type":"boolean","default":false}.

Response example:
```json
{
  "data": [
    {
      "id": "46",
      "ticket_id": "44",
      "user_id": "7",
      "activity_type": "created",
      "old_values": null,
      "new_values": {
        "id": "44",
        "ticket_number": "1",
        "client_id": "42",
        "contact_id": "43",
        "assigned_user_id": "7",
        "created_by_user_id": "7",
        "subject": "Network issue",
        "status": "open",
        "priority": "normal",
        "created_at": "2026-11-17T12:00:00.123456Z",
        "updated_at": "2026-11-17T12:00:00.123456Z",
        "closed_at": null,
        "deleted_at": null
      },
      "status_reason": null,
      "created_at": "2026-11-17T12:00:00.123456Z"
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

## Errors

401 authentication failure; 403 insufficient capability; 404 missing/cross-tenant resource;
409 stale version or business invariant conflict; 413 oversized body; 422 validation
(including forbidden fields); 500 generic internal failure. Errors use `code`, `message`,
and optional field `errors`, except pre-route tenant-host failures retain the existing
tenant 404 shape. A conflict never silently overwrites the concurrent change.

```json
{"code":"validation_failed","message":"The given data was invalid.","errors":{"name":["The name field is required."]}}
```
