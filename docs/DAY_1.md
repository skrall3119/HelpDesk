# Day 1 — development baseline

Started: 2026-11-02. Budget: four focused hours. Actual elapsed/focused time is
not tracked automatically. Status: **implementation and test gates passed; PR review/merge pending**.

## Checklist

| Item | Status | Evidence / next action |
| --- | --- | --- |
| Foundation reviewed for Day 1 setup risks | Reviewed | Starter `composer setup` attempted runtime migrations/key rotation; README claimed no scaffold; grants/bootstrap documentation and non-owner application fixtures were missing |
| Existing foundation merged | Complete | `main` at `d86fb8d` includes the complete `4936799` foundation tree; compare showed no remaining foundation file delta |
| Confirmed requirements reconciled | Complete in plan | Single names, Closed-only completion, subdomains, no V1 categories/reports, required primary/contact method, comment revisions retained; client/comment enforcement remains future implementation |
| Reproducible setup and everyday commands | Documented; CI exercised fresh setup | Root README and `DEVELOPMENT.md`; fresh vs existing environment separated; dedicated owner migrations; scoped authentication/grants; no existing tenant/key reset |
| Reusable A/B account fixtures | Passed in CI and on server | `Tests\Support\TenantFixtures`; synthetic accounts share an email; runtime isolation/failure-cleanup assertions; no real tenant data used |
| Minimal CI baseline | Passed | PostgreSQL 18 service, separate roles, fresh migrations, SQL regressions, PHP tests with fail-on-skipped, Pint and asset build |
| CI execution | Passed | [Run 41524492056](https://github.com/skrall3119/HelpDesk/actions/runs/41524492056): fresh migrations, SQL schema/upgrade checks, Pint, 21 tests / 91 assertions without skips, asset build |
| Existing server verification | PHPUnit passed | Owner switched branch and installed locked dependencies; complete owner/runtime PostgreSQL suite passed: 21 tests / 91 assertions, zero skips |
| Day 1 PR review/merge | Ready for review; merge pending | [PR #2](https://github.com/skrall3119/HelpDesk/pull/2); review exact diff and green checks before merging; the foundation itself is already merged |

## Existing-server verification

No new application schema migration is added by Day 1. Preserve the application
key, Janci Tech tenant, and runtime/migration separation.

1. Inspect `git status --short`; fetch and switch to the Day 1 branch without
   removing untracked server files. Run `composer install` for the script change.
2. Apply `database/setup/runtime-grants.sql` to the **existing disposable**
   `caselantern_provisioning_test` database using shell input redirection, as
   documented in `DEVELOPMENT.md`. Do not rerun fresh-role creation.
3. The test database already accepts SCRAM for the migration role. Add this narrow
   runtime-role rule above the general Ident entry if it is not already covered:

   ```text
   host caselantern_provisioning_test caselantern_app 127.0.0.1/32 scram-sha-256
   ```

   Back up `pg_hba.conf`, check `pg_hba_file_rules` for errors, and reload before
   testing. Do not broaden other authentication rules or switch to trust.
4. Run Pint, the default smoke/validation suite, asset build, and the complete
   PostgreSQL suite using the guide. Record exact results here after execution.
5. Recheck the existing welcome page and runtime DB connection; neither should
   require migration credentials. Review and merge only after checks pass.

## Review boundaries and next work

This is a foundation/setup review, not a claim that all V1 security has been
implemented. Existing SQL checks enforce domain relationships/workflow, and the
provisioning tests already passed on the owner's server. Day 1 adds reproducibility
and runtime-role coverage. It does not implement subdomain routing, tenant lookup,
web request transactions, authentication, policies, or the business screens.

The User model/factory alignment, client/contact rules, comment revision storage,
and browser authorization remain assigned to their respective schedule days.
The next slice is Day 2's restricted tenant lookup, after this baseline's gate passes.

## Verification record

- Local authoring workspace: no PHP/PostgreSQL executables available; no local
  PHPUnit/Pint/SQL pass is claimed. Static file/format review alone is insufficient.
- Static checks: JSON/YAML parsed, workflow shell blocks passed `bash -n`, whitespace reviewed; only Composer setup scripts changed.
- CI: [run 41524492056](https://github.com/skrall3119/HelpDesk/actions/runs/41524492056) passed on code revision `9eacf2c`. SQL schema/upgrade checks, fresh owner migrations, Composer/platform checks, Pint, 21 PHPUnit tests / 91 assertions without skips, and asset build all passed. Later documentation-only commits retain their own required CI status.
- Server: owner-provided output on 2026-11-02 confirms branch switch/Composer install and `vendor/bin/phpunit --fail-on-skipped` against `caselantern_provisioning_test`: PHP 8.5.12, PHPUnit 12.5.42, 21 tests / 91 assertions, zero skips. No application migration or tenant recreation was requested.
- Review/merge: [PR #2](https://github.com/skrall3119/HelpDesk/pull/2) has validation evidence; merge remains pending. Server-specific browser/Pint/build reruns are not claimed by the PHPUnit result; earlier browser access and current CI provide the separate existing evidence.
