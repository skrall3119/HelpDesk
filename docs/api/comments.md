# Internal comments and revisions API

Tenant-host base URL: `/api/v1`. All operations require a current bearer token.
See [shared conventions](../API.md) and [OpenAPI contract](../openapi/comments.json).
IDs are strings; timestamps include fractional seconds. Send mutation bodies as JSON.

All roles read/add internal comments. Authors edit their own comments; administrators may edit any tenant comment. Managers may not edit another author's text. Managers/administrators may soft-delete. Bodies are nonblank escaped-by-consumer plain text, maximum 20,000 characters. The API returns plain text, not rendered HTML. No public comments, notifications, restore-comment action or hard deletion.

Edits lock ticket then comment, compare version, append the previous body/editor/original timestamp, and update atomically. An unchanged body produces no revision. Revision rows are append-only with forced tenant RLS; current text remains on the comment. Soft deletion retains text/revisions and records the deleting employee.

Timeline and revisions sort chronologically by creation time then ID. Normal reads exclude deleted comments. `deleted=1` selects only deleted comments and requires manager/admin moderation. `deleted_ticket=1` explicitly accesses a deleted parent ticket and requires restoration capability. Both flags may be combined. Writes always require a live parent ticket. A comment under a different ticket or tenant is 404.

## GET /tickets/{ticket}/comments

Read the bounded comment timeline.

Success: **200**. Paginated collection; page size 25, maximum 100.

Query parameters: `deleted`: {"type":"boolean","default":false}; `deleted_ticket`: {"type":"boolean","default":false}.

Response example:
```json
{
  "data": [
    {
      "id": "45",
      "ticket_id": "44",
      "user_id": "7",
      "body": "Investigation notes",
      "created_at": "2026-11-17T12:00:00.123456Z",
      "updated_at": "2026-11-17T12:00:00.123456Z",
      "deleted_at": null,
      "deleted_by_user_id": null
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
## POST /tickets/{ticket}/comments

Add an internal comment.

Success: **201**.

Request:
```json
{
  "body": "Investigation notes"
}
```

- `body`: required; {"type":"string","minLength":1,"maxLength":20000}

Response example:
```json
{
  "data": {
    "id": "45",
    "ticket_id": "44",
    "user_id": "7",
    "body": "Investigation notes",
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z",
    "deleted_at": null,
    "deleted_by_user_id": null
  }
}
```
## GET /tickets/{ticket}/comments/{comment}

Read a comment.

Success: **200**.

Query parameters: `deleted`: {"type":"boolean","default":false}; `deleted_ticket`: {"type":"boolean","default":false}.

Response example:
```json
{
  "data": {
    "id": "45",
    "ticket_id": "44",
    "user_id": "7",
    "body": "Investigation notes",
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z",
    "deleted_at": null,
    "deleted_by_user_id": null
  }
}
```
## PATCH /tickets/{ticket}/comments/{comment}

Edit a comment while preserving its previous body.

Success: **200**.

Request:
```json
{
  "body": "Updated notes",
  "updated_at": "2026-11-17T12:00:00.123456Z"
}
```

- `body`: required; {"type":"string","minLength":1,"maxLength":20000}
- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}

Response example:
```json
{
  "data": {
    "id": "45",
    "ticket_id": "44",
    "user_id": "7",
    "body": "Updated notes",
    "created_at": "2026-11-17T12:00:00.123456Z",
    "updated_at": "2026-11-17T12:00:00.123456Z",
    "deleted_at": null,
    "deleted_by_user_id": null
  }
}
```
## DELETE /tickets/{ticket}/comments/{comment}

Soft-delete a comment as manager or administrator.

Success: **204**.

Request:
```json
{
  "updated_at": "2026-11-17T12:00:00.123456Z"
}
```

- `updated_at`: required; {"type":"string","format":"date-time","example":"2026-11-17T12:00:00.123456Z"}
## GET /tickets/{ticket}/comments/{comment}/revisions

Read retained previous comment bodies.

Success: **200**. Paginated collection; page size 25, maximum 100.

Query parameters: `deleted`: {"type":"boolean","default":false}; `deleted_ticket`: {"type":"boolean","default":false}.

Response example:
```json
{
  "data": [
    {
      "id": "46",
      "comment_id": "45",
      "editor_user_id": "7",
      "body": "Original notes",
      "original_updated_at": "2026-11-17T12:00:00.123456Z",
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
