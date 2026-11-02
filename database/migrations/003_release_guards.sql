-- Migration 003: database-level protection for release lifecycle and snapshot immutability.
-- These triggers are a second line of defence behind ReleaseService transactions.

CREATE OR REPLACE FUNCTION rt_guard_release_items() RETURNS trigger AS $$
DECLARE
 rel_status TEXT;
BEGIN
 SELECT status INTO rel_status FROM releases
 WHERE id = COALESCE(NEW.release_id, OLD.release_id);
 IF rel_status = 'released' THEN
  RAISE EXCEPTION 'RELEASE_ITEMS_IMMUTABLE';
 END IF;
 IF TG_OP IN ('INSERT','DELETE') AND rel_status <> 'planned' THEN
  RAISE EXCEPTION 'RELEASE_ITEMS_REQUIRE_PLANNED';
 END IF;
 IF TG_OP = 'UPDATE' AND rel_status NOT IN ('planned','ready') THEN
  RAISE EXCEPTION 'RELEASE_ITEMS_NOT_EDITABLE';
 END IF;
 RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_release_items_guard ON release_items;
CREATE TRIGGER trg_release_items_guard
BEFORE INSERT OR UPDATE OR DELETE ON release_items
FOR EACH ROW EXECUTE FUNCTION rt_guard_release_items();

CREATE OR REPLACE FUNCTION rt_guard_release_row() RETURNS trigger AS $$
BEGIN
 IF TG_OP = 'DELETE' AND OLD.status = 'released' THEN
  RAISE EXCEPTION 'RELEASE_IMMUTABLE';
 END IF;
 IF TG_OP = 'UPDATE' AND OLD.status = 'released' THEN
  RAISE EXCEPTION 'RELEASE_IMMUTABLE';
 END IF;
 RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_release_row_guard ON releases;
CREATE TRIGGER trg_release_row_guard
BEFORE UPDATE OR DELETE ON releases
FOR EACH ROW EXECUTE FUNCTION rt_guard_release_row();
