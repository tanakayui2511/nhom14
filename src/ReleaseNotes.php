<?php

declare(strict_types=1);

require_once __DIR__ . '/App.php';

/**
 * Ghi chú phát hành được dựng HOÀN TOÀN từ snapshot trong release_items.
 * Không đọc bảng issues, nên sửa công việc sau khi chốt không làm đổi nội dung.
 */
final class ReleaseNotes
{
    /** Thứ tự và tên nhóm theo loại công việc. */
    public const GROUPS = [
        'feature' => 'Tính năng',
        'bug' => 'Sửa lỗi',
        'task' => 'Công việc',
        '' => 'Khác (dữ liệu cũ chưa ghi loại)',
    ];

    public static function markdown(PgSql\Connection $connection, int $releaseId): string
    {
        [$release, $items] = self::load($connection, $releaseId);
        return self::renderMarkdown($release, $items);
    }

    public static function text(PgSql\Connection $connection, int $releaseId): string
    {
        [$release, $items] = self::load($connection, $releaseId);
        return self::renderText($release, $items);
    }

    /**
     * Xuất ghi chú cho người dùng tải về. Kiểm tra quyền thành viên dự án và
     * chỉ cho xuất phiên bản đã chốt (nội dung là snapshot bất biến).
     *
     * @return array{filename: string, mime: string, content: string}
     */
    public static function export(
        PgSql\Connection $connection,
        int $releaseId,
        int $userId,
        string $format
    ): array {
        $format = validateEnum($format, ['md', 'txt'], 'Định dạng xuất');
        [$release, $items] = self::load($connection, $releaseId);
        $member = queryOne(
            $connection,
            'SELECT 1 FROM project_members WHERE project_id=$1 AND user_id=$2',
            [(string)$release['project_id'], (string)$userId]
        );
        if ($member === null) {
            throw new ForbiddenException('Bạn không có quyền xem ghi chú phát hành của dự án này.');
        }
        if ((string)$release['status'] !== 'released') {
            throw new ConflictException(
                'Chỉ xuất được ghi chú của phiên bản đã chốt phát hành (nội dung lấy từ snapshot).'
            );
        }
        $safeVersion = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$release['version_name']);
        $base = 'release-notes-' . ($safeVersion === '' ? (string)$releaseId : $safeVersion);
        return $format === 'md'
            ? [
                'filename' => $base . '.md',
                'mime' => 'text/markdown; charset=utf-8',
                'content' => self::renderMarkdown($release, $items),
            ]
            : [
                'filename' => $base . '.txt',
                'mime' => 'text/plain; charset=utf-8',
                'content' => self::renderText($release, $items),
            ];
    }

    /** Nhóm item theo snapshot_issue_type, giữ thứ tự GROUPS. */
    public static function groupItems(array $items): array
    {
        $groups = [];
        foreach (array_keys(self::GROUPS) as $key) {
            $groups[$key] = [];
        }
        foreach ($items as $item) {
            $type = (string)($item['snapshot_issue_type'] ?? '');
            $groups[isset($groups[$type]) ? $type : ''][] = $item;
        }
        return array_filter($groups, static fn(array $rows): bool => $rows !== []);
    }

    public static function testLabel(array $item): string
    {
        $result = (string)$item['snapshot_test_result'];
        $label = match ($result) {
            'not_tested' => 'chưa có bằng chứng kiểm thử',
            'unverified' => 'unverified (thiếu bằng chứng nguồn gốc)',
            default => testResultLabel($result) . ' (' . $result . ')',
        };
        if (($item['snapshot_test_version'] ?? null) !== null) {
            $label .= ' (phiên bản ' . (int)$item['snapshot_test_version'] . ')';
        }
        return $label;
    }

    public static function renderMarkdown(array $release, array $items): string
    {
        $lines = [];
        $lines[] = '# Ghi chú phát hành ' . $release['version_name'] . ' — ' . self::displayProjectName($release);
        $lines[] = '';
        $lines[] = 'Trạng thái: ' . releaseStatusLabel((string)$release['status']) . ' (' . $release['status'] . ')';
        if (($release['released_at'] ?? null) !== null) {
            $lines[] = 'Ngày chốt: ' . self::date((string)$release['released_at']);
        }
        if ((string)$release['description'] !== '') {
            $lines[] = '';
            $lines[] = (string)$release['description'];
        }
        $lines[] = '';
        $lines[] = '## Danh sách thay đổi';
        if ($items === []) {
            $lines[] = 'Chưa có công việc trong phiên bản.';
        }
        foreach (self::groupItems($items) as $type => $rows) {
            $lines[] = '';
            $lines[] = '## ' . self::GROUPS[$type] . ' (' . count($rows) . ')';
            foreach ($rows as $item) {
                $lines[] = '';
                $lines[] = '### #' . (int)$item['issue_id'] . ' — ' . $item['snapshot_title'];
                if ((string)$item['snapshot_description'] !== '') {
                    $lines[] = '';
                    $lines[] = (string)$item['snapshot_description'];
                }
                $lines[] = '';
                $lines[] = '- Trạng thái khi chốt: ' . statusLabel((string)$item['snapshot_status']);
                $lines[] = '- Ưu tiên: ' . priorityLabel((string)$item['snapshot_priority']);
                $lines[] = '- Phụ trách: ' . ((string)$item['snapshot_assignee'] === '' ? 'Chưa phân công' : $item['snapshot_assignee']);
                $lines[] = '- Kiểm thử tại thời điểm chốt: ' . self::testLabel($item);
                if ((string)$item['snapshot_commit_url'] !== '') {
                    $lines[] = '- Commit / PR: ' . $item['snapshot_commit_url'];
                }
            }
        }
        $lines[] = '';
        $lines[] = 'Nguồn: snapshot đã chốt của ReleaseTracker.';
        return implode("\n", $lines) . "\n";
    }

    public static function renderText(array $release, array $items): string
    {
        $title = 'GHI CHÚ PHÁT HÀNH ' . $release['version_name'] . ' — ' . self::displayProjectName($release);
        $lines = [$title, str_repeat('=', max(10, textLength($title)))];
        $lines[] = 'Trạng thái: ' . releaseStatusLabel((string)$release['status']);
        if (($release['released_at'] ?? null) !== null) {
            $lines[] = 'Ngày chốt: ' . self::date((string)$release['released_at']);
        }
        if ((string)$release['description'] !== '') {
            $lines[] = '';
            $lines[] = (string)$release['description'];
        }
        if ($items === []) {
            $lines[] = '';
            $lines[] = 'Chưa có công việc trong phiên bản.';
        }
        foreach (self::groupItems($items) as $type => $rows) {
            $lines[] = '';
            $lines[] = self::GROUPS[$type] . ':';
            foreach ($rows as $item) {
                $parts = [
                    '#' . (int)$item['issue_id'] . ' ' . $item['snapshot_title'],
                    'Phụ trách: ' . ((string)$item['snapshot_assignee'] === '' ? 'Chưa phân công' : $item['snapshot_assignee']),
                    'Kiểm thử khi chốt: ' . self::testLabel($item),
                ];
                if ((string)$item['snapshot_commit_url'] !== '') {
                    $parts[] = 'Commit/PR: ' . $item['snapshot_commit_url'];
                }
                $lines[] = '  - ' . implode(' | ', $parts);
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

    private static function load(PgSql\Connection $connection, int $releaseId): array
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
        return [$release, $items];
    }

    private static function date(string $timestamp): string
    {
        $time = strtotime($timestamp);
        return $time === false ? $timestamp : date('d/m/Y H:i', $time);
    }
}
