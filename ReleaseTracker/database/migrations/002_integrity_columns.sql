-- Migration 002: project-scoped integrity, test versioning and snapshot metadata.
-- The migration is idempotent: it checks existing columns/constraints before adding them.
-- It never deletes rows and never changes primary keys.

DO $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='uq_issues_id_project') THEN
  ALTER TABLE issues ADD CONSTRAINT uq_issues_id_project UNIQUE (id, project_id);
 END IF;
END $$;

ALTER TABLE test_results ADD COLUMN IF NOT EXISTS project_id BIGINT;
ALTER TABLE test_results ADD COLUMN IF NOT EXISTS issue_version INTEGER NOT NULL DEFAULT 0;
UPDATE test_results t
SET project_id=i.project_id
FROM issues i
WHERE i.id=t.issue_id AND t.project_id IS NULL;
ALTER TABLE test_results ALTER COLUMN project_id SET NOT NULL;

DO $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='ck_test_results_issue_version') THEN
  ALTER TABLE test_results ADD CONSTRAINT ck_test_results_issue_version CHECK (issue_version >= 0);
 END IF;
END $$;

DO $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='fk_test_results_issue_project') THEN
  ALTER TABLE test_results ADD CONSTRAINT fk_test_results_issue_project
   FOREIGN KEY (issue_id, project_id) REFERENCES issues(id, project_id) ON DELETE CASCADE;
 END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_test_results_issue_version ON test_results(issue_id, issue_version);
CREATE INDEX IF NOT EXISTS idx_test_results_project ON test_results(project_id);

ALTER TABLE dependencies ADD COLUMN IF NOT EXISTS issue_project_id BIGINT;
ALTER TABLE dependencies ADD COLUMN IF NOT EXISTS depends_on_project_id BIGINT;

DO $$
DECLARE bad_count INTEGER;
BEGIN
 SELECT count(*) INTO bad_count
 FROM dependencies d
 JOIN issues a ON a.id=d.issue_id
 JOIN issues b ON b.id=d.depends_on_id
 WHERE a.project_id <> b.project_id;
 IF bad_count > 0 THEN
  RAISE EXCEPTION 'Dữ liệu dependency khác dự án cần được sửa trước migration: % dòng', bad_count;
 END IF;
END $$;

UPDATE dependencies d
SET issue_project_id=a.project_id, depends_on_project_id=b.project_id
FROM issues a, issues b
WHERE a.id=d.issue_id AND b.id=d.depends_on_id;
ALTER TABLE dependencies ALTER COLUMN issue_project_id SET NOT NULL;
ALTER TABLE dependencies ALTER COLUMN depends_on_project_id SET NOT NULL;

DO $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='ck_dependencies_same_project') THEN
  ALTER TABLE dependencies ADD CONSTRAINT ck_dependencies_same_project
   CHECK (issue_project_id=depends_on_project_id);
 END IF;
END $$;

DO $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='fk_dependencies_issue_project') THEN
  ALTER TABLE dependencies ADD CONSTRAINT fk_dependencies_issue_project
   FOREIGN KEY (issue_id, issue_project_id) REFERENCES issues(id, project_id) ON DELETE CASCADE;
 END IF;
END $$;

DO $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='fk_dependencies_depends_project') THEN
  ALTER TABLE dependencies ADD CONSTRAINT fk_dependencies_depends_project
   FOREIGN KEY (depends_on_id, depends_on_project_id) REFERENCES issues(id, project_id) ON DELETE CASCADE;
 END IF;
END $$;

DO $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='uq_releases_id_project') THEN
  ALTER TABLE releases ADD CONSTRAINT uq_releases_id_project UNIQUE (id, project_id);
 END IF;
END $$;

ALTER TABLE release_items ADD COLUMN IF NOT EXISTS project_id BIGINT;
ALTER TABLE release_items ADD COLUMN IF NOT EXISTS snapshot_commit_url TEXT NOT NULL DEFAULT '';
ALTER TABLE release_items ADD COLUMN IF NOT EXISTS snapshot_test_version INTEGER;
ALTER TABLE release_items ADD COLUMN IF NOT EXISTS snapshot_test_tested_at TIMESTAMPTZ;

DO $$
DECLARE bad_count INTEGER;
BEGIN
 SELECT count(*) INTO bad_count
 FROM release_items ri
 JOIN releases r ON r.id=ri.release_id
 JOIN issues i ON i.id=ri.issue_id
 WHERE r.project_id <> i.project_id;
 IF bad_count > 0 THEN
  RAISE EXCEPTION 'Dữ liệu release_items khác dự án cần được sửa trước migration: % dòng', bad_count;
 END IF;
END $$;

UPDATE release_items ri
SET project_id=r.project_id
FROM releases r
WHERE r.id=ri.release_id AND ri.project_id IS NULL;
ALTER TABLE release_items ALTER COLUMN project_id SET NOT NULL;

DO $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='ck_release_items_snapshot_test_version') THEN
  ALTER TABLE release_items ADD CONSTRAINT ck_release_items_snapshot_test_version
   CHECK (snapshot_test_version IS NULL OR snapshot_test_version >= 0);
 END IF;
END $$;

DO $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='fk_release_items_release_project') THEN
  ALTER TABLE release_items ADD CONSTRAINT fk_release_items_release_project
   FOREIGN KEY (release_id, project_id) REFERENCES releases(id, project_id) ON DELETE CASCADE;
 END IF;
END $$;

DO $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='fk_release_items_issue_project') THEN
  ALTER TABLE release_items ADD CONSTRAINT fk_release_items_issue_project
   FOREIGN KEY (issue_id, project_id) REFERENCES issues(id, project_id);
 END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_release_items_project ON release_items(project_id);
CREATE INDEX IF NOT EXISTS idx_audit_log_project_created ON audit_log(project_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_dependencies_project ON dependencies(issue_project_id, depends_on_project_id);
