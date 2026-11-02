-- Migration 005: reject direct INSERT of releases that bypass the lifecycle.
-- ReleaseService always creates a release in 'planned'.  A direct INSERT with
-- 'ready' or 'released' would skip the service checks, snapshot and audit, so
-- the database rejects it as a second line of defence.

CREATE OR REPLACE FUNCTION rt_guard_release_insert() RETURNS trigger AS $$
BEGIN
 IF NEW.status <> 'planned' THEN
  RAISE EXCEPTION 'RELEASE_INVALID_INITIAL_STATUS';
 END IF;
 RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_release_insert_guard ON releases;
CREATE TRIGGER trg_release_insert_guard
BEFORE INSERT ON releases
FOR EACH ROW EXECUTE FUNCTION rt_guard_release_insert();
