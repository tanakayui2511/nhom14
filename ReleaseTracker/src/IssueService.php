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
        $type = postEnum($input, 'issue_type', issueTypes(), 'Loại công việc');
        $priority = postEnum($input, 'priority', issuePriorities(), 'Mức ưu tiên');
        $assigneeId = postOptionalInt($input, 'assignee_id', 1, PHP_INT_MAX, 'Người phụ trách');
        return transaction($connection, function () use (
            $connection,
            $projectId,
            $userId,
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
            if ($assigneeId !== null && !canAssignToProject($connection, $projectId, $assigneeId)) {
                throw new ValidationException('Người được phân công không thuộc dự án này.');
            }
            $result = queryParams(
                $connection,
                'INSERT INTO issues(
                    project_id,title,description,reproduction_steps,issue_type,priority,
                    reporter_id,assignee_id
                 ) VALUES($1,$2,$3,$4,$5,$6,$7,$8) RETURNING id',
                [
                    (string)$projectId,
                    $title,
                    $description,
                    $steps,
                    $type,
                    $priority,
                    (string)$userId,
                    $assigneeId === null ? null : (string)$assigneeId,
                ]
            );
            $id = (int)pg_fetch_result($result, 0, 0);
            audit($connection, $projectId, $userId, 'issue_created', 'issue', $id, [
                'priority' => $priority,
                'type' => $type,
            ]);
            return $id;
        });
    }

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
        $priority = postEnum($input, 'priority', issuePriorities(), 'Mức ưu tiên');
        $status = postEnum($input, 'status', issueStatuses(), 'Trạng thái');
        $assigneeId = postOptionalInt($input, 'assignee_id', 1, PHP_INT_MAX, 'Người phụ trách');
        $commitUrl = postUrl($input, 'commit_url', 500, 'Commit / PR URL');
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
            $commitUrl
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
                throw new ConflictException(
                    'XUNG ĐỘT: công việc đã được người khác cập nhật. '
                    . 'Vui lòng tải lại dữ liệu trước khi lưu.'
                );
            }
            $oldStatus = (string)$issue['status'];
            $contentChanged = (string)$issue['title'] !== $title
                || (string)$issue['description'] !== $description
                || (string)$issue['reproduction_steps'] !== $steps
                || (string)$issue['priority'] !== $priority
                || $oldAssignee !== $assigneeId
                || (string)$issue['commit_url'] !== $commitUrl;
            $changed = $contentChanged || $oldStatus !== $status;
            if (!$changed) {
                return $oldVersion;
            }
            if ($contentChanged && in_array($status, ['verified', 'closed'], true)) {
                throw new ValidationException(
                    'Nội dung công việc đã thay đổi. Phải kiểm thử lại ở phiên bản mới '
                    . 'trước khi chuyển sang verified hoặc closed.'
                );
            }
            if ($status === 'verified' && $oldStatus !== 'verified') {
                throw new ValidationException(
                    'Trạng thái verified chỉ được đặt bằng cách ghi kết quả kiểm thử passed. '
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
                throw new ConflictException(
                    'XUNG ĐỘT: công việc đã được người khác cập nhật. '
                    . 'Vui lòng tải lại dữ liệu trước khi lưu.'
                );
            }
            $newVersion = (int)pg_fetch_result($result, 0, 0);
            DependencyService::invalidateReadyReleases($connection, $projectId, [$issueId]);
            audit($connection, $projectId, $userId, 'issue_updated', 'issue', $issueId, [
                'from_version' => $oldVersion,
                'to_version' => $newVersion,
                'status' => $status,
            ]);
            return $newVersion;
        });
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
        $resultValue = postEnum($input, 'result', testResults(), 'Kết quả kiểm thử');
        $notes = postString($input, 'notes', 5000, false, 'Ghi chú kiểm thử');
        return transaction($connection, function () use (
            $connection,
            $projectId,
            $userId,
            $issueId,
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
            $newVersion = $oldVersion + 1;
            $newStatus = $resultValue === 'passed' ? 'verified' : 'retest';
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
                [(string)$newStatus, (string)$newVersion, (string)$issueId, (string)$projectId, (string)$oldVersion]
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
