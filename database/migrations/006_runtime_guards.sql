-- Migration 006: runtime guards for release lifecycle and audit immutability.
-- The application marks approved transitions with a transaction-local setting.
-- Deployment must still run the application with a non-owner role and revoke
-- direct lifecycle UPDATE privileges from that role.

CREATE OR REPLACE FUNCTION rt_guard_audit_log() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'AUDIT_LOG_APPEND_ONLY';
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_audit_log_append_only ON audit_log;
CREATE TRIGGER trg_audit_log_append_only
BEFORE UPDATE OR DELETE ON audit_log
FOR EACH ROW EXECUTE FUNCTION rt_guard_audit_log();

CREATE OR REPLACE FUNCTION rt_guard_release_lifecycle() RETURNS trigger AS $$
DECLARE
    authorized TEXT;
BEGIN
    authorized := current_setting('rt.release_transition', true);
    IF OLD.status <> NEW.status AND authorized IS DISTINCT FROM 'service' THEN
        RAISE EXCEPTION 'RELEASE_LIFECYCLE_SERVICE_ONLY';
    END IF;
    IF OLD.status = 'released' THEN
        RAISE EXCEPTION 'RELEASE_IMMUTABLE';
    END IF;
    IF NEW.status = 'released' AND authorized IS DISTINCT FROM 'service' THEN
        RAISE EXCEPTION 'RELEASE_LIFECYCLE_SERVICE_ONLY';
    END IF;
    IF OLD.status = 'planned' AND NEW.status = 'released' THEN
        RAISE EXCEPTION 'RELEASE_INVALID_TRANSITION';
    END IF;
    IF NEW.status = 'released' AND OLD.status <> 'ready' THEN
        RAISE EXCEPTION 'RELEASE_INVALID_TRANSITION';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_release_runtime_lifecycle ON releases;
CREATE TRIGGER trg_release_runtime_lifecycle
BEFORE UPDATE ON releases
FOR EACH ROW EXECUTE FUNCTION rt_guard_release_lifecycle();

-- These REVOKEs are effective for non-owner runtime roles. PostgreSQL owners
-- retain implicit privileges, so deployment must use a distinct app role.
REVOKE UPDATE(status, checked_at, released_at, project_name_snapshot) ON releases FROM PUBLIC;
REVOKE UPDATE, DELETE ON audit_log FROM PUBLIC;
