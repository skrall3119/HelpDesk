# CaseLantern — daily sprint schedule

Planning baseline: 2026-11-02. Capacity confirmed by the owner: **4 focused hours per workday**.

This is a forecast, not a delivery guarantee. Plan **45 workdays / 180 hours**:
40 planned delivery days (160 hours) plus five contingency days (20 hours).
At five workdays per week this is approximately nine weeks; weekends, holidays,
and missed sessions extend elapsed time. Day 1 means the next agreed development
session, not a fixed calendar date. Reforecast after Sprint 1 using actual results.

The target is the agreed **V1 through a verified release**, not the later-release
backlog. This schedule supplements [PROJECT_PLAN.md](PROJECT_PLAN.md), which owns
product rules. Completing a database migration is not completing its application UI.

## Current position: do not repeat completed work

| Area | Verified state | Evidence / remaining boundary |
| --- | --- | --- |
| Repository and framework | Laravel scaffold committed and pushed on `setup/laravel-foundation` | Scaffold commit `4332c64`; later example environment and provisioning commits pulled by owner |
| Development server | Laravel 13.39.0, PHP 8.5.12, Composer 2.10.3, Node 22.23.1, npm 10.9.8; PostgreSQL 18.6 | Owner-provided terminal output; platform requirements passed |
| Database | Baseline and name/workflow forward migration applied | Laravel batches 1 and 2; SQL upgrade and schema checks passed per owner |
| Runtime identity | Laravel connects to `caselantern` as restricted `caselantern_app` | Migration identity remains separate; runtime authentication is not browser authentication |
| Frontend and access | `npm ci` and asset build passed; welcome page loads through SSH forwarding | Optional fontaine warning did not block build; no tenant pages yet |
| Provisioning | Tenant/admin creation implemented and exercised | 17 provisioning tests, 59 assertions passed, including three real PostgreSQL tests; Pint passed for four files |
| Initial organization | Janci Tech, slug `jancitech`, tenant ID 1, administrator ID 1 | Owner ran the command successfully; do not recreate or overwrite this account |
| Remaining foundation | Authentication, tenant host resolution, capability policies, recovery, CI | Reviewed `routes/web.php` still serves only welcome; no tenant middleware registered; Livewire not installed in reviewed Composer manifest |
| Remaining product | Employees, clients, contacts, tickets, comments/history, dashboard, organization settings | Tables and triggers exist for much of the domain; browser workflows are unbuilt |
| Remaining operations | Staging/production topology, wildcard DNS/TLS, recovery mail, backups, monitoring, runbook | Development HTTP server and SSH tunnel are not production hosting |

These are separate observed test runs, not a claimed combined suite total. Existing
SQLite smoke/validation checks do not establish PostgreSQL tenant isolation; keep
real non-owner PostgreSQL tests as required gates.

## Scope and sequence

- Tenant subdomains, independent accounts, single user/contact names, fixed three
  roles, current contact information, internal comments, and Closed-only completion.
- Restore the previously approved client rule: creating a client also creates its
  primary contact atomically; contacts need a name and email or phone. Replace an
  active client's primary contact before deactivating that contact.
- Restore the previously accepted comment rule: authors edit their own comments,
  managers/admins soft-delete, and revisions are retained. The later append-only
  draft was a working default, not an approval overriding those decisions.
- Build authentication/context before business CRUD. Add authorization and tests
  with each feature rather than deferring them to a final security phase.
- Reuse existing SQL numbering, workflow, and audit triggers; do not create a
  competing audit system or reimplement the database schema from scratch.
- Keep the existing native server workflow. Docker Compose is optional portability
  work, not a prerequisite. Keep one application, Blade/Tailwind, and Livewire only
  where interactive forms/filters benefit from it.
- Categories, reports, attachments, public signup, billing, client portal,
  email-to-ticket, ticket notifications, SLAs, teams/custom roles, AI, integrations,
  and public APIs remain outside V1. Recovery/setup email remains in scope.

## Daily operating rule

Each ordinary four-hour day reserves roughly 2.5 hours for implementation, one
hour for meaningful tests/browser checks, and 0.5 hours for review, documentation,
and a small commit. On design/infrastructure days the same budget covers the
relevant proof rather than forcing a code deliverable. Tests belong inside these
estimates; final checks do not replace the daily checks.

