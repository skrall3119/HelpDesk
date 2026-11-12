CREATE POLICY tenant_slug_lookup ON public.tenants FOR SELECT
    TO caselantern_tenant_lookup USING (true);
GRANT USAGE ON SCHEMA public TO caselantern_tenant_lookup;
GRANT SELECT (id, slug, active) ON public.tenants TO caselantern_tenant_lookup;

CREATE FUNCTION public.lookup_tenant(requested_slug text)
RETURNS TABLE (id bigint, active boolean)
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog
AS $$ SELECT t.id, t.active FROM public.tenants AS t WHERE t.slug = requested_slug $$;
REVOKE ALL ON FUNCTION public.lookup_tenant(text) FROM PUBLIC;
-- PostgreSQL requires temporary schema CREATE when transferring function ownership.
GRANT CREATE ON SCHEMA public TO caselantern_tenant_lookup;
ALTER FUNCTION public.lookup_tenant(text) OWNER TO caselantern_tenant_lookup;
REVOKE CREATE ON SCHEMA public FROM caselantern_tenant_lookup;
GRANT EXECUTE ON FUNCTION public.lookup_tenant(text) TO caselantern_app;

CREATE TABLE public.access_tokens (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id bigint NOT NULL REFERENCES public.tenants(id) ON DELETE CASCADE,
    user_id bigint NOT NULL,
    token_hash char(64) NOT NULL UNIQUE,
    password_fingerprint char(64) NOT NULL,
    created_at timestamptz NOT NULL,
    expires_at timestamptz NOT NULL,
    FOREIGN KEY (tenant_id, user_id) REFERENCES public.users(tenant_id, id) ON DELETE CASCADE,
    CHECK (expires_at = created_at + interval '48 hours')
);
CREATE INDEX access_tokens_expiry ON public.access_tokens(expires_at);
ALTER TABLE public.access_tokens ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.access_tokens FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON public.access_tokens
    USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::bigint)
    WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::bigint);
GRANT SELECT, INSERT, DELETE ON public.access_tokens TO caselantern_app;
GRANT USAGE ON SEQUENCE public.access_tokens_id_seq TO caselantern_app;
