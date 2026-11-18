# CaseLantern

Our private multi-tenant help desk application. Each organization uses its own
subdomain and employee accounts. V1 covers clients/contacts, tickets, internal
comments, and history; categories and reports are deferred.

The Laravel/PostgreSQL foundation, tenant provisioning, and bearer authentication
API are implemented. Browser login and help desk screens remain future work.

## Documentation

- [Authentication API reference](docs/AUTHENTICATION.md): endpoints, examples,
  token behavior, errors, and manual testing in Postman.
- [OpenAPI contract](docs/openapi.json): machine-readable API definitions for client tools.
- [API families and conventions](docs/API.md): capabilities, approved backend scope,
  and the shared contract for the family-specific guides and OpenAPI documents.
- [Database reference](database/README.md): schema, tenant isolation, and query invariants.
- [Project plan](docs/PROJECT_PLAN.md) and [sprint schedule](docs/SPRINT_SCHEDULE.md):
  product decisions, acceptance gates, and planned work. Consult their current
  GitHub versions when planning implementation.

API changes include documentation updates in the same task. Server procedures are maintained separately in an untracked local runbook.