Stop dependent work when an exit condition fails. Use the next contingency slot
for genuine overruns; do not silently drop tenant tests or product behavior.
If a contingency slot is unused, pull forward the next scheduled task.

## Sprint 1 — tenant-aware login (Days 1–5, 20 hours)

| Day | Work within the four-hour block | Exit condition / proof |
| --- | --- | --- |
| 1 | Review/merge the existing foundation through the repository's chosen review process; reconcile approved requirements; document server start/test/migration commands; establish a minimal PostgreSQL CI job and reusable A/B test fixtures | Foundation is recoverable from Git; CI skeleton runs; no server-only credential or schema dependency is hidden |
| 2 | Add the narrowly scoped tenant-by-slug lookup and forward migration/grants; use fixed safe search path and least-privilege ownership for any SECURITY DEFINER function | Runtime can resolve only the intended lookup fields; unknown/disabled tenant handling and privilege boundaries tested |
| 3 | Configure base domain, strict subdomain parsing/trusted hosts, and request transaction/context middleware; order it before binding/auth queries and clear context on every completion/failure | Two-host requests and reused connections remain isolated; missing context fails closed; no reads occur after transaction cleanup |
| 4 | Adapt User model/provider for tenant email, active/deleted status; build login/logout, host-only sessions, generic credential errors, rate limiting, CSRF, and a protected landing page | Janci Tech can log in/out on `jancitech.localhost:8000`; invalid credentials and inactive records fail safely |
| 5 | Test cross-subdomain sessions, same email in two tenants, host tampering, deactivation on existing sessions, transaction failures, and local browser flow | Authentication/context tests pass with non-owner PostgreSQL credentials; owner sees a real protected tenant page |

**Gate:** tenant identity comes from the validated host, authenticated identity
comes from the tenant's users, and neither can be changed by submitted form IDs.
Re-estimate the remaining schedule now if this gate took longer than 20 hours.

## Sprint 2 — recovery and employee administration (Days 6–10, 20 hours)

| Day | Work within the four-hour block | Exit condition / proof |
| --- | --- | --- |
| 6 | Add tenant-scoped setup/reset token storage and broker behavior; forward migration; generic responses and expiry/single-use rules | Duplicate emails across tenants cannot share or redeem each other's tokens; raw tokens/passwords are not stored |
| 7 | Build recovery/reset/setup screens and development mail capture; generate trusted tenant URLs and dispatch mail after commit | End-to-end reset works; replay, expired token, wrong tenant, and session invalidation checks pass |
| 8 | Central capability map/policies; administrator employee list/create/edit and fixed-role validation; serialize last-active-admin changes | Managers/technicians cannot manage users or forge roles; concurrent demotion/deactivation cannot remove the last active admin |
| 9 | Complete employee activation/deactivation and one-time setup mail; organization display-name settings; preserve attribution and flag unfinished work for reassignment | Deactivated employee loses access and new assignment eligibility; only administrator can change tenant settings; slug/counter remain protected |
| 10 | **Contingency 1:** fix authentication, recovery, or employee issues; otherwise advance Day 11 | Account-management demo passes before dependent business screens |

**Gate:** an administrator can add an employee who sets a password and signs in;
deactivation works across already-open sessions. Existing provisioning stays a
controlled operator CLI, not public registration.

## Sprint 3 — shared UI and clients/contacts (Days 11–15, 20 hours)

| Day | Work within the four-hour block | Exit condition / proof |
| --- | --- | --- |
| 11 | Extend CI to run SQL upgrade/schema checks and application PostgreSQL tests with separate owner/runtime identities; Pint and asset build; make missing required integration configuration fail CI | A green CI run cannot be achieved merely by skipping PostgreSQL tests; fresh and upgraded schemas both checked |
| 12 | Add compatible Livewire only for planned interactive controls; shared authenticated layout/navigation, forms, loading/error/empty states; carry tenant context through Livewire requests | Layout works on desktop/mobile; interactive endpoints cannot bypass tenant/auth middleware |
| 13 | Client/contact models, input validation, forward constraints for nonblank contact method as needed; atomic CreateClientWithPrimaryContact and replacement rules | Failed contact creation leaves no client; every newly created client has a valid primary; cross-tenant/client references rejected |
| 14 | Client list/search/detail/create/edit UI; business/individual type, one address, reusable individual/contact name entry; tenant-scoped authorization | All three roles can create/edit; no hard-delete control; posted IDs cannot cross tenants |
| 15 | Additional contacts, edit/current details, transactional primary switching; prevent deactivating current primary without replacement; manager/admin lifecycle controls | Concurrent switches produce one active primary; name plus email/phone enforced; primary replacement and permission tests pass |

