<?php

declare(strict_types=1);

/*
 * Unit test KHÔNG cần CSDL: thuật toán đồ thị (DFS), các hàm *Label(),
 * kiểm tra enum, mô tả lịch sử và dựng ghi chú phát hành từ mảng snapshot.
 * Chạy: php tests/run.php
 */

require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/DependencyService.php';
require_once __DIR__ . '/../src/History.php';
require_once __DIR__ . '/../src/ReleaseNotes.php';
require_once __DIR__ . '/helper.php';

testCase('U1: findPath tìm đường bằng DFS trên mảng kề', function (): void {
    // 1 -> 2 -> 3, 1 -> 4
    $adjacency = [1 => [2, 4], 2 => [3]];
    assertSame([1, 2, 3], DependencyService::findPath($adjacency, 1, 3), 'Đường 1 → 3');
    assertSame([1, 4], DependencyService::findPath($adjacency, 1, 4), 'Đường 1 → 4');
    assertSame(null, DependencyService::findPath($adjacency, 3, 1), 'Không có đường ngược');
    assertSame([5], DependencyService::findPath([], 5, 5), 'Đỉnh tới chính nó');
    // Dữ liệu cũ đã có vòng 1 -> 2 -> 1: không được lặp vô hạn.
    assertSame(null, DependencyService::findPath([1 => [2], 2 => [1]], 1, 9), 'Có vòng sẵn vẫn kết thúc');
});

testCase('U2: findCycle phát hiện chu trình', function (): void {
    assertSame(null, DependencyService::findCycle([1 => [2], 2 => [3]]), 'Đồ thị không vòng');
    assertSame(null, DependencyService::findCycle([1 => [2, 3], 2 => [4], 3 => [4]]), 'Hình thoi không phải vòng');
    assertSame([1, 2, 3, 1], DependencyService::findCycle([1 => [2], 2 => [3], 3 => [1]]), 'Vòng 1 → 2 → 3 → 1');
    $cycle = DependencyService::findCycle([10 => [11], 11 => [12], 12 => [11]]);
    assertSame([11, 12, 11], $cycle, 'Chỉ trả phần vòng, bỏ phần đuôi');
});

testCase('U3: cycleIfAdded và formatChain cho chuỗi gây vòng', function (): void {
    // A=1 phụ thuộc B=2, B phụ thuộc C=3; thêm C phụ thuộc A.
    $adjacency = [1 => [2], 2 => [3]];
    $cycle = DependencyService::cycleIfAdded($adjacency, 3, 1);
    assertSame([1, 2, 3, 1], $cycle, 'Chuỗi phải là A, B, C, A');
    assertSame('#1 → #2 → #3 → #1', DependencyService::formatChain($cycle), 'Định dạng chuỗi');
    assertSame(null, DependencyService::cycleIfAdded($adjacency, 1, 3), 'A phụ thuộc C không tạo vòng');
    assertSame([4, 4], DependencyService::cycleIfAdded($adjacency, 4, 4), 'Tự phụ thuộc là vòng');
});

testCase('U4: các hàm *Label trả nhãn tiếng Việt', function (): void {
    assertSame('Blocker', priorityLabel('blocker'), 'Ưu tiên blocker');
    assertSame('Nghiêm trọng', priorityLabel('critical'), 'Ưu tiên critical');
    assertSame('Đang xử lý', statusLabel('in_progress'), 'Trạng thái in_progress');
    assertSame('Đã xác nhận', statusLabel('verified'), 'Trạng thái verified');
    assertSame('Tính năng', issueTypeLabel('feature'), 'Loại feature');
    assertSame('Không đạt', testResultLabel('failed'), 'Kết quả failed');
    assertSame('Chưa kiểm thử', testResultLabel('not_tested'), 'Snapshot not_tested');
    assertSame('Quản lý phát hành', roleLabel('release_manager'), 'Vai trò');
    assertSame('Đã phát hành', releaseStatusLabel('released'), 'Trạng thái phiên bản');
    assertSame('xyz', statusLabel('xyz'), 'Giá trị lạ trả nguyên');
    assertSame('—', statusLabel(null), 'Rỗng trả gạch ngang');
    foreach (ISSUE_PRIORITIES as $value) {
        assertTrue(priorityLabel($value) !== $value || $value === 'blocker', 'Mọi ưu tiên đều có nhãn: ' . $value);
    }
    foreach (ISSUE_STATUSES as $value) {
        assertTrue(statusLabel($value) !== $value, 'Mọi trạng thái đều có nhãn: ' . $value);
    }
    assertContains('priority-blocker', priorityBadge('blocker'), 'Badge có class màu');
    assertContains('&lt;b&gt;', statusBadge('<b>'), 'Badge phải escape dữ liệu');
});

