\set ON_ERROR_STOP on
-- One-time setup on existing and fresh clusters, as administrator, before migration 003.
-- No login, bypass-RLS, table ownership, or write privileges for this lookup identity.
CREATE ROLE caselantern_tenant_lookup NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT caselantern_tenant_lookup TO caselantern_migrator;
