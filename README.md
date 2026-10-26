# CaseLantern

CaseLantern is a multi-tenant support ticket application for technical support organizations. Internal teams manage clients and contacts, claim and assign tickets, record investigation notes, and track issues through resolution.

## Project status

Product planning and the initial PostgreSQL schema are in place. The Laravel application has not been scaffolded yet.

- [Project plan and implementation roadmap](docs/PROJECT_PLAN.md)
- [Database setup, security model, and schema decisions](database/README.md)
- [Initial PostgreSQL schema](database/migrations/001_initial.sql)
- [Database checks](database/tests/schema.sql)

## Naming

- Product/display name: **CaseLantern**
- Machine-readable project name: `caselantern`
- Proposed ticket display format: `CL-000001` (numbers remain integers in PostgreSQL)
- Fresh-install database: `caselantern`
- Fresh-install database roles: `caselantern_migrator` and `caselantern_app`

Existing database/account names from the initial setup remain valid; see the database naming notes before changing connection settings.

The existing GitHub repository is [skrall3119/HelpDesk](https://github.com/skrall3119/HelpDesk). Its remote URL and the local checkout directory have not been renamed by the product branding update.