**Gate:** an employee can create a client with a reachable primary contact and add
another contact without breaking tenant relationships or lifecycle rules.

## Sprint 4 — first ticket slice (Days 16–20, 20 hours)

| Day | Work within the four-hour block | Exit condition / proof |
| --- | --- | --- |
| 16 | Finish client/contact deactivate/reactivate actions and tests; preserve historical links; separate active selection from historical display | Inactive entities cannot be selected on new tickets; preserved history stays readable; technicians cannot deactivate clients/contacts |
| 17 | Ticket model/actions/validation; immutable tenant/creator/number fields; mandatory client/contact; default Open/Normal; database numbering and audit integration | A runtime-credential test creates a valid ticket with one number/creation event; mismatched contact and forged fields rejected |
| 18 | New ticket form with dependent client/contact picker; ticket list/detail with current contact, number, priority, assignment, timestamps | Owner can create/open a real tenant ticket; missing contact cannot be bypassed; old ticket shows updated contact information |
| 19 | Ticket/description editing and safe client/contact changes; atomic expected-version check for stale forms; field-level validation | Stale browser cannot overwrite newer work; description changes retain actor/time/old/new content once in existing audit |
| 20 | **Contingency 2:** stabilize client/ticket creation and first browser demo; otherwise advance Day 21 | Create/view/edit journey passes before assignment/workflow work |

**Gate:** users can create and correct tickets with valid contact relationships,
current contact details, stable numbering, and an inspectable history.

## Sprint 5 — assignment, workflow, comments, restoration (Days 21–25, 20 hours)

| Day | Work within the four-hour block | Exit condition / proof |
| --- | --- | --- |
| 21 | Atomic technician self-claim; manager/admin assign/reassign/unassign; active target validation; conflict response and UI | Technician cannot take another assignee or assign someone else; closed tickets retain an assignee; losers of claims get a clear conflict |
| 22 | Status/priority forms and domain actions; reason/closure-note validation; transaction-local reason; Closed/reopen timestamp behavior | Full approved transition matrix and negative cases pass; On Hold/close/reopen reasons appear once in audit; assignment preserved |
| 23 | Internal comment add/edit actions and revision storage forward migration; author-only editing and manager/admin soft deletion; stale-edit protection | Old comment bodies, actor, and time survive edits/deletion; other technicians cannot edit/delete someone else's comments |
| 24 | Comment editor and readable mixed timeline; audit labels/diffs, reasons, revision display, stable chronological pagination; handle legacy snapshot fields | Work history is readable, escaped, tenant-scoped, and not duplicated; internal-only content has no public delivery path |
| 25 | Ticket soft-delete, restricted deleted-ticket list/detail and restore; retain status, assignee, comments, and audit history | Manager/admin delete/restore works; technicians cannot reach deleted tickets by UI or direct request |

**Gate:** a technician can create → claim → investigate/comment → hold/resume →
close → reopen; managers can coordinate and restore work without losing history.

## Sprint 6 — concurrency and useful dashboard (Days 26–30, 20 hours)

