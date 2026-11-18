<?php

declare(strict_types=1);

/*
 * Test nghiệp vụ trên CSDL thật (schema tạm riêng cho lần chạy).
 *
 * Mỗi test chạy trong BEGIN … ROLLBACK: tự tạo dự án, công việc, phiên bản
 * riêng, không phụ thuộc id cố định và không để lại dữ liệu. Các service
 * dùng SAVEPOINT khi đã ở trong transaction nên hoạt động bình thường.
 *
 * Xem bảng đối chiếu ở docs/test-cases.md. Chạy: php tests/integration.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/History.php';

$connection = TestDatabase::boot();
$devA = testUserId($connection, 'dev_a');
$testerA = testUserId($connection, 'tester_a');
$releaseA = testUserId($connection, 'release_a');
$adminA = testUserId($connection, 'admin_a');
$devB = testUserId($connection, 'dev_b');

echo 'TEST_SCHEMA=' . TestDatabase::schema() . "\n";

/** Chạy một test trong transaction riêng và luôn ROLLBACK. */
function txTest(PgSql\Connection $connection, string $name, callable $callback): void
{
    testCase($name, function () use ($connection, $callback): void {
        execSql($connection, 'BEGIN');
        try {
            $callback();
        } finally {
            @pg_query($connection, 'ROLLBACK');
        }
    });
}

function itVersion(PgSql\Connection $connection, int $issueId): int
{
    return (int)testIssueSnapshot($connection, $issueId)['version'];
}

/** Payload cập nhật đầy đủ từ dữ liệu hiện tại, ghi đè một số trường. */
function itPayload(PgSql\Connection $connection, int $issueId, array $overrides = []): array
{
    $current = testIssueSnapshot($connection, $issueId);
    return array_merge([
        'title' => (string)$current['title'],
        'description' => (string)$current['description'],
        'steps' => (string)$current['reproduction_steps'],
        'priority' => (string)$current['priority'],
        'status' => (string)$current['status'],
        'assignee_id' => $current['assignee_id'] === null ? '' : (string)$current['assignee_id'],
        'commit_url' => (string)$current['commit_url'],
    ], $overrides);
}

function itTest(PgSql\Connection $connection, int $projectId, int $testerId, int $issueId, string $result): int
{
    return IssueService::recordTest($connection, $projectId, $testerId, null, $issueId, [
        'version' => (string)itVersion($connection, $issueId),
        'result' => $result,
        'notes' => 'Kết quả ' . $result,
    ]);
}

function itRelease(PgSql\Connection $connection, int $projectId, int $managerId, array $issueIds): int
{
    $releaseId = ReleaseService::create($connection, $projectId, $managerId, null, [
        'version_name' => 'it-' . bin2hex(random_bytes(3)),
        'description' => 'Phiên bản kiểm thử tích hợp',
    ]);
    foreach ($issueIds as $issueId) {
        ReleaseService::addItem($connection, $releaseId, $projectId, $issueId, $managerId, null);
    }
    return $releaseId;
}

function itReleaseStatus(PgSql\Connection $connection, int $releaseId): string
{
    return (string)queryOne($connection, 'SELECT status FROM releases WHERE id=$1', [(string)$releaseId])['status'];
}

function itCount(PgSql\Connection $connection, string $sql, array $params = []): int
{
    return (int)queryOne($connection, $sql, $params)['total'];
}

// ===================================================================
// Năm phép thử bắt buộc
// ===================================================================