testCase('U5: validateEnum kiểm tra enum bằng thông báo tiếng Việt', function (): void {
    assertSame('high', validateEnum('high', ISSUE_PRIORITIES, 'Mức ưu tiên'), 'Giá trị hợp lệ');
    assertSame('tester', postEnum(['role' => 'tester'], 'role', PROJECT_ROLES, 'Vai trò'), 'postEnum hợp lệ');
    $exception = expectException(
        static fn() => validateEnum('urgent', ISSUE_PRIORITIES, 'Mức ưu tiên'),
        ValidationException::class,
        'Mức ưu tiên không hợp lệ',
        'Giá trị sai phải bị từ chối'
    );
    assertContains('Nghiêm trọng', $exception->getMessage(), 'Thông báo liệt kê lựa chọn bằng tiếng Việt');
    assertNotContains('DATABASE', $exception->getMessage(), 'Không lộ mã lỗi CSDL');
    expectException(static fn() => postEnum([], 'status', ISSUE_STATUSES, 'Trạng thái'), ValidationException::class, 'Trạng thái');
    expectException(static fn() => postEnum(['result' => ['x']], 'result', TEST_RESULTS), ValidationException::class);
    expectException(static fn() => validateEnum('owner', PROJECT_ROLES, 'Vai trò'), ValidationException::class, 'Vai trò');
    expectException(static fn() => validateEnum('Bug', ISSUE_TYPES, 'Loại'), ValidationException::class, 'Loại', 'Phân biệt hoa thường');
    assertSame('Thao tác cơ sở dữ liệu thất bại.', userMessage(new RuntimeException('DATABASE_OPERATION_FAILED')), 'Lỗi DB được dịch');
});

testCase('U6: History::describe mô tả lịch sử thay đổi', function (): void {
    $entry = History::describe([
        'action' => 'issue_updated',
        'details' => json_encode(['changes' => [
            'priority' => ['old' => 'blocker', 'new' => 'low'],
            'title' => ['old' => 'Cũ', 'new' => 'Mới'],
        ]]),
    ]);
    assertSame('cập nhật', $entry['text'], 'Hành động');
    assertSame(['field' => 'Ưu tiên', 'old' => 'Blocker', 'new' => 'Thấp'], $entry['changes'][0], 'Ưu tiên cũ → mới');
    assertSame('Tiêu đề', $entry['changes'][1]['field'], 'Tên trường tiếng Việt');
    assertSame(null, History::describe(['action' => 'priority_changed', 'details' => '{}']), 'Bỏ bản ghi trùng');
    $test = History::describe(['action' => 'test_result_recorded', 'details' => ['result' => 'passed']]);
    assertSame('ghi kết quả kiểm thử: Đạt', $test['text'], 'Kết quả kiểm thử');
    $forced = History::describe(['action' => 'issue_updated', 'details' => ['overwrite_after_conflict' => true]]);
    assertContains('ghi đè có chủ đích', $forced['text'], 'Ghi nhận ghi đè sau xung đột');
    assertSame('(trống)', History::value('title', ''), 'Giá trị rỗng');
});

testCase('U7: ghi chú phát hành nhóm theo loại công việc', function (): void {
    $release = [
        'version_name' => '2.0.0',
        'status' => 'released',
        'project_name_snapshot' => 'Dự án X',
        'description' => '',
        'released_at' => '2026-11-18 10:00:00+07',
    ];
    $item = static fn(int $id, string $title, ?string $type, string $url = ''): array => [
        'issue_id' => $id,
        'snapshot_title' => $title,
        'snapshot_issue_type' => $type,
        'snapshot_status' => 'verified',
        'snapshot_priority' => 'high',
        'snapshot_assignee' => 'Nguyễn An',
        'snapshot_description' => '',
        'snapshot_commit_url' => $url,
        'snapshot_test_result' => 'passed',
        'snapshot_test_version' => 3,
    ];
    $items = [$item(1, 'Sửa đăng nhập', 'bug'), $item(2, 'Xuất PDF', 'feature', 'https://git/pr/1'), $item(3, 'Cũ', null)];
    $groups = ReleaseNotes::groupItems($items);
    assertSame(['feature', 'bug', ''], array_keys($groups), 'Thứ tự nhóm: Tính năng, Sửa lỗi, Khác');
    $md = ReleaseNotes::renderMarkdown($release, $items);
    assertContains('# Ghi chú phát hành 2.0.0 — Dự án X', $md, 'Tiêu đề dùng tên dự án snapshot');
    assertContains('## Tính năng (1)', $md, 'Nhóm Tính năng');
    assertContains('### #2 — Xuất PDF', $md, 'Dòng có #id và tiêu đề');
    assertContains('- Phụ trách: Nguyễn An', $md, 'Người phụ trách');
    assertContains('Đạt (passed) (phiên bản 3)', $md, 'Kết quả kiểm thử khi chốt');
    assertContains('- Commit / PR: https://git/pr/1', $md, 'Link commit/PR');
    $txt = ReleaseNotes::renderText($release, $items);
    assertContains('Sửa lỗi:', $txt, 'TXT có nhóm');
    assertContains('#1 Sửa đăng nhập | Phụ trách: Nguyễn An', $txt, 'TXT một dòng mỗi công việc');
    assertNotContains('###', $txt, 'TXT không có cú pháp Markdown');
});

testFinish('UNIT');
