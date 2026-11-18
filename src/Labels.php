<?php

declare(strict_types=1);

/**
 * Nhãn tiếng Việt cho các giá trị enum lưu trong CSDL.
 *
 * CSDL luôn lưu mã tiếng Anh (blocker, in_progress, ...). Mọi chỗ hiển thị
 * cho người dùng phải đi qua các hàm *Label() bên dưới để không lộ giá trị thô.
 * Các hàm ở đây là hàm thuần (không truy vấn CSDL) nên test được không cần DB.
 */

const PRIORITY_LABELS = [
    'blocker' => 'Blocker',
    'critical' => 'Nghiêm trọng',
    'high' => 'Cao',
    'medium' => 'Trung bình',
    'low' => 'Thấp',
];

const STATUS_LABELS = [
    'open' => 'Mới mở',
    'in_progress' => 'Đang xử lý',
    'resolved' => 'Đã sửa',
    'retest' => 'Chờ kiểm thử lại',
    'verified' => 'Đã xác nhận',
    'closed' => 'Đã đóng',
];

const ISSUE_TYPE_LABELS = [
    'bug' => 'Lỗi',
    'task' => 'Công việc',
    'feature' => 'Tính năng',
];

const TEST_RESULT_LABELS = [
    'passed' => 'Đạt',
    'failed' => 'Không đạt',
    'blocked' => 'Bị chặn',
    'not_tested' => 'Chưa kiểm thử',
    'unverified' => 'Chưa xác minh nguồn gốc',
];

const ROLE_LABELS = [
    'developer' => 'Lập trình viên',
    'tester' => 'Kiểm thử viên',
    'release_manager' => 'Quản lý phát hành',
    'admin' => 'Quản trị',
];

const RELEASE_STATUS_LABELS = [
    'planned' => 'Dự kiến',
    'ready' => 'Sẵn sàng',
    'released' => 'Đã phát hành',
];

function labelFrom(array $labels, ?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return $labels[$value] ?? $value;
}

function priorityLabel(?string $value): string
{
    return labelFrom(PRIORITY_LABELS, $value);
}

function statusLabel(?string $value): string
{
    return labelFrom(STATUS_LABELS, $value);
}

function issueTypeLabel(?string $value): string
{
    return labelFrom(ISSUE_TYPE_LABELS, $value);
}

function testResultLabel(?string $value): string
{
    return labelFrom(TEST_RESULT_LABELS, $value);
}

function roleLabel(?string $value): string
{
    return labelFrom(ROLE_LABELS, $value);
}

function releaseStatusLabel(?string $value): string
{
    return labelFrom(RELEASE_STATUS_LABELS, $value);
}

/**
 * Tra nhãn cho một giá trị enum bất kỳ (dùng trong thông báo lỗi chung).
 * Các mã enum không trùng nhau giữa các bảng nên tra lần lượt là an toàn.
 */
function enumLabel(string $value): string
{
    foreach ([
        PRIORITY_LABELS,
        STATUS_LABELS,
        ISSUE_TYPE_LABELS,
        TEST_RESULT_LABELS,
        ROLE_LABELS,
        RELEASE_STATUS_LABELS,
    ] as $labels) {
        if (isset($labels[$value])) {
            return $labels[$value];
        }
    }
    return $value;
}

/** Huy hiệu (badge) màu theo mức ưu tiên. */
function priorityBadge(?string $value): string
{
    return '<span class="pill priority-' . h((string)$value) . '">' . h(priorityLabel($value)) . '</span>';
}

/** Huy hiệu màu theo trạng thái công việc. */
function statusBadge(?string $value): string
{
    return '<span class="pill status-' . h((string)$value) . '">' . h(statusLabel($value)) . '</span>';
}

function issueTypeBadge(?string $value): string
{
    return '<span class="pill type-' . h((string)$value) . '">' . h(issueTypeLabel($value)) . '</span>';
}

function testResultBadge(?string $value): string
{
    return '<span class="pill test-' . h((string)$value) . '">' . h(testResultLabel($value)) . '</span>';
}

function releaseStatusBadge(?string $value): string
{
    return '<span class="pill release-' . h((string)$value) . '">' . h(releaseStatusLabel($value)) . '</span>';
}

function roleBadge(?string $value): string
{
    return '<span class="pill role-' . h((string)$value) . '">' . h(roleLabel($value)) . '</span>';
}