txTest($connection, 'T1: vòng A→B→C→A bị từ chối, chỉ rõ chuỗi và không ghi gì', function () use ($connection, $devA): void {
    $project = testInsertProject($connection, 'T1');
    $a = testInsertIssue($connection, $project, 'Công việc A', 'medium', 'open', $devA, $devA);
    $b = testInsertIssue($connection, $project, 'Công việc B', 'medium', 'open', $devA, $devA);
    $c = testInsertIssue($connection, $project, 'Công việc C', 'medium', 'open', $devA, $devA);
    DependencyService::add($connection, $project, $a, $b, $devA);
    DependencyService::add($connection, $project, $b, $c, $devA);
    $auditBefore = itCount($connection, 'SELECT count(*) AS total FROM audit_log WHERE project_id=$1', [(string)$project]);
    $exception = expectException(
        static fn() => DependencyService::add($connection, $project, $c, $a, $devA),
        CycleException::class,
        "#$a → #$b → #$c → #$a",
        'Thêm C phụ thuộc A phải bị từ chối kèm chuỗi gây vòng'
    );
    assertSame([$a, $b, $c, $a], $exception->cycle, 'Exception mang theo chuỗi id để giao diện tô đậm');
    assertSame('Công việc B', $exception->titles[$b] ?? null, 'Exception mang theo tiêu đề');
    assertSame(2, itCount($connection, 'SELECT count(*) AS total FROM dependencies WHERE issue_project_id=$1', [(string)$project]), 'Chỉ còn 2 quan hệ');
    assertSame($auditBefore, itCount($connection, 'SELECT count(*) AS total FROM audit_log WHERE project_id=$1', [(string)$project]), 'Không ghi audit');
});

txTest($connection, 'T2: blocker chưa test/failed thì không chốt được, passed + verified thì đạt', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'T2');
    $blocker = testInsertIssue($connection, $project, 'Blocker T2', 'blocker', 'resolved', $devA, $devA);
    $release = itRelease($connection, $project, $releaseA, [$blocker]);

    $check = ReleaseService::check($connection, $release, $project);
    assertSame(false, $check['checks']['blockers']['passed'], 'Chưa test: điều kiện blocker chưa đạt');
    assertContains('chưa có kết quả kiểm thử lại', implode("\n", $check['problems']), 'Nêu rõ chưa kiểm thử');
    expectException(
        static fn() => ReleaseService::snapshot($connection, $release, $project, $releaseA),
        AppException::class,
        null,
        'snapshot() phải ném exception khi blocker chưa test'
    );

    itTest($connection, $project, $testerA, $blocker, 'failed');
    $check = ReleaseService::check($connection, $release, $project);
    assertSame(false, $check['checks']['blockers']['passed'], 'Test failed: chưa đạt');
    expectException(
        static fn() => ReleaseService::markReady($connection, $release, $project, $releaseA, null),
        ValidationException::class,
        'Blocker #' . $blocker,
        'Không xác nhận sẵn sàng khi blocker failed'
    );
    expectException(
        static fn() => ReleaseService::snapshot($connection, $release, $project, $releaseA),
        AppException::class,
        null,
        'snapshot() phải ném exception khi blocker failed'
    );

    itTest($connection, $project, $testerA, $blocker, 'passed');
    assertSame('verified', (string)testIssueSnapshot($connection, $blocker)['status'], 'Passed chuyển verified');
    $check = ReleaseService::check($connection, $release, $project);
    assertSame(true, $check['checks']['blockers']['passed'], 'Passed + verified: đạt');
    assertSame([], $check['problems'], 'Không còn vấn đề nào');
    ReleaseService::markReady($connection, $release, $project, $releaseA, null);
    ReleaseService::snapshot($connection, $release, $project, $releaseA);
    assertSame('released', itReleaseStatus($connection, $release), 'Chốt thành công');
});

