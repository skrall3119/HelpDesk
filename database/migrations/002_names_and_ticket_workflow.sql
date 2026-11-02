-- Run once, after 001_initial.sql, as the table owner (or a maintenance superuser).
-- The Laravel wrapper supplies the transaction; direct psql callers must use -1.
LOCK TABLE public.users, public.contacts, public.tickets IN ACCESS EXCLUSIVE MODE;

-- Allow the migration owner to backfill all tenants, restoring FORCE before commit.
-- Runtime roles retain their tenant policies throughout this transaction.
ALTER TABLE public.users NO FORCE ROW LEVEL SECURITY;
ALTER TABLE public.contacts NO FORCE ROW LEVEL SECURITY;
ALTER TABLE public.tickets NO FORCE ROW LEVEL SECURITY;
ALTER TABLE public.users DISABLE TRIGGER users_touch;
ALTER TABLE public.contacts DISABLE TRIGGER contacts_touch;
ALTER TABLE public.tickets DISABLE TRIGGER tickets_prepare;
ALTER TABLE public.tickets DISABLE TRIGGER tickets_audit;
ALTER TABLE public.tickets DISABLE TRIGGER tickets_touch;

ALTER TABLE public.users ADD COLUMN name text;
UPDATE public.users SET name = btrim(concat_ws(' ', btrim(first_name), btrim(last_name)));
ALTER TABLE public.users ALTER COLUMN name SET NOT NULL;
ALTER TABLE public.users ADD CONSTRAINT users_name_check CHECK (btrim(name) <> '');
ALTER TABLE public.users DROP COLUMN first_name, DROP COLUMN last_name;

ALTER TABLE public.contacts ADD COLUMN name text;
UPDATE public.contacts SET name = btrim(concat_ws(' ', btrim(first_name), btrim(last_name)));
ALTER TABLE public.contacts ALTER COLUMN name SET NOT NULL;
ALTER TABLE public.contacts ADD CONSTRAINT contacts_name_check CHECK (btrim(name) <> '');
ALTER TABLE public.contacts DROP COLUMN first_name, DROP COLUMN last_name;

ALTER TABLE public.tickets
    DROP CONSTRAINT tickets_check,
    DROP CONSTRAINT tickets_check1,
    DROP CONSTRAINT tickets_check2,
    DROP CONSTRAINT tickets_check3,
    DROP CONSTRAINT tickets_check4,
    DROP CONSTRAINT tickets_status_check;

-- This is a schema conversion, not an employee action. Preserve existing audit
-- snapshots and updated_at; do not invent an actor or a closure note for legacy rows.
UPDATE public.tickets
SET status = 'closed', closed_at = COALESCE(closed_at, resolved_at, created_at)
WHERE status = 'resolved';
ALTER TABLE public.tickets DROP COLUMN resolved_at;
ALTER TABLE public.tickets
    ADD CONSTRAINT tickets_status_check CHECK (status IN ('open', 'in_progress', 'waiting_on_client', 'on_hold', 'closed')),
    ADD CONSTRAINT tickets_closed_assignee_check CHECK (status <> 'closed' OR assigned_user_id IS NOT NULL),
    ADD CONSTRAINT tickets_closed_timestamp_check CHECK ((status = 'closed') = (closed_at IS NOT NULL)),
    ADD CONSTRAINT tickets_closed_chronology_check CHECK (closed_at IS NULL OR closed_at >= created_at);

ALTER TABLE public.ticket_activities ADD COLUMN status_reason text
    CHECK (status_reason IS NULL OR btrim(status_reason) <> '');

