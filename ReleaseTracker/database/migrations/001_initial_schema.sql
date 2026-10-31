-- Migration 001: baseline schema for ReleaseTracker.
-- Safe to apply to an existing database because every object uses IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS users (
 id BIGSERIAL PRIMARY KEY, username VARCHAR(60) UNIQUE NOT NULL,
 full_name VARCHAR(120) NOT NULL, password_hash TEXT NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS projects (
 id BIGSERIAL PRIMARY KEY, name VARCHAR(160) NOT NULL UNIQUE,
 description TEXT NOT NULL DEFAULT '', created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS project_members (
 project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
 user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 role VARCHAR(20) NOT NULL CHECK(role IN ('developer','tester','release_manager','admin')),
 PRIMARY KEY(project_id,user_id)
);
CREATE TABLE IF NOT EXISTS issues (
 id BIGSERIAL PRIMARY KEY, project_id BIGINT NOT NULL REFERENCES projects(id),
 title VARCHAR(200) NOT NULL, description TEXT NOT NULL DEFAULT '',
 reproduction_steps TEXT NOT NULL DEFAULT '',
 issue_type VARCHAR(20) NOT NULL DEFAULT 'bug' CHECK(issue_type IN ('bug','task','feature')),
 priority VARCHAR(20) NOT NULL DEFAULT 'medium' CHECK(priority IN ('blocker','critical','high','medium','low')),
 status VARCHAR(20) NOT NULL DEFAULT 'open' CHECK(status IN ('open','in_progress','resolved','retest','verified','closed')),
 reporter_id BIGINT REFERENCES users(id), assignee_id BIGINT REFERENCES users(id),
 commit_url TEXT NOT NULL DEFAULT '', version INTEGER NOT NULL DEFAULT 1,
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS dependencies (
 issue_id BIGINT NOT NULL REFERENCES issues(id) ON DELETE CASCADE,
 depends_on_id BIGINT NOT NULL REFERENCES issues(id) ON DELETE CASCADE,
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 PRIMARY KEY(issue_id,depends_on_id), CHECK(issue_id <> depends_on_id)
);
CREATE TABLE IF NOT EXISTS comments (
 id BIGSERIAL PRIMARY KEY, issue_id BIGINT NOT NULL REFERENCES issues(id) ON DELETE CASCADE,
 user_id BIGINT NOT NULL REFERENCES users(id), body TEXT NOT NULL CHECK(length(trim(body))>0),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS test_results (
 id BIGSERIAL PRIMARY KEY, issue_id BIGINT NOT NULL REFERENCES issues(id) ON DELETE CASCADE,
 tester_id BIGINT NOT NULL REFERENCES users(id),
 result VARCHAR(20) NOT NULL CHECK(result IN ('passed','failed','blocked')),
 notes TEXT NOT NULL DEFAULT '', tested_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS releases (
 id BIGSERIAL PRIMARY KEY, project_id BIGINT NOT NULL REFERENCES projects(id),
 version_name VARCHAR(80) NOT NULL, description TEXT NOT NULL DEFAULT '',
 status VARCHAR(20) NOT NULL DEFAULT 'planned'
 CHECK(status IN ('planned','ready','released')),
 created_by BIGINT REFERENCES users(id), created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 checked_at TIMESTAMPTZ, released_at TIMESTAMPTZ, UNIQUE(project_id,version_name)
);
CREATE TABLE IF NOT EXISTS release_items (
 id BIGSERIAL PRIMARY KEY, release_id BIGINT NOT NULL REFERENCES releases(id) ON DELETE CASCADE,
 issue_id BIGINT NOT NULL REFERENCES issues(id), snapshot_title VARCHAR(200) NOT NULL,
 snapshot_status VARCHAR(20) NOT NULL, snapshot_priority VARCHAR(20) NOT NULL,
 snapshot_assignee VARCHAR(120) NOT NULL DEFAULT '', snapshot_description TEXT NOT NULL DEFAULT '',
 snapshot_test_result VARCHAR(20) NOT NULL DEFAULT 'not_tested',
 UNIQUE(release_id,issue_id)
);
CREATE TABLE IF NOT EXISTS audit_log (
 id BIGSERIAL PRIMARY KEY, project_id BIGINT REFERENCES projects(id), user_id BIGINT REFERENCES users(id),
 action VARCHAR(80) NOT NULL, entity_type VARCHAR(40) NOT NULL, entity_id BIGINT,
 details JSONB NOT NULL DEFAULT '{}'::jsonb, created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_issues_project_title ON issues(project_id,title);
CREATE INDEX IF NOT EXISTS idx_issues_project_status ON issues(project_id,status);
CREATE INDEX IF NOT EXISTS idx_issues_assignee ON issues(assignee_id);
CREATE INDEX IF NOT EXISTS idx_release_items_release ON release_items(release_id);