txTest($connection, 'T3: hai lần update cùng version, lần sau báo xung đột, dữ liệu lần đầu còn nguyên', function () use (
    $connection,
    $devA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'T3');
    $issue = testInsertIssue($connection, $project, 'Sửa đồng thời', 'medium', 'open', $devA, $devA);
    $version = itVersion($connection, $issue);
    $first = itPayload($connection, $issue, ['title' => 'Bản của người 1', 'status' => 'in_progress']);
    $second = itPayload($connection, $issue, ['title' => 'Bản của người 2', 'description' => 'Người 2 sửa']);
    $newVersion = IssueService::update($connection, $project, $devA, null, $issue, $version, $first);
    assertSame($version + 1, $newVersion, 'Lần 1 tăng version');
    $exception = expectException(
        static fn() => IssueService::update($connection, $project, $releaseA, null, $issue, $version, $second),
        VersionConflictException::class,
        'XUNG ĐỘT',
        'Lần 2 với version cũ phải báo xung đột'
    );
    assertSame('Bản của người 1', $exception->current['title'], 'Xung đột mang theo bản hiện tại để so sánh');
    $row = testIssueSnapshot($connection, $issue);
    assertSame('Bản của người 1', (string)$row['title'], 'Dữ liệu người 1 còn nguyên');
    assertSame('in_progress', (string)$row['status'], 'Trạng thái người 1 còn nguyên');
    assertSame($version + 1, (int)$row['version'], 'Version không đổi sau lần bị từ chối');
    // "Ghi đè có chủ đích": gửi lại với version mới, vẫn ghi audit.
    IssueService::update($connection, $project, $releaseA, null, $issue, $version + 1, $second + ['overwrite' => '1']);
    $audit = queryOne(
        $connection,
        "SELECT details FROM audit_log WHERE action='issue_updated' AND entity_id=\$1 ORDER BY id DESC LIMIT 1",
        [(string)$issue]
    );
    assertSame(true, json_decode((string)$audit['details'], true)['overwrite_after_conflict'] ?? null, 'Audit ghi nhận ghi đè có chủ đích');
});

txTest($connection, 'T4: thành viên dự án B không thao tác được trên dự án A', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA,
    $devB
): void {
    $project = testInsertProject($connection, 'T4');
    $issue = testInsertIssue($connection, $project, 'Việc của dự án A', 'medium', 'resolved', $devA, $devA);
    $other = testInsertIssue($connection, $project, 'Việc khác của dự án A', 'medium', 'open', $devA, $devA);
    itTest($connection, $project, $testerA, $issue, 'passed');
    $release = itRelease($connection, $project, $releaseA, [$issue]);
    ReleaseService::markReady($connection, $release, $project, $releaseA, null);
    ReleaseService::release($connection, $release, $project, $releaseA, null);
    $before = testIssueSnapshot($connection, $issue);
    $auditBefore = itCount($connection, 'SELECT count(*) AS total FROM audit_log WHERE project_id=$1', [(string)$project]);

    expectException(
        static fn() => IssueService::update($connection, $project, $devB, 'admin', $issue, (int)$before['version'], itPayload($connection, $issue, ['title' => 'Bị chiếm'])),
        ForbiddenException::class,
        null,
        'Không update được'
    );
    expectException(
        static fn() => IssueService::addComment($connection, $project, $devB, 'admin', $issue, ['body' => 'Xâm nhập']),
        ForbiddenException::class,
        null,
        'Không bình luận được'
    );
    expectException(
        static fn() => IssueService::recordTest($connection, $project, $devB, 'tester', $issue, ['version' => (string)$before['version'], 'result' => 'failed', 'notes' => '']),
        ForbiddenException::class,
        null,
        'Không ghi test được'
    );
    expectException(
        static fn() => DependencyService::add($connection, $project, $other, $issue, $devB),
        ForbiddenException::class,
        null,
        'Không thêm phụ thuộc được'
    );
    expectException(
        static fn() => ReleaseNotes::export($connection, $release, $devB, 'md'),
        ForbiddenException::class,
        null,
        'Không xem/xuất ghi chú phát hành được'
    );
    $after = testIssueSnapshot($connection, $issue);
    assertSame($before, $after, 'Dữ liệu công việc không đổi');
    assertSame($auditBefore, itCount($connection, 'SELECT count(*) AS total FROM audit_log WHERE project_id=$1', [(string)$project]), 'Không có audit mới');
});

