\set ON_ERROR_STOP on
BEGIN;
CREATE FUNCTION pg_temp.assert_true(value boolean, label text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN IF value IS DISTINCT FROM true THEN RAISE EXCEPTION 'FAIL: %', label; END IF; END; $$;
CREATE FUNCTION pg_temp.rejects(statement text, expected_state text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN EXECUTE statement;
    EXCEPTION WHEN OTHERS THEN
        IF SQLSTATE = expected_state THEN RETURN; END IF;
        RAISE;
    END;
    RAISE EXCEPTION 'Expected rejection: %', statement;
END; $$;
INSERT INTO tenants(id, name, slug) OVERRIDING SYSTEM VALUE VALUES (100, 'Alpha', 'alpha'), (200, 'Beta', 'beta');
INSERT INTO users(id, tenant_id, name, email, password, role) OVERRIDING SYSTEM VALUE VALUES
    (100, 100, 'A Tech', 'tech@example.com', 'test-hash', 'technician'),
    (200, 200, 'B Tech', 'tech@example.com', 'test-hash', 'technician');
INSERT INTO clients(id, tenant_id, client_type, name) OVERRIDING SYSTEM VALUE VALUES (100, 100, 'business', 'Client A'), (101, 100, 'individual', 'Other A'), (200, 200, 'business', 'Client B');
INSERT INTO contacts(id, tenant_id, client_id, name, is_primary) OVERRIDING SYSTEM VALUE VALUES
    (100, 100, 100, 'A Contact', true), (101, 100, 101, 'Other Contact', true), (200, 200, 200, 'B Contact', true);
SELECT pg_temp.rejects($q$INSERT INTO contacts(tenant_id,client_id,name,is_primary) VALUES (100,100,'Duplicate Primary',true)$q$, '23505');
SELECT pg_temp.rejects($q$INSERT INTO users(tenant_id,name,email,password,role) VALUES (100,'A Duplicate','TECH@example.com','test-hash','technician')$q$, '23505');
SELECT pg_temp.rejects($q$UPDATE users SET name='   ' WHERE id=100$q$, '23514');
SELECT pg_temp.rejects($q$UPDATE contacts SET name='   ' WHERE id=100$q$, '23514');
SET LOCAL app.user_id = '100';
INSERT INTO tickets(id,tenant_id,client_id,contact_id,created_by_user_id,subject,description) OVERRIDING SYSTEM VALUE VALUES (100,100,100,100,100,'Network issue','Original description');
SELECT pg_temp.rejects($q$INSERT INTO tickets(tenant_id,client_id,contact_id,created_by_user_id,subject,description) VALUES (100,100,101,100,'Wrong contact','Problem')$q$, '23503');
SELECT pg_temp.rejects($q$UPDATE tickets SET assigned_user_id=200 WHERE id=100$q$, '23503');
SELECT pg_temp.rejects($q$UPDATE tickets SET status='resolved' WHERE id=100$q$, '23514');
SET LOCAL app.status_reason='Issue fixed';
SELECT pg_temp.rejects($q$UPDATE tickets SET status='closed' WHERE id=100$q$, '23514');
SELECT pg_temp.rejects($q$UPDATE tickets SET contact_id=NULL WHERE id=100$q$, '23502');
SELECT pg_temp.rejects($q$UPDATE tickets SET created_by_user_id=200 WHERE id=100$q$, 'P0001');
UPDATE tickets SET assigned_user_id=100, status='closed', description='Corrected description' WHERE id=100;
SELECT pg_temp.assert_true((SELECT closed_at IS NOT NULL FROM tickets WHERE id=100), 'closure timestamp');
SELECT pg_temp.assert_true(EXISTS(SELECT 1 FROM ticket_activities WHERE ticket_id=100 AND status_reason='Issue fixed'), 'closure note audited');
DO $$
DECLARE before_time timestamptz; before_events bigint;
BEGIN
    SELECT closed_at INTO before_time FROM tickets WHERE id=100;
    SELECT count(*) INTO before_events FROM ticket_activities WHERE ticket_id=100;
    UPDATE tickets SET closed_at=created_at, status='closed' WHERE id=100;
    IF (SELECT closed_at FROM tickets WHERE id=100) IS DISTINCT FROM before_time
       OR (SELECT count(*) FROM ticket_activities WHERE ticket_id=100) <> before_events THEN
        RAISE EXCEPTION 'Same-status save changed closure time or created spurious history';
    END IF;
END;
$$;
SELECT pg_temp.assert_true(EXISTS(SELECT 1 FROM ticket_activities WHERE ticket_id=100 AND old_values->>'description'='Original description' AND new_values->>'description'='Corrected description'), 'description history');
SET LOCAL app.status_reason='';
SELECT pg_temp.rejects($q$UPDATE tickets SET status='in_progress' WHERE id=100$q$, '23514');
SET LOCAL app.status_reason='Client reports recurrence';
SELECT pg_temp.rejects($q$UPDATE tickets SET status='open' WHERE id=100$q$, '23514');
SELECT pg_temp.rejects($q$UPDATE tickets SET status='on_hold' WHERE id=100$q$, '23514');
UPDATE tickets SET status='in_progress' WHERE id=100;
SELECT pg_temp.assert_true((SELECT closed_at IS NULL FROM tickets WHERE id=100), 'reopening clears timestamp');
SET LOCAL app.status_reason='';
SELECT pg_temp.rejects($q$UPDATE tickets SET status='on_hold' WHERE id=100$q$, '23514');
SET LOCAL app.status_reason='   ';
SELECT pg_temp.rejects($q$UPDATE tickets SET status='on_hold' WHERE id=100$q$, '23514');
SET LOCAL app.status_reason='Waiting for parts';
UPDATE tickets SET status='on_hold' WHERE id=100;
SELECT pg_temp.assert_true(EXISTS(SELECT 1 FROM ticket_activities WHERE ticket_id=100 AND new_values->>'status'='on_hold' AND status_reason='Waiting for parts'), 'hold reason audited');
UPDATE tickets SET status='in_progress' WHERE id=100;
SET LOCAL app.status_reason='';
SELECT pg_temp.rejects($q$UPDATE tickets SET status='closed' WHERE id=100$q$, '23514');
SELECT pg_temp.rejects($q$INSERT INTO tickets(tenant_id,client_id,contact_id,created_by_user_id,subject,description,status) VALUES (100,100,100,100,'Wrong initial status','Problem','in_progress')$q$, '23514');
-- Exercise every pair independently; each nested transaction rolls its fixture
-- back so numbering/count assertions below retain their original meaning.
DO $$
DECLARE
    source_status text;
    target_status text;
    fixture_id bigint;
    permitted boolean;
    rejected boolean;
BEGIN
    PERFORM set_config('app.status_reason', 'Workflow regression reason', true);
    FOREACH source_status IN ARRAY ARRAY['open','in_progress','waiting_on_client','on_hold','closed'] LOOP
        FOREACH target_status IN ARRAY ARRAY['open','in_progress','waiting_on_client','on_hold','closed'] LOOP
            BEGIN
                INSERT INTO tickets(tenant_id,client_id,contact_id,created_by_user_id,assigned_user_id,subject,description)
                VALUES (100,100,100,100,100,'Matrix fixture','Synthetic issue') RETURNING id INTO fixture_id;
                IF source_status <> 'open' THEN
                    UPDATE tickets SET status=source_status WHERE id=fixture_id;
                END IF;
                permitted := source_status=target_status OR
                    (source_status='open' AND target_status<>'open') OR
                    (source_status IN ('in_progress','waiting_on_client','on_hold') AND target_status<>'open') OR
                    (source_status='closed' AND target_status='in_progress');
                rejected := false;
                BEGIN
                    UPDATE tickets SET status=target_status WHERE id=fixture_id;
                EXCEPTION WHEN check_violation THEN rejected := true;
                END;
                IF rejected = permitted THEN
                    RAISE EXCEPTION 'Unexpected transition result: % -> %', source_status,target_status;
                END IF;
                RAISE EXCEPTION 'Rollback matrix fixture' USING ERRCODE='ZX001';
            EXCEPTION WHEN SQLSTATE 'ZX001' THEN NULL;
            END;
        END LOOP;
    END LOOP;
END;
$$;
SET LOCAL app.status_reason='';
INSERT INTO ticket_comments(tenant_id,ticket_id,user_id,body) VALUES (100,100,100,'Internal investigation');
SELECT pg_temp.rejects($q$INSERT INTO ticket_comments(tenant_id,ticket_id,user_id,body) VALUES (100,100,200,'Wrong actor')$q$, '23503');
SELECT pg_temp.rejects($q$DELETE FROM ticket_activities WHERE ticket_id=100$q$, 'P0001');
-- PostgreSQL 18 reports RESTRICT deletes as restrict_violation (23001);
-- PostgreSQL 17 reports foreign_key_violation (23503).
SELECT pg_temp.rejects($q$DELETE FROM contacts WHERE id=100$q$,
    CASE WHEN current_setting('server_version_num')::integer >= 180000
        THEN '23001' ELSE '23503' END);
SET LOCAL app.user_id='200';
INSERT INTO tickets(id,tenant_id,client_id,contact_id,created_by_user_id,subject,description) OVERRIDING SYSTEM VALUE VALUES (200,200,200,200,200,'Other tenant','Problem');
SELECT pg_temp.assert_true((SELECT ticket_number=1 FROM tickets WHERE id=100) AND (SELECT ticket_number=1 FROM tickets WHERE id=200), 'tenant numbering');
-- Verify policies as a non-owner, non-superuser, without BYPASSRLS.
CREATE ROLE caselantern_schema_test_runtime NOLOGIN NOSUPERUSER NOBYPASSRLS;
GRANT USAGE ON SCHEMA public TO caselantern_schema_test_runtime;
GRANT SELECT, INSERT, UPDATE ON ALL TABLES IN SCHEMA public TO caselantern_schema_test_runtime;
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO caselantern_schema_test_runtime;
SET LOCAL ROLE caselantern_schema_test_runtime;
SELECT pg_temp.assert_true((SELECT count(*)=0 FROM tickets), 'missing tenant context fails closed');
SET LOCAL app.tenant_id='100';
SET LOCAL app.user_id='100';
SELECT pg_temp.assert_true((SELECT count(*)=1 FROM tickets), 'tenant read isolation');
SELECT pg_temp.assert_true((SELECT count(*)=1 FROM tenants), 'tenant table isolation');
SELECT pg_temp.rejects($q$INSERT INTO clients(tenant_id,client_type,name) VALUES (200,'business','Forbidden')$q$, '42501');
INSERT INTO tickets(tenant_id,client_id,contact_id,created_by_user_id,subject,description) VALUES (100,100,100,100,'Runtime write','With audit');
SELECT pg_temp.assert_true((SELECT max(ticket_number)=2 FROM tickets), 'runtime numbering');
SELECT pg_temp.assert_true((SELECT count(*)=2 FROM ticket_activities WHERE activity_type='created'), 'runtime audit');
UPDATE tickets SET deleted_at=now() WHERE id=100;
SELECT pg_temp.assert_true(EXISTS(SELECT 1 FROM ticket_activities WHERE ticket_id=100 AND new_values->>'deleted_at' IS NOT NULL), 'soft deletion history');
RESET ROLE;
ROLLBACK;
\echo 'Schema checks passed (fixtures rolled back).'
