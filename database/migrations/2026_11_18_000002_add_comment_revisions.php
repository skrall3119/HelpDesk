<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE public.ticket_comments ADD CONSTRAINT ticket_comments_tenant_id_unique UNIQUE (tenant_id, id);
ALTER TABLE public.ticket_comments ADD COLUMN deleted_by_user_id bigint,
    ADD FOREIGN KEY (tenant_id, deleted_by_user_id) REFERENCES public.users(tenant_id, id);
CREATE TABLE public.comment_revisions (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id bigint NOT NULL REFERENCES public.tenants(id),
    comment_id bigint NOT NULL,
    editor_user_id bigint NOT NULL,
    body text NOT NULL CHECK (btrim(body) <> ''),
    original_updated_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    FOREIGN KEY (tenant_id, comment_id) REFERENCES public.ticket_comments(tenant_id, id),
    FOREIGN KEY (tenant_id, editor_user_id) REFERENCES public.users(tenant_id, id)
);
CREATE INDEX comment_revisions_timeline ON public.comment_revisions(tenant_id, comment_id, created_at, id);
ALTER TABLE public.comment_revisions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.comment_revisions FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON public.comment_revisions
    USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::bigint)
    WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::bigint);
CREATE TRIGGER comment_revisions_append_only BEFORE UPDATE OR DELETE ON public.comment_revisions
    FOR EACH ROW EXECUTE FUNCTION public.reject_activity_mutation();
GRANT SELECT, INSERT ON public.comment_revisions TO caselantern_app;
GRANT USAGE ON SEQUENCE public.comment_revisions_id_seq TO caselantern_app;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward migration; revisions are retained.');
    }
};