txTest($connection, 'T5: sau khi chốt, sửa công việc không đổi snapshot; không thêm/gỡ item', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'T5');
    $issue = testInsertIssue($connection, $project, 'Tiêu đề lúc chốt', 'high', 'resolved', $devA, $devA);
    $spare = testInsertIssue($connection, $project, 'Công việc chưa gắn', 'low', 'open', $devA, $devA);
    itTest($connection, $project, $testerA, $issue, 'passed');
    $release = itRelease($connection, $project, $releaseA, [$issue]);
    ReleaseService::markReady($connection, $release, $project, $releaseA, null);
    ReleaseService::release($connection, $release, $project, $releaseA, null);
    $itemsBefore = queryAll($connection, 'SELECT * FROM release_items WHERE release_id=$1 ORDER BY id', [(string)$release]);
    $notesBefore = ReleaseNotes::markdown($connection, $release);

    IssueService::update($connection, $project, $devA, null, $issue, itVersion($connection, $issue), itPayload($connection, $issue, [
        'title' => 'Tiêu đề sau khi chốt',
        'status' => 'in_progress',
    ]));
    assertSame($itemsBefore, queryAll($connection, 'SELECT * FROM release_items WHERE release_id=$1 ORDER BY id', [(string)$release]), 'release_items không đổi');
    assertSame($notesBefore, ReleaseNotes::markdown($connection, $release), 'Ghi chú phát hành không đổi');
    assertContains('Tiêu đề lúc chốt', $notesBefore, 'Ghi chú dùng tiêu đề snapshot');
    expectException(
        static fn() => ReleaseService::addItem($connection, $release, $project, $spare, $releaseA, null),
        ConflictException::class,
        'planned',
        'Không thêm item vào phiên bản đã chốt'
    );
    expectException(
        static fn() => ReleaseService::removeItem($connection, $release, $project, $issue, $releaseA, null),
        ConflictException::class,
        'planned',
        'Không gỡ item khỏi phiên bản đã chốt'
    );
    assertSame('released', itReleaseStatus($connection, $release), 'Vẫn ở trạng thái đã phát hành');
});

// ===================================================================
// Hồi quy cho các lỗi ở Phần 2
// ===================================================================

txTest($connection, 'R1: hạ ưu tiên blocker để lách bị chặn và vẫn bị coi là blocker', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'R1');
    $blocker = testInsertIssue($connection, $project, 'Blocker R1', 'blocker', 'in_progress', $devA, $devA);
    $release = itRelease($connection, $project, $releaseA, [$blocker]);
    expectException(
        static fn() => IssueService::update($connection, $project, $devA, null, $blocker, itVersion($connection, $blocker), itPayload($connection, $blocker, ['priority' => 'low'])),
        ForbiddenException::class,
        'Blocker',
        'Developer không được hạ ưu tiên blocker'
    );
    assertSame('blocker', (string)testIssueSnapshot($connection, $blocker)['priority'], 'Ưu tiên giữ nguyên');

    IssueService::changePriority($connection, $project, $testerA, $blocker, itVersion($connection, $blocker), 'low');
    $audit = queryOne(
        $connection,
        "SELECT details FROM audit_log WHERE action='priority_changed' AND entity_id=\$1 ORDER BY id DESC LIMIT 1",
        [(string)$blocker]
    );
    $details = json_decode((string)$audit['details'], true);
    assertSame(['blocker', 'low'], [$details['old'] ?? null, $details['new'] ?? null], 'Audit ghi giá trị cũ và mới');
    // Developer đóng việc mà không có test lại.
    IssueService::update($connection, $project, $devA, null, $blocker, itVersion($connection, $blocker), itPayload($connection, $blocker, ['status' => 'closed']));
    $check = ReleaseService::check($connection, $release, $project);
    assertSame(false, $check['checks']['blockers']['passed'], 'Vẫn phải qua điều kiện blocker');
    assertContains('từng là blocker', implode("\n", $check['checks']['blockers']['problems']), 'Giải thích lý do');
    // Công việc chưa gắn vào phiên bản khi còn là blocker thì không bị ảnh hưởng.
    $plain = testInsertIssue($connection, $project, 'Low từ đầu', 'low', 'resolved', $devA, $devA);
    itTest($connection, $project, $testerA, $plain, 'passed');
    $release2 = itRelease($connection, $project, $releaseA, [$plain]);
    assertSame(true, ReleaseService::check($connection, $release2, $project)['checks']['blockers']['passed'], 'Công việc low bình thường không bị coi là blocker');
});

