<?php

declare(strict_types=1);

require_once __DIR__ . '/App.php';

final class ReleaseNotes
{
    public static function markdown(PgSql\Connection $connection, int $releaseId): string
    {
        $release = queryOne(
            $connection,
            'SELECT r.*,p.name AS current_project_name
             FROM releases r JOIN projects p ON p.id=r.project_id
             WHERE r.id=$1',
            [(string)$releaseId]
        );
        if ($release === null) {
            throw new NotFoundException('Không tìm thấy phiên bản.');
        }
        $items = queryAll(
            $connection,
            'SELECT * FROM release_items WHERE release_id=$1 ORDER BY id',
            [(string)$releaseId]
        );
        $projectName = self::displayProjectName($release);
        $lines = [];
        $lines[] = '# Ghi chú phát hành ' . $release['version_name'] . ' — ' . $projectName;
        $lines[] = '';
        $lines[] = 'Trạng thái: ' . $release['status'];
        if ((string)$release['description'] !== '') {
            $lines[] = '';
            $lines[] = (string)$release['description'];
        }
        $lines[] = '';
        $lines[] = '## Danh sách thay đổi';
        if ($items === []) {
            $lines[] = 'Chưa có công việc trong phiên bản.';
        }
        foreach ($items as $item) {
            $lines[] = '';
            $lines[] = '### #' . (int)$item['issue_id'] . ' — ' . $item['snapshot_title'];
            if ((string)$item['snapshot_description'] !== '') {
                $lines[] = '';
                $lines[] = (string)$item['snapshot_description'];
            }
            $lines[] = '';
            $lines[] = '- Trạng thái khi chốt: ' . $item['snapshot_status'];
            $lines[] = '- Ưu tiên: ' . $item['snapshot_priority'];
            $lines[] = '- Phụ trách: ' . $item['snapshot_assignee'];
            $testResult = (string)$item['snapshot_test_result'];
            $testLabel = match ($testResult) {
                'not_tested' => 'chưa có bằng chứng kiểm thử',
                'unverified' => 'unverified (thiếu bằng chứng nguồn gốc)',
                default => $testResult,
            };
            $lines[] = '- Kiểm thử tại thời điểm chốt: ' . $testLabel
                . ($item['snapshot_test_version'] === null
                    ? ''
                    : ' (phiên bản ' . (int)$item['snapshot_test_version'] . ')');
            if ((string)$item['snapshot_commit_url'] !== '') {
                $lines[] = '- Commit / PR: ' . $item['snapshot_commit_url'];
            }
        }
        $lines[] = '';
        $lines[] = 'Nguồn: snapshot đã chốt của ReleaseTracker.';
        return implode("\n", $lines) . "\n";
    }

    /**
     * Released notes must use the project name captured at release time.
     * Legacy releases without a snapshot deliberately disclose the gap instead
     * of silently presenting the current project name as historical data.
     *
     * @param array $release Row with status and project_name_snapshot, plus
     *                       either current_project_name or project_name.
     */
    public static function displayProjectName(array $release): string
    {
        $currentName = (string)($release['current_project_name'] ?? $release['project_name'] ?? '');
        if ((string)($release['status'] ?? '') !== 'released') {
            return $currentName;
        }
        $snapshotName = $release['project_name_snapshot'] ?? null;
        return is_string($snapshotName) && $snapshotName !== ''
            ? $snapshotName
            : '[không có snapshot tên dự án lịch sử]';
    }
}
