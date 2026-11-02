# CaseLantern

A multi-tenant help desk for internal support teams. Each organization uses its
own subdomain and separate employee accounts. Clients/contacts, tickets, internal
comments, and history form the V1 product; categories and reports are deferred.

## Current state

The Laravel scaffold, PostgreSQL baseline/forward migration, restricted runtime
connection, asset build, and tenant/admin provisioning are implemented and tested
on PostgreSQL 18.6. Browser
authentication and the help desk screens are not implemented yet.

The foundation has been merged to `main`. Work continues through small branches
and reviewed pull requests. Brief implementation updates and verification links
are maintained in the [sprint schedule](docs/SPRINT_SCHEDULE.md#progress).

## Start here

- [Development setup: fresh checkout and existing server](docs/DEVELOPMENT.md)
- [Tenant/admin provisioning](docs/TENANT_PROVISIONING.md)
- [Product rules and implementation plan](docs/PROJECT_PLAN.md)
- [Daily sprint schedule](docs/SPRINT_SCHEDULE.md)
- [Database design and SQL checks](database/README.md)

Verified server toolchain: PHP 8.5.12, Composer 2.10.3, Node 22.23.1/npm 10.9.8,
Laravel 13.39.0, PostgreSQL 18.6. Dependencies are committed in Composer/npm lockfiles.
CI uses PHP 8.5, Node 22, and PostgreSQL 18 on an isolated runner.

## Everyday development

From an already-configured checkout:

```bash
composer install
npm ci
npm run build
php artisan test
php artisan serve --host=127.0.0.1 --port=8000
```

On Windows, forward port 8000 using the SSH identity you already use for your
development server, then open `http://localhost:8000`:

```powershell
ssh -i "$env:USERPROFILE\.ssh\YOUR_SERVER_KEY" -o IdentitiesOnly=yes -N -L 8000:127.0.0.1:8000 YOUR_USER@YOUR_SERVER
```

The tunnel intentionally stays open without a shell. Keep both processes running.
Tenant hostnames such as `acme.localhost:8000` become useful once tenant routing
is implemented; a welcome-page response today is not evidence of tenant login.

## Checks

```bash
vendor/bin/pint --test app tests
php artisan test
npm run build
```

The default suite skips real PostgreSQL tests when its dedicated test database is
not configured. Follow [the development guide](docs/DEVELOPMENT.md#postgresql-tests)
to run them with separate owner/runtime roles. CI configures them and uses
`--fail-on-skipped`, so skipped integration tests cannot produce a green build.

## Database safety

- Web requests use `caselantern_app`; migrations/provisioning use the separate
  `pgsql_migrator` connection with process-scoped credentials.
- Never run `migrate:fresh`, rollback the applied baseline, or recreate
  existing tenants as part of setup.
- Never commit `.env`, passwords, private keys, or personal server helper files.
- `composer setup` installs/builds dependencies only; it does not generate/rotate
  application keys or apply migrations. Finish the explicit configuration steps
  in the guide. Do not run `composer create-project` inside an existing checkout.

Repository: [skrall3119/HelpDesk](https://github.com/skrall3119/HelpDesk).
Product name: **CaseLantern**; machine name: `caselantern`. Ticket numbers remain
integers; `CL-000001` is their proposed display format.