txTest($connection, 'R2: blocker passed rồi bị mở lại thì không đạt', function () use ($connection, $devA, $testerA, $releaseA): void {
    $project = testInsertProject($connection, 'R2');
    $blocker = testInsertIssue($connection, $project, 'Blocker R2', 'blocker', 'resolved', $devA, $devA);
    $release = itRelease($connection, $project, $releaseA, [$blocker]);
    itTest($connection, $project, $testerA, $blocker, 'passed');
    assertSame(true, ReleaseService::check($connection, $release, $project)['checks']['blockers']['passed'], 'Vừa passed thì đạt');
    IssueService::update($connection, $project, $devA, null, $blocker, itVersion($connection, $blocker), itPayload($connection, $blocker, ['status' => 'open']));
    $check = ReleaseService::check($connection, $release, $project);
    assertSame(false, $check['checks']['blockers']['passed'], 'Mở lại thì không đạt');
    expectException(
        static fn() => ReleaseService::markReady($connection, $release, $project, $releaseA, null),
        ValidationException::class,
        'Blocker #' . $blocker,
        'Không xác nhận sẵn sàng'
    );
});

txTest($connection, 'R3: failed/blocked trả về in_progress, passed thành verified, test dùng version', function () use (
    $connection,
    $devA,
    $testerA
): void {
    $project = testInsertProject($connection, 'R3');
    $issue = testInsertIssue($connection, $project, 'Kiểm thử R3', 'high', 'resolved', $devA, $devA);
    foreach (['failed' => 'in_progress', 'blocked' => 'in_progress', 'passed' => 'verified'] as $result => $expected) {
        itTest($connection, $project, $testerA, $issue, $result);
        assertSame($expected, (string)testIssueSnapshot($connection, $issue)['status'], 'Sau kết quả ' . $result);
    }
    expectException(
        static fn() => IssueService::recordTest($connection, $project, $testerA, null, $issue, ['version' => '1', 'result' => 'passed', 'notes' => '']),
        ConflictException::class,
        'XUNG ĐỘT',
        'Form test với version cũ phải báo xung đột'
    );
    expectException(
        static fn() => IssueService::recordTest($connection, $project, $devA, null, $issue, ['version' => (string)itVersion($connection, $issue), 'result' => 'passed', 'notes' => '']),
        ForbiddenException::class,
        null,
        'Developer không được ghi kết quả kiểm thử'
    );
});

txTest($connection, 'R4: hai lần chốt cùng phiên bản chỉ một lần thành công', function () use ($connection, $devA, $testerA, $releaseA, $adminA): void {
    $project = testInsertProject($connection, 'R4');
    $issue = testInsertIssue($connection, $project, 'Việc R4', 'medium', 'resolved', $devA, $devA);
    itTest($connection, $project, $testerA, $issue, 'passed');
    $release = itRelease($connection, $project, $releaseA, [$issue]);
    ReleaseService::markReady($connection, $release, $project, $releaseA, null);
    ReleaseService::release($connection, $release, $project, $releaseA, null);
    expectException(
        static fn() => ReleaseService::release($connection, $release, $project, $adminA, null),
        ConflictException::class,
        'đã được chốt',
        'Lần chốt thứ hai phải bị từ chối'
    );
    assertSame(1, itCount(
        $connection,
        "SELECT count(*) AS total FROM audit_log WHERE action='release_snapshotted' AND entity_id=\$1",
        [(string)$release]
    ), 'Chỉ một audit chốt phát hành');
});

txTest($connection, 'R5: phụ thuộc khác dự án bị từ chối khi thêm và gỡ', function () use ($connection, $devA): void {
    $projectA = testInsertProject($connection, 'R5A');
    $projectB = testInsertProject($connection, 'R5B');
    $a = testInsertIssue($connection, $projectA, 'Việc dự án A', 'medium', 'open', $devA, $devA);
    $b = testInsertIssue($connection, $projectB, 'Việc dự án B', 'medium', 'open', $devA, $devA);
    expectException(
        static fn() => DependencyService::add($connection, $projectA, $a, $b, $devA),
        NotFoundException::class,
        'cùng dự án',
        'Không thêm phụ thuộc khác dự án'
    );
    expectException(
        static fn() => DependencyService::remove($connection, $projectA, $a, $b, $devA),
        NotFoundException::class,
        'cùng dự án',
        'Không gỡ phụ thuộc khác dự án'
    );
    assertSame(0, itCount($connection, 'SELECT count(*) AS total FROM dependencies WHERE issue_id=$1', [(string)$a]), 'Không ghi gì');
});

