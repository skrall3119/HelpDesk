\set ON_ERROR_STOP on
-- Fresh development/CI PostgreSQL cluster only. Run as a cluster administrator.
-- Intentionally fail if either name exists; never rewrite existing role settings.
-- Assign passwords with psql \password afterward, not committed SQL literals.
CREATE ROLE caselantern_migrator LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE caselantern_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
