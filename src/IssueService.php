<?php

declare(strict_types=1);

require_once __DIR__ . '/App.php';
require_once __DIR__ . '/DependencyService.php';
require_once __DIR__ . '/TestEvidence.php';

final class IssueService
{
    public static function create(
        PgSql\Connection $connection,
        int $projectId,
        int $userId,
        ?string $role,
        array $input
    ): int {
        $title = postString($input, 'title', 200, true, 'Tiêu đề');
        $description = postString($input, 'description', 20000, false, 'Mô tả');
        $steps = postString($input, 'steps', 20000, false, 'Các bước tái hiện');
        $type = postEnum($input, 'issue_type', ISSUE_TYPES, 'Loại công việc');
        $priority = postEnum($input, 'priority', ISSUE_PRIORITIES, 'Mức ưu tiên');
        $assigneeId = postOptionalInt($input, 'assignee_id', 1, PHP_INT_MAX, 'Người phụ trách');
        $commitUrl = postUrl($input, 'commit_url', 500, 'Commit / PR URL');
        return transaction($connection, function () use (
            $connection,
            $projectId,
            $userId,
            $commitUrl,
            $title,
            $description,
            $steps,
            $type,
            $priority,
            $assigneeId
        ): int {
            projectLock($connection, $projectId);
            requireProjectRoleInTransaction(
                $connection,
                $projectId,
                $userId,
                ['developer', 'tester', 'release_manager'],
                'Bạn không có quyền tạo công việc trong dự án này.'
            );
            if ($type === 'bug' && $steps === '') {
                throw new ValidationException(
                    'Lỗi (bug) bắt buộc phải có các bước tái hiện. '
                    . 'Gợi ý: Bước tái hiện / Kết quả mong đợi / Kết quả thực tế.'
                );
            }
            if ($assigneeId !== null && !canAssignToProject($connection, $projectId, $assigneeId)) {
                throw new ValidationException('Người được phân công không thuộc dự án này.');
            }
            self::assertTitleAvailable($connection, $projectId, $title, null);
            $result = queryParams(
                $connection,
                'INSERT INTO issues(
                    project_id,title,description,reproduction_steps,issue_type,priority,
                    reporter_id,assignee_id,commit_url
                 ) VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9) RETURNING id',
                [
                    (string)$projectId,
                    $title,
                    $description,
                    $steps,
                    $type,
                    $priority,
                    (string)$userId,
                    $assigneeId === null ? null : (string)$assigneeId,
                    $commitUrl,
                ]
            );
            $id = (int)pg_fetch_result($result, 0, 0);
            audit($connection, $projectId, $userId, 'issue_created', 'issue', $id, [
                'title' => $title,
                'priority' => $priority,
                'type' => $type,
                'assignee_id' => $assigneeId,
            ]);
            return $id;
        });
    }

    /**
     * Cập nhật công việc có kiểm soát phiên bản (optimistic locking).
     *
     * - $baseVersion là version người dùng nhìn thấy khi mở form. Nếu trong
     *   CSDL đã khác thì ném VersionConflictException kèm bản hiện tại, không
     *   bao giờ âm thầm ghi đè.
     * - $input['overwrite']='1' chỉ là cờ ghi nhận "ghi đè có chủ đích" sau khi
     *   người dùng đã xem bản mới nhất; version gửi lên vẫn phải khớp.
     * - Mọi trường bị đổi được ghi vào audit (giá trị cũ → mới) để hiển thị
     *   lịch sử thay đổi.
     */
    public static function update(
        PgSql\Connection $connection,
        int $projectId,
        int $userId,
        ?string $role,
        int $issueId,
        int $baseVersion,
        array $input
    ): int {
        if ($baseVersion < 1) {
            throw new ValidationException('Phiên bản dữ liệu không hợp lệ.');
        }
        $title = postString($input, 'title', 200, true, 'Tiêu đề');
        $description = postString($input, 'description', 20000, false, 'Mô tả');
        $steps = postString($input, 'steps', 20000, false, 'Các bước tái hiện');
        $priority = postEnum($input, 'priority', ISSUE_PRIORITIES, 'Mức ưu tiên');
        $status = postEnum($input, 'status', ISSUE_STATUSES, 'Trạng thái');
        $assigneeId = postOptionalInt($input, 'assignee_id', 1, PHP_INT_MAX, 'Người phụ trách');
        $commitUrl = postUrl($input, 'commit_url', 500, 'Commit / PR URL');
        $overwrite = (string)($input['overwrite'] ?? '0') === '1';
        return transaction($connection, function () use (
            $connection,
            $projectId,
            $userId,
            $issueId,
            $baseVersion,
            $title,
            $description,
            $steps,
            $priority,
            $status,
            $assigneeId,
            $commitUrl,
            $overwrite
        ): int {
            projectLock($connection, $projectId);
            $currentRole = requireProjectMembershipInTransaction(
                $connection,
                $projectId,
                $userId,
                'Bạn không có quyền sửa công việc này.'
            );
            $issue = self::issueForUpdate($connection, $projectId, $issueId);
            $reporterId = $issue['reporter_id'] === null ? null : (int)$issue['reporter_id'];
            $oldAssignee = $issue['assignee_id'] === null ? null : (int)$issue['assignee_id'];
            if (!canEditIssue($currentRole, $userId, $reporterId, $oldAssignee)) {
                throw new ForbiddenException('Bạn không có quyền sửa công việc này.');
            }
            if ($assigneeId !== null && !canAssignToProject($connection, $projectId, $assigneeId)) {
                throw new ValidationException('Người được phân công không thuộc dự án này.');
            }
            $oldVersion = (int)$issue['version'];
            if ($oldVersion !== $baseVersion) {
                throw self::conflict($connection, $issue);
            }
            if ((string)$issue['issue_type'] === 'bug' && $steps === '') {
                throw new ValidationException('Lỗi (bug) bắt buộc phải có các bước tái hiện.');
            }
            $oldStatus = (string)$issue['status'];
            $oldPriority = (string)$issue['priority'];
            $contentChanged = (string)$issue['title'] !== $title
                || (string)$issue['description'] !== $description
                || (string)$issue['reproduction_steps'] !== $steps
                || $oldPriority !== $priority
                || $oldAssignee !== $assigneeId
                || (string)$issue['commit_url'] !== $commitUrl;
            $changed = $contentChanged || $oldStatus !== $status;
            if (!$changed) {
                return $oldVersion;
            }
            self::assertCanChangePriority($currentRole, $oldPriority, $priority);
            self::assertTitleAvailable($connection, $projectId, $title, $issueId);
            if ($contentChanged && in_array($status, ['verified', 'closed'], true)) {
                throw new ValidationException(
                    'Nội dung công việc đã thay đổi. Phải kiểm thử lại ở phiên bản mới '
                    . 'trước khi chuyển sang verified hoặc closed.'
                );
            }
            if ($status === 'verified' && $oldStatus !== 'verified') {
                throw new ValidationException(
                    'Trạng thái verified (Đã xác nhận) chỉ được đặt bằng cách ghi kết quả kiểm thử passed. '
                    . 'Vui lòng dùng biểu mẫu kết quả kiểm thử thay vì sửa trạng thái trực tiếp.'
                );
            }
            $result = queryParams(
                $connection,
                'UPDATE issues
                 SET title=$1,description=$2,reproduction_steps=$3,priority=$4,status=$5,
                     assignee_id=$6,commit_url=$7,version=version+1,updated_at=now()
                 WHERE id=$8 AND project_id=$9 AND version=$10
                 RETURNING version',
                [
                    $title,
                    $description,
                    $steps,
                    $priority,
                    $status,
                    $assigneeId === null ? null : (string)$assigneeId,
                    $commitUrl,
                    (string)$issueId,
                    (string)$projectId,
                    (string)$baseVersion,
                ]
            );
            if (pg_affected_rows($result) !== 1) {
                throw self::conflict($connection, $issue);
            }
            $newVersion = (int)pg_fetch_result($result, 0, 0);
            DependencyService::invalidateReadyReleases($connection, $projectId, [$issueId]);
            $changes = self::diff($connection, $issue, [
                'title' => $title,
                'description' => $description,
                'reproduction_steps' => $steps,
                'priority' => $priority,
                'status' => $status,
                'assignee_id' => $assigneeId,
                'commit_url' => $commitUrl,
            ]);
            audit($connection, $projectId, $userId, 'issue_updated', 'issue', $issueId, [
                'from_version' => $oldVersion,
                'to_version' => $newVersion,
                'status' => $status,
                'changes' => $changes,
                'overwrite_after_conflict' => $overwrite,
            ]);
            if ($oldPriority !== $priority) {
                self::auditPriority($connection, $projectId, $userId, $issueId, $oldPriority, $priority);
            }
            return $newVersion;
        });
    }

    /**
     * Chỉ đổi mức ưu tiên (có kiểm soát version). Dùng chung quy tắc với
     * update(): công việc đang là blocker chỉ được tester, release manager
     * hoặc admin đổi ưu tiên.
     */
    public static function changePriority(
        PgSql\Connection $connection,
        int $projectId,
        int $userId,
        int $issueId,
        int $baseVersion,
        string $newPriority
    ): int {
        $newPriority = validateEnum($newPriority, ISSUE_PRIORITIES, 'Mức ưu tiên');
        return transaction($connection, function () use (
            $connection,
            $projectId,
            $userId,
            $issueId,
            $baseVersion,
            $newPriority
        ): int {
            projectLock($connection, $projectId);
            $currentRole = requireProjectMembershipInTransaction(
                $connection,
                $projectId,
                $userId,
                'Bạn không có quyền sửa công việc này.'
            );
            $issue = self::issueForUpdate($connection, $projectId, $issueId);
            $reporterId = $issue['reporter_id'] === null ? null : (int)$issue['reporter_id'];
            $assignee = $issue['assignee_id'] === null ? null : (int)$issue['assignee_id'];
            if (!canEditIssue($currentRole, $userId, $reporterId, $assignee) && !canTestIssue($currentRole)) {
                throw new ForbiddenException('Bạn không có quyền sửa công việc này.');
            }
            $oldVersion = (int)$issue['version'];
            if ($oldVersion !== $baseVersion) {
                throw self::conflict($connection, $issue);
            }
            $oldPriority = (string)$issue['priority'];
            if ($oldPriority === $newPriority) {
                return $oldVersion;
            }
            self::assertCanChangePriority($currentRole, $oldPriority, $newPriority);
            $result = queryParams(
                $connection,
                'UPDATE issues SET priority=$1,version=version+1,updated_at=now()
                 WHERE id=$2 AND project_id=$3 AND version=$4
                 RETURNING version',
                [$newPriority, (string)$issueId, (string)$projectId, (string)$baseVersion]
            );
            if (pg_affected_rows($result) !== 1) {
                throw self::conflict($connection, $issue);
            }
            $newVersion = (int)pg_fetch_result($result, 0, 0);
            DependencyService::invalidateReadyReleases($connection, $projectId, [$issueId]);
            audit($connection, $projectId, $userId, 'issue_updated', 'issue', $issueId, [
                'from_version' => $oldVersion,
                'to_version' => $newVersion,
                'status' => (string)$issue['status'],
                'changes' => ['priority' => ['old' => $oldPriority, 'new' => $newPriority]],
            ]);
            self::auditPriority($connection, $projectId, $userId, $issueId, $oldPriority, $newPriority);
            return $newVersion;
        });
    }

    /**
     * Quy tắc chống lách điều kiện phát hành: developer không được tự hạ
     * (hay đổi) ưu tiên của một công việc đang là blocker.
     */
    public static function assertCanChangePriority(?string $role, string $oldPriority, string $newPriority): void
    {
        if ($oldPriority === 'blocker' && $newPriority !== 'blocker'
            && !hasProjectRole($role, ['tester', 'release_manager'])) {
            throw new ForbiddenException(
                'Chỉ kiểm thử viên, quản lý phát hành hoặc quản trị được đổi mức ưu tiên '
                . 'của công việc đang là Blocker.'
            );
        }
    }

    public static function addComment(
        PgSql\Connection $connection,
        int $projectId,
        int $userId,
        ?string $role,
        int $issueId,
        array $input
    ): int {
        $body = postString($input, 'body', 2000, true, 'Nội dung bình luận');
        return transaction($connection, function () use (
            $connection,
            $projectId,
            $userId,
            $issueId,
            $body
        ): int {
            projectLock($connection, $projectId);
            requireProjectMembershipInTransaction(
                $connection,
                $projectId,
                $userId,
                'Bạn không có quyền bình luận trong dự án này.'
            );
            $result = queryParams(
                $connection,
                'INSERT INTO comments(issue_id,user_id,body)
                 SELECT id,$2,$3 FROM issues WHERE id=$1 AND project_id=$4
                 RETURNING id',
                [(string)$issueId, (string)$userId, $body, (string)$projectId]
            );
            if (pg_affected_rows($result) !== 1) {
                throw new NotFoundException('Không tìm thấy công việc trong dự án.');
            }
            $commentId = (int)pg_fetch_result($result, 0, 0);
            audit($connection, $projectId, $userId, 'comment_added', 'comment', $commentId, [
                'issue_id' => $issueId,
            ]);
            return $commentId;
        });
    }

    public static function recordTest(
        PgSql\Connection $connection,
        int $projectId,
        int $userId,
        ?string $role,
        int $issueId,
        array $input
    ): int {
        $expectedVersion = postInt($input, 'version', 1, PHP_INT_MAX, 'Phiên bản dữ liệu');
        $resultValue = postEnum($input, 'result', TEST_RESULTS, 'Kết quả kiểm thử');
        $notes = postString($input, 'notes', 5000, false, 'Ghi chú kiểm thử');
        return transaction($connection, function () use (
            $connection,
            $projectId,
            $userId,
            $issueId,
            $expectedVersion,
            $resultValue,
            $notes
        ): int {
            projectLock($connection, $projectId);
            $testerRole = requireProjectRoleInTransaction(
                $connection,
                $projectId,
                $userId,
                ['tester', 'release_manager'],
                'Chỉ tester, release manager hoặc admin được ghi kết quả kiểm thử.'
            );
            $issue = self::issueForUpdate($connection, $projectId, $issueId);
            if ((string)$issue['status'] === 'closed') {
                throw new ValidationException('Công việc đã đóng, không thể ghi thêm kết quả kiểm thử.');
            }
            $oldVersion = (int)$issue['version'];
            if ($oldVersion !== $expectedVersion) {
                throw new ConflictException(
                    'XUNG ĐỘT: công việc đã được cập nhật sau khi bạn kiểm thử. '
                    . 'Vui lòng tải lại dữ liệu và kiểm thử lại phiên bản hiện tại.'
                );
            }
            $newVersion = $oldVersion + 1;
            // passed => verified (đã xác nhận). failed/blocked => trả lại cho
            // người sửa (in_progress) thay vì để ở retest.
            $newStatus = $resultValue === 'passed' ? 'verified' : 'in_progress';
            $oldStatus = (string)$issue['status'];
            $result = queryParams(
                $connection,
                'INSERT INTO test_results(issue_id,project_id,tester_id,result,notes,issue_version)
                 VALUES($1,$2,$3,$4,$5,$6) RETURNING id',
                [
                    (string)$issueId,
                    (string)$projectId,
                    (string)$userId,
                    $resultValue,
                    $notes,
                    (string)$newVersion,
                ]
            );
            if (pg_affected_rows($result) !== 1) {
                throw new ConflictException('Không thể lưu kết quả kiểm thử.');
            }
            $testId = (int)pg_fetch_result($result, 0, 0);
            $updated = queryParams(
                $connection,
                'UPDATE issues SET status=$1,version=$2,updated_at=now()
                 WHERE id=$3 AND project_id=$4 AND version=$5',
                [
                    $newStatus,
                    (string)$newVersion,
                    (string)$issueId,
                    (string)$projectId,
                    (string)$expectedVersion,
                ]
            );
            if (pg_affected_rows($updated) !== 1) {
                throw new ConflictException(
                    'XUNG ĐỘT: công việc đã được người khác cập nhật trong lúc kiểm thử.'
                );
            }
            DependencyService::invalidateReadyReleases($connection, $projectId, [$issueId]);
            audit($connection, $projectId, $userId, 'test_result_recorded', 'test_result', $testId, [
                'issue_id' => $issueId,
                'result' => $resultValue,
                'issue_version' => $newVersion,
                'tester_role' => $testerRole,
                'changes' => $oldStatus === $newStatus
                    ? []
                    : ['status' => ['old' => $oldStatus, 'new' => $newStatus]],
            ]);
            return $newVersion;
        });
    }

    public static function latestTest(PgSql\Connection $connection, int $issueId): ?array
    {
        $latest = queryOne(
            $connection,
            'SELECT t.*,u.full_name AS tester_name,(t.issue_version=i.version) AS is_current,
                    a.user_id AS audit_user_id,a.details AS audit_details
             FROM test_results t
             JOIN issues i ON i.id=t.issue_id
             JOIN users u ON u.id=t.tester_id
             LEFT JOIN LATERAL (
                 SELECT al.user_id,al.details
                 FROM audit_log al
                 WHERE al.action=\'test_result_recorded\'
                   AND al.entity_type=\'test_result\'
                   AND al.entity_id=t.id
                   AND al.project_id=t.project_id
                 ORDER BY al.id DESC
                 LIMIT 1
             ) a ON true
             WHERE t.issue_id=$1
             ORDER BY t.id DESC
             LIMIT 1',
            [(string)$issueId]
        );
        if ($latest === null) {
            return null;
        }
        $audit = $latest['audit_user_id'] === null && $latest['audit_details'] === null
            ? null
            : ['user_id' => $latest['audit_user_id'], 'details' => $latest['audit_details']];
        $latest['test_provenance'] = TestEvidence::provenance($latest, $audit);
        $latest['test_provenance_trusted'] = $latest['test_provenance'] === TestEvidence::PROVENANCE_AUDITED;
        return $latest;
    }

    public static function hasValidPassedTest(
        PgSql\Connection $connection,
        int $issueId,
        int $issueVersion
    ): bool {
        $latest = self::latestTest($connection, $issueId);
        return $latest !== null
            && (string)$latest['result'] === 'passed'
            && (int)$latest['issue_version'] === $issueVersion
            && (bool)$latest['test_provenance_trusted'];
    }

    /**
     * Lịch sử thay đổi của một công việc, đọc từ audit_log (chỉ ghi thêm).
     * Mọi thành viên dự án đều xem được.
     */
    public static function history(PgSql\Connection $connection, int $projectId, int $issueId): array
    {
        return queryAll(
            $connection,
            "SELECT a.id,a.action,a.entity_type,a.entity_id,a.details,a.created_at,
                    COALESCE(u.full_name,'Hệ thống') AS user_name
             FROM audit_log a
             LEFT JOIN users u ON u.id=a.user_id
             WHERE a.project_id=\$1
               AND (
                   (a.entity_type='issue' AND a.entity_id=\$2)
                   OR (a.details->>'issue_id')=\$2::text
               )
             ORDER BY a.id DESC
             LIMIT 200",
            [(string)$projectId, (string)$issueId]
        );
    }

    /** Các phiên bản ĐÃ CHỐT từng chứa công việc này (tra ngược release_items). */
    public static function releasedVersions(PgSql\Connection $connection, int $projectId, int $issueId): array
    {
        return queryAll(
            $connection,
            "SELECT r.id,r.version_name,r.released_at,ri.snapshot_status,ri.snapshot_test_result
             FROM release_items ri
             JOIN releases r ON r.id=ri.release_id AND r.project_id=ri.project_id
             WHERE ri.project_id=\$1 AND ri.issue_id=\$2 AND r.status='released'
             ORDER BY r.released_at DESC, r.id DESC",
            [(string)$projectId, (string)$issueId]
        );
    }

    /** Bản hiện tại trong CSDL (dạng hiển thị) để so sánh khi xung đột. */
    public static function currentForConflict(PgSql\Connection $connection, array $issue): array
    {
        $assignee = null;
        if ($issue['assignee_id'] !== null) {
            $row = queryOne($connection, 'SELECT full_name FROM users WHERE id=$1', [(string)$issue['assignee_id']]);
            $assignee = $row === null ? null : (string)$row['full_name'];
        }
        return [
            'title' => (string)$issue['title'],
            'description' => (string)$issue['description'],
            'steps' => (string)$issue['reproduction_steps'],
            'priority' => (string)$issue['priority'],
            'status' => (string)$issue['status'],
            'assignee_id' => $issue['assignee_id'] === null ? '' : (string)$issue['assignee_id'],
            'assignee' => $assignee,
            'commit_url' => (string)$issue['commit_url'],
            'version' => (int)$issue['version'],
        ];
    }

    private static function conflict(PgSql\Connection $connection, array $issueRowAtLock): VersionConflictException
    {
        // Đọc lại bản mới nhất (dòng đã bị khóa FOR UPDATE nên là bản chuẩn).
        $fresh = queryOne(
            $connection,
            'SELECT * FROM issues WHERE id=$1',
            [(string)$issueRowAtLock['id']]
        ) ?? $issueRowAtLock;
        return new VersionConflictException(
            'XUNG ĐỘT: công việc đã được người khác cập nhật (phiên bản hiện tại: '
            . (int)$fresh['version'] . '). Dữ liệu của bạn chưa được lưu. '
            . 'Hãy so sánh với bản mới nhất rồi chọn tải lại hoặc ghi đè có chủ đích.',
            self::currentForConflict($connection, $fresh)
        );
    }

    /**
     * Liệt kê các trường bị đổi: [field => ['old' => ..., 'new' => ...]].
     * Người phụ trách được lưu bằng họ tên để lịch sử vẫn đọc được về sau.
     */
    private static function diff(PgSql\Connection $connection, array $old, array $new): array
    {
        $changes = [];
        foreach ($new as $field => $value) {
            $before = $old[$field];
            if ($field === 'assignee_id') {
                $beforeId = $before === null ? null : (int)$before;
                if ($beforeId === $value) {
                    continue;
                }
                $changes['assignee'] = [
                    'old' => self::userName($connection, $beforeId),
                    'new' => self::userName($connection, $value),
                ];
                continue;
            }
            if ((string)$before !== (string)$value) {
                $changes[$field] = ['old' => (string)$before, 'new' => (string)$value];
            }
        }
        return $changes;
    }

    private static function userName(PgSql\Connection $connection, ?int $userId): string
    {
        if ($userId === null) {
            return '';
        }
        $row = queryOne($connection, 'SELECT full_name FROM users WHERE id=$1', [(string)$userId]);
        return $row === null ? ('#' . $userId) : (string)$row['full_name'];
    }

    private static function assertTitleAvailable(
        PgSql\Connection $connection,
        int $projectId,
        string $title,
        ?int $exceptIssueId
    ): void {
        $row = queryOne(
            $connection,
            'SELECT id FROM issues WHERE project_id=$1 AND title=$2 AND id<>$3',
            [(string)$projectId, $title, (string)($exceptIssueId ?? 0)]
        );
        if ($row !== null) {
            throw new ConflictException('Dự án đã có công việc #' . (int)$row['id'] . ' cùng tiêu đề.');
        }
    }

    /** Audit riêng cho đổi ưu tiên; ReleaseService::check dựa vào bản ghi này. */
    private static function auditPriority(
        PgSql\Connection $connection,
        int $projectId,
        int $userId,
        int $issueId,
        string $old,
        string $new
    ): void {
        audit($connection, $projectId, $userId, 'priority_changed', 'issue', $issueId, [
            'old' => $old,
            'new' => $new,
        ]);
    }

    private static function issueForUpdate(
        PgSql\Connection $connection,
        int $projectId,
        int $issueId
    ): array {
        $issue = queryOne(
            $connection,
            'SELECT * FROM issues WHERE id=$1 AND project_id=$2 FOR UPDATE',
            [(string)$issueId, (string)$projectId]
        );
        if ($issue === null) {
            throw new NotFoundException('Không tìm thấy công việc trong dự án.');
        }
        return $issue;
    }
}
