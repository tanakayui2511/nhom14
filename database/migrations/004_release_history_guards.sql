-- Migration 004: preserve release-level history and close DB trigger bypasses.
-- Existing releases intentionally keep a NULL project_name_snapshot: the
-- original historical name is unknown and must not be backfilled from live data.

ALTER TABLE releases ADD COLUMN IF NOT EXISTS project_name_snapshot TEXT;

CREATE OR REPLACE FUNCTION rt_guard_release_items() RETURNS trigger AS $$
DECLARE
 source_status TEXT;
 target_status TEXT;
BEGIN
 IF TG_OP IN ('UPDATE', 'DELETE') THEN
  SELECT status INTO source_status FROM releases WHERE id=OLD.release_id;
 END IF;
 IF TG_OP IN ('INSERT', 'UPDATE') THEN
  SELECT status INTO target_status FROM releases WHERE id=NEW.release_id;
 END IF;

 IF source_status = 'released' OR target_status = 'released' THEN
  RAISE EXCEPTION 'RELEASE_ITEMS_IMMUTABLE';
 END IF;
 IF TG_OP = 'INSERT' AND target_status <> 'planned' THEN
  RAISE EXCEPTION 'RELEASE_ITEMS_REQUIRE_PLANNED';
 END IF;
 IF TG_OP = 'DELETE' AND source_status <> 'planned' THEN
  RAISE EXCEPTION 'RELEASE_ITEMS_REQUIRE_PLANNED';
 END IF;
 IF TG_OP = 'UPDATE' AND (source_status NOT IN ('planned','ready') OR target_status NOT IN ('planned','ready')) THEN
  RAISE EXCEPTION 'RELEASE_ITEMS_NOT_EDITABLE';
 END IF;
 RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION rt_guard_release_row() RETURNS trigger AS $$
BEGIN
 IF TG_OP = 'DELETE' AND OLD.status = 'released' THEN
  RAISE EXCEPTION 'RELEASE_IMMUTABLE';
 END IF;
 IF TG_OP = 'UPDATE' THEN
  IF OLD.status = 'released' THEN
   RAISE EXCEPTION 'RELEASE_IMMUTABLE';
  END IF;
  IF NEW.status = 'released' THEN
   IF OLD.status <> 'ready' THEN
    RAISE EXCEPTION 'RELEASE_INVALID_TRANSITION';
   END IF;
   IF NEW.checked_at IS NULL OR NEW.released_at IS NULL THEN
    RAISE EXCEPTION 'RELEASE_METADATA_REQUIRED';
   END IF;
  END IF;
 END IF;
 RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;