txTest($connection, 'R6: developer không tự đặt verified qua form cập nhật', function () use ($connection, $devA): void {
    $project = testInsertProject($connection, 'R6');
    $issue = testInsertIssue($connection, $project, 'Việc R6', 'medium', 'resolved', $devA, $devA);
    expectException(
        static fn() => IssueService::update($connection, $project, $devA, null, $issue, itVersion($connection, $issue), itPayload($connection, $issue, ['status' => 'verified'])),
        ValidationException::class,
        'verified',
        'Đặt verified trực tiếp phải bị từ chối'
    );
    assertSame('resolved', (string)testIssueSnapshot($connection, $issue)['status'], 'Trạng thái giữ nguyên');
});

txTest($connection, 'R7: phiên bản ready tự hạ về planned khi công việc bị sửa', function () use ($connection, $devA, $testerA, $releaseA): void {
    $project = testInsertProject($connection, 'R7');
    $issue = testInsertIssue($connection, $project, 'Việc R7', 'medium', 'resolved', $devA, $devA);
    itTest($connection, $project, $testerA, $issue, 'passed');
    $release = itRelease($connection, $project, $releaseA, [$issue]);
    ReleaseService::markReady($connection, $release, $project, $releaseA, null);
    assertSame('ready', itReleaseStatus($connection, $release), 'Đã sẵn sàng');
    IssueService::update($connection, $project, $devA, null, $issue, itVersion($connection, $issue), itPayload($connection, $issue, ['status' => 'in_progress']));
    assertSame('planned', itReleaseStatus($connection, $release), 'Tự hạ về planned');
    expectException(
        static fn() => ReleaseService::release($connection, $release, $project, $releaseA, null),
        ConflictException::class,
        'ready',
        'Phiên bản planned không chốt được'
    );
});

// ===================================================================
// Hai tình huống sử dụng của đề (đầu-cuối)
// ===================================================================

txTest($connection, 'S1: lỗi → phân công → sửa → test lại → xác nhận', function () use ($connection, $devA, $testerA): void {
    $project = testInsertProject($connection, 'S1');
    $status = static fn(int $id): string => (string)testIssueSnapshot($connection, $id)['status'];
    // 1. Tester báo lỗi (bắt buộc có bước tái hiện).
    expectException(
        static fn() => IssueService::create($connection, $project, $testerA, null, [
            'title' => 'Lỗi thiếu bước', 'issue_type' => 'bug', 'priority' => 'high', 'steps' => '',
        ]),
        ValidationException::class,
        'bước tái hiện',
        'Lỗi phải có bước tái hiện'
    );
    $issue = IssueService::create($connection, $project, $testerA, null, [
        'title' => 'Không lưu được hồ sơ',
        'description' => 'Bấm Lưu không có phản hồi.',
        'steps' => "Bước tái hiện: mở hồ sơ, bấm Lưu\nKết quả mong đợi: thông báo đã lưu\nKết quả thực tế: không có gì",
        'issue_type' => 'bug',
        'priority' => 'high',
    ]);
    assertSame('open', $status($issue), 'Mới tạo là open');
    // 2. Phân công cho developer (tester là người báo nên được sửa).
    IssueService::update($connection, $project, $testerA, null, $issue, itVersion($connection, $issue), itPayload($connection, $issue, ['assignee_id' => (string)$devA]));
    // 3. Developer sửa, gắn commit, chuyển resolved.
    IssueService::update($connection, $project, $devA, null, $issue, itVersion($connection, $issue), itPayload($connection, $issue, [
        'status' => 'resolved',
        'commit_url' => 'https://git.example/commit/abc123',
    ]));
    assertSame('resolved', $status($issue), 'Đã sửa');
    // 4. Tester test lại: failed → trả về in_progress.
    itTest($connection, $project, $testerA, $issue, 'failed');
    assertSame('in_progress', $status($issue), 'Failed trả về developer');
    // 5. Developer sửa lại.
    IssueService::update($connection, $project, $devA, null, $issue, itVersion($connection, $issue), itPayload($connection, $issue, ['status' => 'resolved']));
    // 6. Tester xác nhận.
    itTest($connection, $project, $testerA, $issue, 'passed');
    assertSame('verified', $status($issue), 'Đã xác nhận');
    assertTrue(IssueService::hasValidPassedTest($connection, $issue, itVersion($connection, $issue)), 'Có test passed hợp lệ ở version hiện tại');
    $texts = [];
    foreach (IssueService::history($connection, $project, $issue) as $row) {
        $described = History::describe($row);
        if ($described !== null) {
            $texts[] = $described['text'];
        }
    }
    assertSame(['ghi kết quả kiểm thử: Đạt', 'cập nhật', 'ghi kết quả kiểm thử: Không đạt', 'cập nhật', 'cập nhật', 'tạo công việc (ưu tiên Cao)'], $texts, 'Lịch sử có đủ các bước');
});

