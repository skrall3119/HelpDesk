BEGIN;

CREATE TABLE public.tenants (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name text NOT NULL CHECK (btrim(name) <> ''),
    slug text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    active boolean NOT NULL DEFAULT true,
    next_ticket_number bigint NOT NULL DEFAULT 1 CHECK (next_ticket_number > 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE public.users (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id bigint NOT NULL REFERENCES public.tenants(id) ON DELETE RESTRICT,
    first_name text NOT NULL CHECK (btrim(first_name) <> ''),
    last_name text NOT NULL CHECK (btrim(last_name) <> ''),
    email text NOT NULL CHECK (btrim(email) <> '' AND email = btrim(email)),
    password text NOT NULL CHECK (btrim(password) <> ''),
    role text NOT NULL CHECK (role IN ('administrator', 'manager', 'technician')),
    active boolean NOT NULL DEFAULT true,
    remember_token varchar(100),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz,
    UNIQUE (tenant_id, id)
);
-- Reserve an email even after soft deletion; restoring cannot conflict.
CREATE UNIQUE INDEX users_tenant_email_unique ON public.users (tenant_id, lower(email));

CREATE TABLE public.clients (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id bigint NOT NULL REFERENCES public.tenants(id) ON DELETE RESTRICT,
    client_type text NOT NULL CHECK (client_type IN ('individual', 'business')),
    name text NOT NULL CHECK (btrim(name) <> ''),
    phone text,
    email text,
    address_line_1 text,
    address_line_2 text,
    city text,
    state text,
    postal_code text,
    country varchar(2) CHECK (country ~ '^[A-Z]{2}$'),
    active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz,
    UNIQUE (tenant_id, id)
);

CREATE TABLE public.contacts (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id bigint NOT NULL REFERENCES public.tenants(id) ON DELETE RESTRICT,
    client_id bigint NOT NULL,
    first_name text NOT NULL CHECK (btrim(first_name) <> ''),
    last_name text NOT NULL CHECK (btrim(last_name) <> ''),
    email text,
    phone text,
    job_title text,
    is_primary boolean NOT NULL DEFAULT false,
    active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz,
    UNIQUE (tenant_id, id),
    UNIQUE (tenant_id, client_id, id),
    FOREIGN KEY (tenant_id, client_id) REFERENCES public.clients(tenant_id, id) ON DELETE RESTRICT
);
CREATE UNIQUE INDEX contacts_one_primary ON public.contacts (tenant_id, client_id)
    WHERE is_primary AND active AND deleted_at IS NULL;
CREATE INDEX contacts_client_idx ON public.contacts (tenant_id, client_id);

CREATE TABLE public.tickets (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id bigint NOT NULL REFERENCES public.tenants(id) ON DELETE RESTRICT,
    ticket_number bigint NOT NULL CHECK (ticket_number > 0),
    client_id bigint NOT NULL,
    contact_id bigint NOT NULL,
    assigned_user_id bigint,
    created_by_user_id bigint NOT NULL,
    status text NOT NULL DEFAULT 'open'
        CHECK (status IN ('open', 'in_progress', 'waiting_on_client', 'resolved', 'closed')),
    priority text NOT NULL DEFAULT 'normal' CHECK (priority IN ('low', 'normal', 'high', 'critical')),
    subject text NOT NULL CHECK (btrim(subject) <> ''),
    description text NOT NULL CHECK (btrim(description) <> ''),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    resolved_at timestamptz,
    closed_at timestamptz,
    deleted_at timestamptz,
    UNIQUE (tenant_id, id),
    UNIQUE (tenant_id, ticket_number),
    FOREIGN KEY (tenant_id, client_id) REFERENCES public.clients(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, client_id, contact_id) REFERENCES public.contacts(tenant_id, client_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, assigned_user_id) REFERENCES public.users(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES public.users(tenant_id, id) ON DELETE RESTRICT,
    CHECK (status NOT IN ('resolved', 'closed') OR assigned_user_id IS NOT NULL),
    CHECK ((status IN ('resolved', 'closed')) = (resolved_at IS NOT NULL)),
    CHECK ((status = 'closed') = (closed_at IS NOT NULL)),
    CHECK (resolved_at IS NULL OR resolved_at >= created_at),
    CHECK (closed_at IS NULL OR closed_at >= resolved_at)
);
CREATE INDEX tickets_dashboard_idx ON public.tickets (tenant_id, status, priority, created_at DESC, id DESC) WHERE deleted_at IS NULL;
CREATE INDEX tickets_assignee_idx ON public.tickets (tenant_id, assigned_user_id, created_at DESC, id DESC) WHERE deleted_at IS NULL;
CREATE INDEX tickets_client_idx ON public.tickets (tenant_id, client_id, created_at DESC);
CREATE INDEX tickets_contact_idx ON public.tickets (tenant_id, client_id, contact_id);
CREATE INDEX tickets_creator_idx ON public.tickets (tenant_id, created_by_user_id);
CREATE INDEX tickets_search_idx ON public.tickets USING gin
    (to_tsvector('english', subject || ' ' || description)) WHERE deleted_at IS NULL;

CREATE TABLE public.ticket_comments (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id bigint NOT NULL REFERENCES public.tenants(id) ON DELETE RESTRICT,
    ticket_id bigint NOT NULL,
    user_id bigint NOT NULL,
    body text NOT NULL CHECK (btrim(body) <> ''),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz,
    FOREIGN KEY (tenant_id, ticket_id) REFERENCES public.tickets(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, user_id) REFERENCES public.users(tenant_id, id) ON DELETE RESTRICT
);
CREATE INDEX ticket_comments_timeline_idx ON public.ticket_comments (tenant_id, ticket_id, created_at, id);
CREATE INDEX ticket_comments_author_idx ON public.ticket_comments (tenant_id, user_id);

CREATE TABLE public.ticket_activities (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id bigint NOT NULL REFERENCES public.tenants(id) ON DELETE RESTRICT,
    ticket_id bigint NOT NULL,
    user_id bigint NOT NULL,
    activity_type text NOT NULL CHECK (activity_type IN ('created', 'updated')),
    old_values jsonb,
    new_values jsonb NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (tenant_id, ticket_id) REFERENCES public.tickets(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, user_id) REFERENCES public.users(tenant_id, id) ON DELETE RESTRICT,
    CHECK (jsonb_typeof(new_values) = 'object'),
    CHECK (old_values IS NULL OR jsonb_typeof(old_values) = 'object'),
    CHECK ((activity_type = 'created') = (old_values IS NULL))
);
CREATE INDEX ticket_activities_timeline_idx ON public.ticket_activities (tenant_id, ticket_id, created_at, id);
CREATE INDEX ticket_activities_actor_idx ON public.ticket_activities (tenant_id, user_id);

CREATE FUNCTION public.touch_updated_at() RETURNS trigger LANGUAGE plpgsql
SET search_path = pg_catalog AS $$
BEGIN
    NEW.updated_at := clock_timestamp();
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.prepare_ticket() RETURNS trigger LANGUAGE plpgsql
SET search_path = pg_catalog AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        -- Atomic counter update serializes number allocation within a tenant.
        UPDATE public.tenants SET next_ticket_number = next_ticket_number + 1
        WHERE id = NEW.tenant_id RETURNING next_ticket_number - 1 INTO NEW.ticket_number;
        IF NOT FOUND THEN RAISE EXCEPTION 'Tenant missing or inaccessible'; END IF;
    ELSE
        IF ROW(NEW.id, NEW.tenant_id, NEW.ticket_number, NEW.created_by_user_id, NEW.created_at)
            IS DISTINCT FROM ROW(OLD.id, OLD.tenant_id, OLD.ticket_number, OLD.created_by_user_id, OLD.created_at) THEN
            RAISE EXCEPTION 'Ticket identity and creator are immutable';
        END IF;
    END IF;
    IF NEW.status IN ('resolved', 'closed') THEN
        NEW.resolved_at := COALESCE(NEW.resolved_at, clock_timestamp());
    ELSE
        NEW.resolved_at := NULL;
    END IF;
    IF NEW.status = 'closed' THEN
        NEW.closed_at := COALESCE(NEW.closed_at, clock_timestamp());
    ELSE
        NEW.closed_at := NULL;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER tickets_prepare BEFORE INSERT OR UPDATE ON public.tickets
    FOR EACH ROW EXECUTE FUNCTION public.prepare_ticket();

CREATE FUNCTION public.audit_ticket() RETURNS trigger LANGUAGE plpgsql
SET search_path = pg_catalog AS $$
DECLARE
    actor_id bigint := nullif(current_setting('app.user_id', true), '')::bigint;
    previous_values jsonb;
    current_values jsonb := to_jsonb(NEW) - 'updated_at';
BEGIN
    IF actor_id IS NULL THEN RAISE EXCEPTION 'app.user_id is required for ticket writes'; END IF;
    IF NOT EXISTS (SELECT 1 FROM public.users WHERE id = actor_id AND tenant_id = NEW.tenant_id
        AND active AND deleted_at IS NULL) THEN
        RAISE EXCEPTION 'Ticket actor must be an active user in the tenant';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        previous_values := to_jsonb(OLD) - 'updated_at';
        IF previous_values = current_values THEN RETURN NEW; END IF;
    ELSIF NEW.created_by_user_id <> actor_id THEN
        RAISE EXCEPTION 'Ticket creator must match app.user_id';
    END IF;
    INSERT INTO public.ticket_activities (tenant_id, ticket_id, user_id, activity_type, old_values, new_values)
        VALUES (NEW.tenant_id, NEW.id, actor_id, CASE WHEN TG_OP = 'INSERT' THEN 'created' ELSE 'updated' END,
            previous_values, current_values);
    RETURN NEW;
END;
$$;
CREATE TRIGGER tickets_audit AFTER INSERT OR UPDATE ON public.tickets
    FOR EACH ROW EXECUTE FUNCTION public.audit_ticket();

CREATE FUNCTION public.reject_activity_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Ticket activity is append-only';
END;
$$;
CREATE TRIGGER ticket_activities_append_only BEFORE UPDATE OR DELETE ON public.ticket_activities
    FOR EACH ROW EXECUTE FUNCTION public.reject_activity_mutation();

-- Context is supplied by trusted server code, never by a browser or SQL client.
-- Missing context denies access. Runtime credentials must not own tables or bypass RLS.
DO $$
DECLARE table_name text;
BEGIN
    FOREACH table_name IN ARRAY ARRAY['tenants', 'users', 'clients', 'contacts', 'tickets', 'ticket_comments', 'ticket_activities'] LOOP
        EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('ALTER TABLE public.%I FORCE ROW LEVEL SECURITY', table_name);
        EXECUTE format('CREATE POLICY tenant_isolation ON public.%I USING (%I = nullif(current_setting(''app.tenant_id'', true), '''')::bigint) WITH CHECK (%I = nullif(current_setting(''app.tenant_id'', true), '''')::bigint)',
            table_name, CASE WHEN table_name = 'tenants' THEN 'id' ELSE 'tenant_id' END,
            CASE WHEN table_name = 'tenants' THEN 'id' ELSE 'tenant_id' END);
        IF table_name <> 'ticket_activities' THEN
            EXECUTE format('CREATE TRIGGER %I BEFORE UPDATE ON public.%I FOR EACH ROW EXECUTE FUNCTION public.touch_updated_at()', table_name || '_touch', table_name);
        END IF;
    END LOOP;
END;
$$;
COMMIT;
