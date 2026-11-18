<?php

declare(strict_types=1);

require_once __DIR__ . '/Labels.php';

/**
 * Chuyển bản ghi audit_log thành mô tả tiếng Việt cho mục "Lịch sử thay đổi".
 * Hàm thuần (không truy vấn CSDL) nên test được không cần DB.
 */
final class History
{
    public const FIELD_LABELS = [
        'title' => 'Tiêu đề',
        'description' => 'Mô tả',
        'reproduction_steps' => 'Bước tái hiện',
        'priority' => 'Ưu tiên',
        'status' => 'Trạng thái',
        'assignee' => 'Người phụ trách',
        'commit_url' => 'Commit / PR',
    ];

    /**
     * @return array{text: string, changes: list<array{field: string, old: string, new: string}>}|null
     *         null nếu bản ghi không cần hiển thị (ví dụ priority_changed đã nằm trong issue_updated).
     */
    public static function describe(array $row): ?array
    {
        $details = self::details($row['details'] ?? null);
        $action = (string)($row['action'] ?? '');
        $text = match ($action) {
            'issue_created' => 'tạo công việc'
                . (isset($details['priority']) ? ' (ưu tiên ' . priorityLabel((string)$details['priority']) . ')' : ''),
            'issue_updated' => !empty($details['overwrite_after_conflict'])
                ? 'cập nhật (ghi đè có chủ đích sau xung đột)'
                : 'cập nhật',
            'priority_changed' => null,
            'comment_added' => 'thêm bình luận',
            'test_result_recorded' => 'ghi kết quả kiểm thử: '
                . testResultLabel((string)($details['result'] ?? '')),
            'dependency_added' => 'thêm phụ thuộc vào #' . (int)($details['depends_on'] ?? 0),
            'dependency_removed' => 'gỡ phụ thuộc vào #' . (int)($details['depends_on'] ?? 0),
            'release_item_added' => 'được gắn vào phiên bản #' . (int)($row['entity_id'] ?? 0),
            'release_item_removed' => 'được gỡ khỏi phiên bản #' . (int)($row['entity_id'] ?? 0),
            default => $action,
        };
        if ($text === null) {
            return null;
        }
        $changes = [];
        foreach ((array)($details['changes'] ?? []) as $field => $change) {
            if (!is_array($change)) {
                continue;
            }
            $changes[] = [
                'field' => self::FIELD_LABELS[$field] ?? (string)$field,
                'old' => self::value((string)$field, (string)($change['old'] ?? '')),
                'new' => self::value((string)$field, (string)($change['new'] ?? '')),
            ];
        }
        return ['text' => $text, 'changes' => $changes];
    }

    public static function value(string $field, string $value): string
    {
        if ($value === '') {
            return '(trống)';
        }
        $value = match ($field) {
            'priority' => priorityLabel($value),
            'status' => statusLabel($value),
            default => $value,
        };
        if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > 80) {
            return mb_substr($value, 0, 77, 'UTF-8') . '…';
        }
        return $value;
    }

    private static function details(mixed $details): array
    {
        if (is_array($details)) {
            return $details;
        }
        $decoded = is_string($details) ? json_decode($details, true) : null;
        return is_array($decoded) ? $decoded : [];
    }
}