| Day | Work within the four-hour block | Exit condition / proof |
| --- | --- | --- |
| 26 | Exercise real concurrent claim/reassignment/closure/unassignment and stale-comment/description writes with separate connections; fix exposed races | Exactly one claim winner; closure cannot lose its required assignee; rollback leaves no partial audit or revisions |
| 27 | All/My/Unassigned dashboard; combine status/priority/client/assignee filters; default hides On Hold/Closed/deleted; show reassignment-needed work | Assignment indicates responsibility, not visibility; explicit status filters retrieve paused/closed work; deleted view remains restricted |
| 28 | Exact formatted/plain number and existing full-text subject/description search; deterministic sorting, URL-persisted filters, bounded pagination | Search/filter combinations are tenant-scoped; no accidental promise of comment/substring search; malformed sort/filter values rejected |
| 29 | Realistic synthetic datasets, query plans/N+1 review, bounded timeline/list reads; tune only demonstrated slow paths | Main screens stay responsive with recorded query evidence; no speculative indexing or premature service split |
| 30 | **Contingency 3:** fix workflow/dashboard issues; otherwise advance Day 31; run full internal demo | Feature-complete internal help desk beta; remaining work is cross-cutting verification and release preparation |

**Gate:** the V1 product loop is usable, including search, history, and privileged
recovery. This is an internal beta, not approval to accept production customers.

## Sprint 7 — integration hardening and staging (Days 31–35, 20 hours)

| Day | Work within the four-hour block | Exit condition / proof |
| --- | --- | --- |
| 31 | Complete cross-feature capability/tenant matrix: direct URLs, forged IDs, missing/reused context, inactive records, Livewire, session host mismatch | Required allow/deny combinations pass on production-like runtime credentials; no unexpected public mutation route |
| 32 | Browser automation/smoke journeys for login, employee setup, client, ticket, closure, recovery, delete/restore; keyboard/focus/mobile checks | Repeatable owner journeys pass; accessible labels, focus, and status text do not depend on color alone |
| 33 | Resolve shared validation/error/empty/loading inconsistencies; timezone display; safe logs; no lazy protected reads after transaction end; dependency/asset review | Errors preserve safe input without exposing secrets or another tenant; frontend and PHP checks pass |
| 34 | Finalize staging/production base domain, host/region/budget, SMTP provider, DNS/TLS ownership, mail sender, session storage, retention/recovery targets with owner; prepare exact config/runbook | Required choices recorded; no migration credential in web config; staging has an approved deployment plan |
| 35 | Deploy isolated staging with web server/PHP-FPM, PostgreSQL runtime grants, wildcard tenant DNS/TLS, trusted-host rules, and real recovery/setup mail | Two synthetic tenants work over HTTPS; reset/setup links stay on correct hosts; developer server/SSH tunnel not used as public hosting |

**Gate:** owner can review the product on staging with real TLS and mail delivery.
If external services/domain access are unavailable, keep the block visible rather
than treating localhost checks as staging verification.

## Sprint 8 — operations and acceptance (Days 36–40, 20 hours)

| Day | Work within the four-hour block | Exit condition / proof |
| --- | --- | --- |
| 36 | Configure encrypted scheduled database backups and retention; restore into an isolated database; measure recovery time and validate app data | Backup retrieval and restore are demonstrated; recovery targets are owner-approved and measured, not assumed |
| 37 | Service startup/restart, filesystem ownership, runtime permissions, HTTPS cookies/debug settings, log rotation/redaction, health checks and error/backup/database monitoring | Restart/reboot and failure alerts behave as documented; no secrets or tenant data in health responses |
| 38 | Rehearse release build and privileged migration step, runtime switch, smoke checks, compatible code rollback/forward database repair; verify upgrade from prior schema | Reproducible deployment succeeds without runtime DDL privileges or destructive database rollback |
| 39 | Owner UAT with administrator/manager/technician checklist; provisioning/deactivation/reassignment/reset/restore runbooks and support/offboarding notes | Owner records concrete acceptance or defects; documentation covers everyday and failure operations |
| 40 | **Contingency 4:** fix UAT/operational defects; otherwise advance Day 41 | No known release-blocking defect is carried forward as an undocumented assumption |

**Gate:** restore, failure recovery, operational ownership, and core user behavior
are understood and demonstrated before release signoff.

## Sprint 9 — release candidate and controlled release (Days 41–45, 20 hours)

