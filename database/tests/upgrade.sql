\set ON_ERROR_STOP on
-- Disposable database with ONLY 001_initial.sql applied. Run as a superuser.
-- The forward migration and all fixtures are rolled back by this upgrade test.
BEGIN;
INSERT INTO public.tenants(id,name,slug) OVERRIDING SYSTEM VALUE
VALUES (9100,'Upgrade Alpha','upgrade-alpha'),(9200,'Upgrade Beta','upgrade-beta');
INSERT INTO public.users(id,tenant_id,first_name,last_name,email,password,role) OVERRIDING SYSTEM VALUE
VALUES (9100,9100,'Alex','Smith','alex@example.com','test-hash','technician'),
       (9200,9200,'Sam','Jones','sam@example.com','test-hash','technician');
INSERT INTO public.clients(id,tenant_id,client_type,name) OVERRIDING SYSTEM VALUE
VALUES (9100,9100,'business','Upgrade client'),(9200,9200,'business','Other client');
INSERT INTO public.contacts(id,tenant_id,client_id,first_name,last_name) OVERRIDING SYSTEM VALUE
VALUES (9100,9100,9100,'Pat','Smith'),(9200,9200,9200,'Robin','Jones');
SET LOCAL app.user_id='9100';
INSERT INTO public.tickets(id,tenant_id,client_id,contact_id,created_by_user_id,assigned_user_id,subject,description,status)
OVERRIDING SYSTEM VALUE VALUES (9100,9100,9100,9100,9100,9100,'Legacy resolution','Retain history','resolved');
CREATE TEMP TABLE upgrade_before AS SELECT id,resolved_at,updated_at FROM public.tickets WHERE id=9100;
CREATE TEMP TABLE upgrade_audit_before AS SELECT * FROM public.ticket_activities WHERE ticket_id=9100;

-- Exercise the actual table-owner path, which must backfill across forced RLS.
SELECT tableowner AS upgrade_owner FROM pg_tables WHERE schemaname='public' AND tablename='tickets' \gset
SET LOCAL ROLE :"upgrade_owner";
\ir ../migrations/002_names_and_ticket_workflow.sql
RESET ROLE;

DO $$
BEGIN
    IF (SELECT name FROM public.users WHERE id=9100) <> 'Alex Smith'
       OR (SELECT name FROM public.users WHERE id=9200) <> 'Sam Jones'
       OR (SELECT name FROM public.contacts WHERE id=9100) <> 'Pat Smith'
       OR (SELECT name FROM public.contacts WHERE id=9200) <> 'Robin Jones' THEN
        RAISE EXCEPTION 'Names were not preserved across tenants';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM public.tickets t JOIN upgrade_before b USING(id)
        WHERE t.status='closed' AND t.closed_at=b.resolved_at AND t.updated_at=b.updated_at) THEN
        RAISE EXCEPTION 'Legacy resolution/timestamps were not preserved';
    END IF;
    IF EXISTS (SELECT id,old_values,new_values FROM upgrade_audit_before
        EXCEPT SELECT id,old_values,new_values FROM public.ticket_activities)
       OR (SELECT count(*) FROM public.ticket_activities WHERE ticket_id=9100)
          <> (SELECT count(*) FROM upgrade_audit_before) THEN
        RAISE EXCEPTION 'Upgrade rewrote audit history or invented events';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' AND c.relname IN ('users','contacts','tickets')
        AND NOT (c.relrowsecurity AND c.relforcerowsecurity)) THEN
        RAISE EXCEPTION 'Forced RLS was not restored';
    END IF;
END;
$$;
ROLLBACK;
\echo 'Upgrade checks passed (migration and fixtures rolled back).'
