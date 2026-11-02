\set ON_ERROR_STOP on
-- Run against the intended migrated database as its owner or an administrator.
-- Reusable after migrations. Never grant runtime ownership, DDL, or BYPASSRLS.
BEGIN;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO caselantern_app;
GRANT SELECT, UPDATE ON public.tenants TO caselantern_app;
GRANT SELECT, INSERT, UPDATE ON public.users, public.clients, public.contacts,
    public.tickets, public.ticket_comments TO caselantern_app;
GRANT SELECT, INSERT ON public.ticket_activities TO caselantern_app;
GRANT USAGE ON SEQUENCE public.tenants_id_seq, public.users_id_seq,
    public.clients_id_seq, public.contacts_id_seq, public.tickets_id_seq,
    public.ticket_comments_id_seq, public.ticket_activities_id_seq TO caselantern_app;
COMMIT;