txTest($connection, 'S2: lập phiên bản → kiểm tra → chốt → xuất ghi chú', function () use ($connection, $devA, $testerA, $releaseA): void {
    $project = testInsertProject($connection, 'S2');
    $feature = testInsertIssue($connection, $project, 'Xuất báo cáo PDF', 'medium', 'resolved', $devA, $devA);
    $bug = testInsertIssue($connection, $project, 'Sửa lỗi phân trang', 'high', 'resolved', $devA, $devA);
    queryParams($connection, "UPDATE issues SET issue_type='feature',commit_url='https://git.example/pr/42' WHERE id=\$1", [(string)$feature]);
    itTest($connection, $project, $testerA, $feature, 'passed');
    itTest($connection, $project, $testerA, $bug, 'passed');
    // 1–2. Lập phiên bản và gắn công việc.
    $release = itRelease($connection, $project, $releaseA, [$feature, $bug]);
    // Developer không được xác nhận/chốt.
    expectException(
        static fn() => ReleaseService::markReady($connection, $release, $project, $devA, null),
        ForbiddenException::class,
        null,
        'Developer không xác nhận sẵn sàng được'
    );
    // 3. Kiểm tra và xác nhận sẵn sàng.
    $check = ReleaseService::check($connection, $release, $project, 'release_manager');
    assertSame(count(ReleaseService::conditions()), count(array_filter($check['checks'], static fn(array $c): bool => $c['passed'])), 'Mọi điều kiện đạt');
    ReleaseService::markReady($connection, $release, $project, $releaseA, null);
    assertSame('ready', itReleaseStatus($connection, $release), 'planned → ready');
    // 4. Chốt.
    ReleaseService::release($connection, $release, $project, $releaseA, null);
    assertSame('released', itReleaseStatus($connection, $release), 'ready → released');
    // 5. Xuất ghi chú.
    $md = ReleaseNotes::export($connection, $release, $devA, 'md');
    assertContains('## Tính năng (1)', $md['content'], 'Nhóm Tính năng');
    assertContains('## Sửa lỗi (1)', $md['content'], 'Nhóm Sửa lỗi');
    assertContains('### #' . $feature . ' — Xuất báo cáo PDF', $md['content'], '#id và tiêu đề');
    assertContains('https://git.example/pr/42', $md['content'], 'Link PR');
    assertContains('Đạt (passed)', $md['content'], 'Kết quả kiểm thử khi chốt');
    $txt = ReleaseNotes::export($connection, $release, $devA, 'txt');
    assertSame('text/plain; charset=utf-8', $txt['mime'], 'TXT mime');
    assertContains('#' . $bug . ' Sửa lỗi phân trang', $txt['content'], 'TXT có công việc');
});

testFinish('INTEGRATION');