CREATE OR REPLACE FUNCTION public.prepare_ticket() RETURNS trigger LANGUAGE plpgsql
SET search_path = pg_catalog AS $$
DECLARE
    allowed boolean;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'open' THEN
            RAISE EXCEPTION 'New tickets must start Open' USING ERRCODE = '23514';
        END IF;
        UPDATE public.tenants SET next_ticket_number = next_ticket_number + 1
        WHERE id = NEW.tenant_id RETURNING next_ticket_number - 1 INTO NEW.ticket_number;
        IF NOT FOUND THEN RAISE EXCEPTION 'Tenant missing or inaccessible'; END IF;
        NEW.closed_at := NULL;
    ELSE
        IF ROW(NEW.id, NEW.tenant_id, NEW.ticket_number, NEW.created_by_user_id, NEW.created_at)
            IS DISTINCT FROM ROW(OLD.id, OLD.tenant_id, OLD.ticket_number, OLD.created_by_user_id, OLD.created_at) THEN
            RAISE EXCEPTION 'Ticket identity and creator are immutable';
        END IF;
        IF NEW.status IS DISTINCT FROM OLD.status THEN
            allowed := CASE OLD.status
                WHEN 'open' THEN NEW.status IN ('in_progress', 'waiting_on_client', 'on_hold', 'closed')
                WHEN 'in_progress' THEN NEW.status IN ('waiting_on_client', 'on_hold', 'closed')
                WHEN 'waiting_on_client' THEN NEW.status IN ('in_progress', 'on_hold', 'closed')
                WHEN 'on_hold' THEN NEW.status IN ('in_progress', 'waiting_on_client', 'closed')
                WHEN 'closed' THEN NEW.status = 'in_progress'
                ELSE false
            END;
            IF allowed IS DISTINCT FROM true THEN
                RAISE EXCEPTION 'Invalid ticket status transition: % -> %', OLD.status, NEW.status
                    USING ERRCODE = '23514';
            END IF;
            IF (NEW.status IN ('on_hold', 'closed') OR OLD.status = 'closed')
                AND NULLIF(btrim(current_setting('app.status_reason', true)), '') IS NULL THEN
                RAISE EXCEPTION 'A reason is required for hold, closure, or reopening'
                    USING ERRCODE = '23514';
            END IF;
        END IF;
        -- Callers cannot supply or overwrite completion timestamps.
        IF NEW.status = 'closed' THEN
            IF OLD.status = 'closed' THEN NEW.closed_at := OLD.closed_at;
            ELSE NEW.closed_at := clock_timestamp(); END IF;
        ELSE NEW.closed_at := NULL;
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION public.audit_ticket() RETURNS trigger LANGUAGE plpgsql
SET search_path = pg_catalog AS $$
DECLARE
    actor_id bigint := nullif(current_setting('app.user_id', true), '')::bigint;
    previous_values jsonb;
    current_values jsonb := to_jsonb(NEW) - 'updated_at';
    reason text;
BEGIN
    IF actor_id IS NULL THEN RAISE EXCEPTION 'app.user_id is required for ticket writes'; END IF;
    IF NOT EXISTS (SELECT 1 FROM public.users WHERE id = actor_id AND tenant_id = NEW.tenant_id
        AND active AND deleted_at IS NULL) THEN
        RAISE EXCEPTION 'Ticket actor must be an active user in the tenant';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        previous_values := to_jsonb(OLD) - 'updated_at';
        IF previous_values = current_values THEN RETURN NEW; END IF;
        IF OLD.status IS DISTINCT FROM NEW.status THEN
            reason := nullif(btrim(current_setting('app.status_reason', true)), '');
        END IF;
    ELSIF NEW.created_by_user_id <> actor_id THEN
        RAISE EXCEPTION 'Ticket creator must match app.user_id';
    END IF;
    INSERT INTO public.ticket_activities
        (tenant_id, ticket_id, user_id, activity_type, old_values, new_values, status_reason)
    VALUES (NEW.tenant_id, NEW.id, actor_id,
        CASE WHEN TG_OP = 'INSERT' THEN 'created' ELSE 'updated' END,
        previous_values, current_values, reason);
    RETURN NEW;
END;
$$;

ALTER TABLE public.users ENABLE TRIGGER users_touch;
ALTER TABLE public.contacts ENABLE TRIGGER contacts_touch;
ALTER TABLE public.tickets ENABLE TRIGGER tickets_prepare;
ALTER TABLE public.tickets ENABLE TRIGGER tickets_audit;
ALTER TABLE public.tickets ENABLE TRIGGER tickets_touch;
ALTER TABLE public.users FORCE ROW LEVEL SECURITY;
ALTER TABLE public.contacts FORCE ROW LEVEL SECURITY;
ALTER TABLE public.tickets FORCE ROW LEVEL SECURITY;