| Day | Work within the four-hour block | Exit condition / proof |
| --- | --- | --- |
| 41 | Final security/capability and data-lifecycle review; dependency findings, recovery mail, wildcard-host/session configuration, secret exposure, and offboarding obligations | Release-blocking issues fixed or gate stays closed; no real customer data accepted before retention/offboarding decisions |
| 42 | Verify fresh checkout and upgrade instructions; tagged/reviewed release candidate; CI includes mandatory database and browser checks | A clean environment can reproduce the release; no untracked server file or manual SQL step is required |
| 43 | Run the release candidate on staging; end-to-end acceptance, backup/restore evidence, monitoring and deployment rehearsal against the final artifact | Final reviewable artifact and complete evidence pack; outstanding limitations recorded |
| 44 | Obtain owner release approval; then deploy the reviewed artifact and run production smoke/tenant checks with rollback readiness | Production publication happens only after explicit approval; if approval/access is pending, remain on staging |
| 45 | **Contingency 5:** post-release observation and fixes, or resolve release blockers; avoid adding new features | Stable verified release, or a clearly reported remaining gate with a revised date |

**Gate:** all release criteria in the project plan pass. A calendar day arriving
never substitutes for passing a gate or receiving release authorization.

## Decision deadlines and dependencies

| Needed | Latest useful point | Why it matters |
| --- | --- | --- |
| Confirm the next session as Day 1 and whether workdays exclude holidays | Before putting calendar dates on this forecast | Capacity is known; actual availability is not |
| Base-domain configuration convention for local tests | Day 2 | Subdomain parsing and URL generation must use a trusted base |
| Real-world display preferences not already agreed | Before affected UI is polished | Use simple existing Blade/Tailwind patterns until feedback arrives; do not block authentication |
| Staging/production domain, budget, provider access, SMTP sender | Start gathering by Day 11; finalize by Day 34 | DNS, certificates, and sender verification can create external waits |
| Backup retention, recovery objectives, offboarding/permanent-erasure policy | Before Days 36–41 | Existing plan targets are recommendations, not guarantees or legal conclusions |
| Owner UAT availability and explicit release approval | Days 39 and 44 | Required acceptance cannot be inferred from elapsed time |

Critical path: tenant lookup/context → login/session isolation → account recovery
and policies → valid clients/contacts → tickets/workflow/history → dashboard →
staging/operations → approved release. CI and service/domain setup are started early
to expose blockers; features remain sequential where they share unverified foundations.

## Coverage of the original roadmap

| Original milestone | Already complete | Remaining scheduled work |
| --- | --- | --- |
| M0 schema baseline | Baseline, forward name/workflow migration, SQL checks | Targeted new forward migrations for remaining approved requirements; no baseline reset |
| M1 runnable foundation | Native server, scaffold/lockfiles, migration adoption, asset build, smoke tests | Days 1, 11–12, 42: repeatable docs, mandatory CI, shared UI/Livewire; Compose is optional |
| M2 tenant/auth/security | Provisioning and its PostgreSQL tests | Days 2–9, 31: tenant lookup/context, login/logout, recovery/setup, capabilities and isolation |
| M3 employees/clients | Domain tables only | Days 8–9, 13–16: employee/admin settings and complete client/contact lifecycle |
| M4 first ticket slice | Database relationships/numbering/audit | Days 17–19, 24: UI/actions, stale-write checks, readable history |
| M5 work lifecycle | Database statuses/timestamps/reason audit | Days 21–26: claim/assignment, comments/revisions, status forms, restore and races |
| M6 dashboard/usability | Supporting indexes only | Days 27–33: views, search/filters, pagination, performance and browser/accessibility checks |
| M7 release preparation | Development access only | Days 34–45: staging, mail/DNS/TLS, backups, monitoring, runbooks, acceptance/release |

## Tracking and reforecasting

For every day record planned work, actual focused hours, commit/PR, checks and
results, unresolved blocker, and next dependency. A day is complete only when its
exit condition passes. Do not count skipped integration tests as passed tests.

Reforecast after each sprint. If scope grows, a gate fails, or a day exceeds its
budget, consume explicit contingency or move downstream days. Do not recover time
by reducing tenant isolation, removing tests, or treating development infrastructure
as production. New features go to a later-release backlog unless the owner explicitly
changes V1 scope. If this baseline overruns by more than the 20-hour reserve, publish
a revised forecast before continuing to imply the same release window.
