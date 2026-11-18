# API families

All endpoints use `/api/v1` on the tenant subdomain, with the existing fixed
48-hour bearer authentication. IDs are decimal strings. The existing login,
logout and `/me` contracts remain unchanged; see [authentication](AUTHENTICATION.md).

Each additional family ships its own guide and standalone OpenAPI document in
`docs/openapi/`. These contracts can be imported individually into API clients.
The existing `docs/openapi.json` continues to describe the original auth routes.

Family PRs (all based on authentication #9; merge the existing documentation #8 first):

| Family | PR |
| --- | --- |
| Authentication/capabilities | [#9](https://github.com/skrall3119/HelpDesk/pull/9) |
| Organization settings | [#10](https://github.com/skrall3119/HelpDesk/pull/10) |
| Employees | [#11](https://github.com/skrall3119/HelpDesk/pull/11) |
| Clients | [#12](https://github.com/skrall3119/HelpDesk/pull/12) |
| Contacts | [#13](https://github.com/skrall3119/HelpDesk/pull/13) |
| Tickets/search/editing | [#14](https://github.com/skrall3119/HelpDesk/pull/14) |
| Assignment | [#15](https://github.com/skrall3119/HelpDesk/pull/15) |
| Status | [#16](https://github.com/skrall3119/HelpDesk/pull/16) |
| Comments/revisions | [#17](https://github.com/skrall3119/HelpDesk/pull/17) |
| History/restoration | [#18](https://github.com/skrall3119/HelpDesk/pull/18) |

## Shared contract for new endpoints

Send `Accept: application/json` and `Authorization: Bearer <token>`; mutation
bodies are JSON. Tenant identity always comes from the validated hostname.
Data responses use `{"data": ...}`. Collections use `data` plus `meta` with
`current_page`, `per_page`, `last_page`, and `total`. Page size defaults to 25,
maximum 100. Page numbers start at 1 and are capped at 1,000,000.

Updates and lifecycle actions require the exact `updated_at` timestamp obtained
from the last read. Conflicting writes return 409; reload before retrying.
Unknown submitted fields are rejected with 422. Request bodies are limited to
128 KiB. Descriptions/comments are plain text; consumers must escape them.

New-route errors use `code`, `message`, and optional field `errors`:
`401 unauthenticated`, `403 forbidden`, `404 not_found`, `409 conflict`,
`413 request_too_large`, `422 validation_failed`, `429 rate_limited`.
Unexpected failures return a generic `500 server_error`. Invalid/inactive tenant
hosts are rejected by the existing tenant middleware before route handling and
retain its existing 404 response. Cross-tenant record IDs return 404.

## GET /capabilities

Returns the authenticated employee's current capability names:

```json
{"data":["tickets.read","tickets.write","tickets.claim","tickets.status","clients.write","comments.write"]}
```

Administrator: all capabilities. Manager: all except `users.manage`,
`settings.manage`, and `comments.edit_any`. Technician: the example above.
Capabilities are UI hints; every mutation independently authorizes the actor.
Status: 200, 401, 404, 413, or 422. No parameters or request body; supplied fields are rejected.

## Owner decisions for this implementation

Confirmed November 17, 2026: API/backend only; one PR per API family; at most
three dependency layers; existing auth paths/contracts preserved. Plan defaults
for assignee eligibility, manager/admin unassignment/restoration, optimistic
concurrency, validation limits, and pagination are approved.

Password recovery/setup APIs and email delivery are deferred until frontend work.
Employee creation requires an administrator-supplied initial password of at least
eight characters. Eventual reset/setup links will expire after one/24 hours,
respectively; no such token or email feature is implemented by this task.
