-- Migration 007: cột snapshot phục vụ xuất ghi chú phát hành theo loại công việc.
-- Chạy lại nhiều lần được (ADD COLUMN IF NOT EXISTS, kiểm tra constraint trước khi thêm).
-- Không sửa snapshot của phiên bản đã chốt: trigger bất biến vẫn có hiệu lực, và
-- bản ghi lịch sử không được điền lại từ dữ liệu hiện tại.

ALTER TABLE release_items ADD COLUMN IF NOT EXISTS snapshot_issue_type VARCHAR(20);
ALTER TABLE release_items ADD COLUMN IF NOT EXISTS snapshot_commit_url TEXT NOT NULL DEFAULT '';

DO $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE c.conname='ck_release_items_snapshot_issue_type' AND t.relname='release_items' AND n.nspname=current_schema()) THEN
  ALTER TABLE release_items ADD CONSTRAINT ck_release_items_snapshot_issue_type
   CHECK (snapshot_issue_type IS NULL OR snapshot_issue_type IN ('bug','task','feature'));
 END IF;
END $$;

-- Chỉ điền cho phiên bản chưa chốt; khi chốt, ReleaseService ghi lại giá trị chính thức.
UPDATE release_items ri
SET snapshot_issue_type=i.issue_type
FROM issues i, releases r
WHERE i.id=ri.issue_id
  AND r.id=ri.release_id
  AND r.status IN ('planned','ready')
  AND ri.snapshot_issue_type IS NULL;

CREATE INDEX IF NOT EXISTS idx_release_items_issue ON release_items(project_id, issue_id);
