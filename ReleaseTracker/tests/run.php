<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$connection = TestDatabase::boot();
$core = testProjectId($connection, 'ReleaseTracker Core');
$mobile = testProjectId($connection, 'Mobile Client');
$devA = testUserId($connection, 'dev_a');
$testerA = testUserId($connection, 'tester_a');
$releaseA = testUserId($connection, 'release_a');
$adminA = testUserId($connection, 'admin_a');

echo 'TEST_SCHEMA=' . TestDatabase::schema() . "\n";

testCase('seed có đủ dự án, issue, dependency và release', function () use ($connection): void {
    $counts = queryOne(
        $connection,
        'SELECT
            (SELECT count(*) FROM users) AS users,
            (SELECT count(*) FROM projects) AS projects,
            (SELECT count(*) FROM issues) AS issues,
            (SELECT count(*) FROM dependencies) AS dependencies,
            (SELECT count(*) FROM releases) AS releases'
    );
    assertSame(5, (int)$counts['users'], 'Số người dùng mẫu');
    assertSame(2, (int)$counts['projects'], 'Số dự án mẫu');
    assertSame(40, (int)$counts['issues'], 'Số issue mẫu');
    assertSame(15, (int)$counts['dependencies'], 'Số dependency mẫu');
    assertSame(3, (int)$counts['releases'], 'Số release mẫu');
    assertTrue(DependencyService::projectCycle(
        $connection,
        testProjectId($connection, 'ReleaseTracker Core')
    ) === null, 'Seed không được có chu trình phụ thuộc.');
});

testCase('seed không tạo issue verified thiếu test passed đúng version hiện tại', function () use ($connection): void {
    $bad = queryAll(
        $connection,
        "SELECT i.id,i.title,i.version,i.status
         FROM issues i
         WHERE i.status='verified'
           AND NOT EXISTS (
               SELECT 1 FROM test_results t
               WHERE t.issue_id=i.id
                 AND t.id=(
                     SELECT t2.id FROM test_results t2
                     WHERE t2.issue_id=i.id
                     ORDER BY t2.id DESC
                     LIMIT 1
                 )
                 AND t.result='passed'
                 AND t.issue_version=i.version
           )
         ORDER BY i.id"
    );
    assertSame(
        [],
        $bad,
        'Seed không được có issue verified thiếu test mới nhất passed đúng version hiện tại: '
        . json_encode($bad, JSON_UNESCAPED_UNICODE)
    );

    $formError = queryOne(
        $connection,
        "SELECT id,status FROM issues
         WHERE project_id=(SELECT id FROM projects WHERE name='ReleaseTracker Core')
           AND title LIKE 'Thông báo lỗi biểu mẫu%'"
    );
    assertTrue($formError !== null, 'Seed phải có issue Thông báo lỗi biểu mẫu');
    assertSame(
        'resolved',
        (string)$formError['status'],
        'Issue hoàn thành nhưng chưa có test phải ở trạng thái resolved, không phải verified'
    );
});

testCase('đồ thị: tìm đường và phát hiện chu trình thuần PHP', function (): void {
    $adjacency = [1 => [2], 2 => [3], 3 => []];
    assertSame([1, 2, 3], DependencyService::findPath($adjacency, 1, 3), 'Đường đi 1 -> 3');
    assertSame(null, DependencyService::findPath($adjacency, 3, 1), 'Không có đường đi ngược');
    $cycle = DependencyService::findCycle([1 => [2], 2 => [3], 3 => [1]]);
    assertTrue(is_array($cycle), 'Phải phát hiện chu trình');
    assertSame($cycle[0], $cycle[count($cycle) - 1], 'Chu trình phải khép kín');
});

testCase('TEST 1: từ chối chu trình và không lưu quan hệ vòng', function () use ($connection, $devA): void {
    $project = testInsertProject($connection, 'Cycle');
    $a = testInsertIssue($connection, $project, 'A', 'medium', 'open', $devA, $devA);
    $b = testInsertIssue($connection, $project, 'B', 'medium', 'open', $devA, $devA);
    $c = testInsertIssue($connection, $project, 'C', 'medium', 'open', $devA, $devA);
    DependencyService::add($connection, $project, $a, $b, $devA);
    DependencyService::add($connection, $project, $b, $c, $devA);
    $exception = expectException(
        static fn() => DependencyService::add($connection, $project, $c, $a, $devA),
        ConflictException::class,
        'A -> B -> C -> A',
        'Phải từ chối chu trình kèm nhãn A -> B -> C -> A'
    );
    assertContains('vòng', $exception->getMessage(), 'Thông báo phải nói rõ quan hệ vòng');
    $bad = queryOne(
        $connection,
        'SELECT 1 FROM dependencies WHERE issue_id=$1 AND depends_on_id=$2',
        [(string)$c, (string)$a]
    );
    assertSame(null, $bad, 'Database không được lưu cạnh tạo vòng');
    expectException(
        static fn() => DependencyService::add($connection, $project, $a, $b, $devA),
        ConflictException::class,
        'đã tồn tại',
        'Phải từ chối dependency trùng lặp'
    );
    expectException(
        static fn() => DependencyService::add($connection, $project, $a, $a, $devA),
        ValidationException::class,
        'chính nó',
        'Phải từ chối tự phụ thuộc'
    );
});

testCase('TEST 1b: dependency khác dự án bị từ chối', function () use ($connection, $core, $mobile, $devA): void {
    $coreIssue = queryOne(
        $connection,
        'SELECT id FROM issues WHERE project_id=$1 ORDER BY id LIMIT 1',
        [(string)$core]
    );
    $mobileIssue = queryOne(
        $connection,
        'SELECT id FROM issues WHERE project_id=$1 ORDER BY id LIMIT 1',
        [(string)$mobile]
    );
    assertTrue($coreIssue !== null && $mobileIssue !== null, 'Cần issue ở hai dự án');
    expectException(
        static fn() => DependencyService::add(
            $connection,
            $core,
            (int)$coreIssue['id'],
            (int)$mobileIssue['id'],
            $devA
        ),
        NotFoundException::class,
        'cùng dự án',
        'Phải từ chối dependency khác dự án'
    );
});

testCase('TEST 1c: dependency trùng bị từ chối, không đổi dữ liệu và không ghi audit giả', function () use (
    $connection,
    $core,
    $devA
): void {
    $source = queryOne(
        $connection,
        "SELECT id FROM issues WHERE project_id=\$1 AND title LIKE 'Đồng bộ trạng thái%'",
        [(string)$core]
    );
    $target = queryOne(
        $connection,
        "SELECT id FROM issues WHERE project_id=\$1 AND title LIKE 'Liên kết commit%'",
        [(string)$core]
    );
    assertTrue($source !== null && $target !== null, 'Cần cặp issue seed cho dependency trùng');
    $sourceId = (int)$source['id'];
    $targetId = (int)$target['id'];

    $existing = queryOne(
        $connection,
        'SELECT 1 FROM dependencies WHERE issue_id=$1 AND depends_on_id=$2',
        [(string)$sourceId, (string)$targetId]
    );
    assertTrue($existing !== null, 'Dependency seed phải tồn tại trước khi kiểm tra trùng');

    $dependencyCount = static fn(): int => (int)queryOne(
        $connection,
        'SELECT count(*) AS total FROM dependencies WHERE issue_id=$1 AND depends_on_id=$2',
        [(string)$sourceId, (string)$targetId]
    )['total'];
    $auditCount = static fn(): int => (int)queryOne(
        $connection,
        "SELECT count(*) AS total FROM audit_log
         WHERE project_id=\$1 AND action='dependency_added'",
        [(string)$core]
    )['total'];
    $beforeDependency = $dependencyCount();
    $beforeAudit = $auditCount();

    expectException(
        static fn() => DependencyService::add($connection, $core, $sourceId, $targetId, $devA),
        ConflictException::class,
        'đã tồn tại',
        'Phải từ chối dependency đã tồn tại'
    );

    assertSame($beforeDependency, $dependencyCount(), 'Dependency trùng không được tạo thêm dòng');
    assertSame($beforeAudit, $auditCount(), 'Dependency trùng không được ghi audit thành công giả');
    assertSame(
        PGSQL_TRANSACTION_IDLE,
        pg_transaction_status($connection),
        'Transaction phải rollback sạch, không để lại trạng thái mở'
    );
});

testCase('TEST 2: blocker chưa kiểm thử lại thì không được ready/release', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'Blocker');
    $issue = testInsertIssue($connection, $project, 'Blocker chưa retest', 'blocker', 'open', $devA, $devA);
    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => '0.9.0',
        'description' => 'Kiểm thử blocker',
    ]);
    ReleaseService::addItem($connection, $release, $project, $issue, $releaseA, 'release_manager');
    expectException(
        static fn() => ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager'),
        ValidationException::class,
        'chưa có kết quả kiểm thử lại',
        'Blocker chưa test phải chặn ready'
    );
    assertSame(0, (int)queryOne(
        $connection,
        "SELECT count(*) AS total FROM audit_log WHERE action='release_ready' AND entity_id=\$1",
        [(string)$release]
    )['total'], 'Ready thất bại không được ghi audit thành công');
    expectException(
        static fn() => ReleaseService::release($connection, $release, $project, $releaseA, 'release_manager'),
        ConflictException::class,
        'ready',
        'Release planned không được chốt trực tiếp'
    );
    IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
        'result' => 'failed',
        'notes' => 'Còn lỗi',
    ]);
    expectException(
        static fn() => ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager'),
        ValidationException::class,
        'chưa đạt',
        'Kết quả failed phải chặn ready'
    );
    IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
        'result' => 'passed',
        'notes' => 'Đã qua',
    ]);
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');
    assertSame('ready', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Blocker đã retest hợp lệ phải cho ready');
    $current = testIssueSnapshot($connection, $issue);
    IssueService::update(
        $connection,
        $project,
        $devA,
        'developer',
        $issue,
        (int)$current['version'],
        [
            'title' => 'Blocker đã đổi nội dung',
            'description' => 'Nội dung mới',
            'steps' => 'Bước mới',
            'priority' => 'blocker',
            'status' => 'in_progress',
            'assignee_id' => (string)$devA,
            'commit_url' => '',
        ]
    );
    assertSame('planned', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Sửa issue phải đưa release ready về planned');
    expectException(
        static fn() => ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager'),
        ValidationException::class,
        'lỗi thời',
        'Kết quả passed cũ phải bị coi là lỗi thời'
    );
    expectException(
        static fn() => ReleaseService::markReady($connection, $release, $project, $devA, 'developer'),
        ForbiddenException::class,
        'release manager',
        'Developer không được chuyển ready'
    );
});

testCase('TEST 2b: blocker closed trong release vẫn phải có test hiện tại', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'BlockerClosedItem');
    $issue = testInsertIssue($connection, $project, 'Blocker closed item', 'blocker', 'open', $devA, $devA);
    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => '2b.1.0',
        'description' => 'Blocker closed trong release',
    ]);
    ReleaseService::addItem($connection, $release, $project, $issue, $releaseA, 'release_manager');

    $current = testIssueSnapshot($connection, $issue);
    IssueService::update(
        $connection,
        $project,
        $devA,
        'developer',
        $issue,
        (int)$current['version'],
        [
            'title' => (string)$current['title'],
            'description' => (string)$current['description'],
            'steps' => (string)$current['reproduction_steps'],
            'priority' => (string)$current['priority'],
            'status' => 'closed',
            'assignee_id' => (string)$current['assignee_id'],
            'commit_url' => (string)$current['commit_url'],
        ]
    );
    expectException(
        static fn() => ReleaseService::markReady(
            $connection,
            $release,
            $project,
            $releaseA,
            'release_manager'
        ),
        ValidationException::class,
        'chưa có kết quả kiểm thử lại',
        'Blocker closed chưa có test phải chặn ready'
    );

    $current = testIssueSnapshot($connection, $issue);
    IssueService::update(
        $connection,
        $project,
        $devA,
        'developer',
        $issue,
        (int)$current['version'],
        [
            'title' => (string)$current['title'],
            'description' => (string)$current['description'],
            'steps' => (string)$current['reproduction_steps'],
            'priority' => (string)$current['priority'],
            'status' => 'open',
            'assignee_id' => (string)$current['assignee_id'],
            'commit_url' => (string)$current['commit_url'],
        ]
    );
    IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
        'result' => 'passed',
        'notes' => 'Pass trước khi đóng lại',
    ]);
    $current = testIssueSnapshot($connection, $issue);
    IssueService::update(
        $connection,
        $project,
        $devA,
        'developer',
        $issue,
        (int)$current['version'],
        [
            'title' => (string)$current['title'],
            'description' => (string)$current['description'],
            'steps' => (string)$current['reproduction_steps'],
            'priority' => (string)$current['priority'],
            'status' => 'closed',
            'assignee_id' => (string)$current['assignee_id'],
            'commit_url' => (string)$current['commit_url'],
        ]
    );
    expectException(
        static fn() => ReleaseService::markReady(
            $connection,
            $release,
            $project,
            $releaseA,
            'release_manager'
        ),
        ValidationException::class,
        'lỗi thời',
        'Blocker closed có test passed cũ phải chặn ready'
    );
    queryParams(
        $connection,
        "UPDATE releases SET status='ready' WHERE id=$1",
        [(string)$release]
    );
    expectException(
        static fn() => ReleaseService::release(
            $connection,
            $release,
            $project,
            $releaseA,
            'release_manager'
        ),
        ValidationException::class,
        'lỗi thời',
        'Blocker closed có test passed cũ phải chặn chốt release'
    );
    assertSame('ready', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Chốt release thất bại không được đổi trạng thái ready');

    $current = testIssueSnapshot($connection, $issue);
    IssueService::update(
        $connection,
        $project,
        $devA,
        'developer',
        $issue,
        (int)$current['version'],
        [
            'title' => (string)$current['title'],
            'description' => (string)$current['description'],
            'steps' => (string)$current['reproduction_steps'],
            'priority' => (string)$current['priority'],
            'status' => 'open',
            'assignee_id' => (string)$current['assignee_id'],
            'commit_url' => (string)$current['commit_url'],
        ]
    );
    IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
        'result' => 'passed',
        'notes' => 'Retest sau khi mở lại',
    ]);
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');
    ReleaseService::release($connection, $release, $project, $releaseA, 'release_manager');
    assertSame('released', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Blocker closed được retest hợp lệ phải phát hành được');
});

testCase('TEST 2c: blocker dependency closed vẫn phải có test hiện tại', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'BlockerClosedDep');
    $dependency = testInsertIssue($connection, $project, 'Blocker dependency closed', 'blocker', 'open', $devA, $devA);
    $item = testInsertIssue($connection, $project, 'Release item phụ thuộc blocker', 'medium', 'open', $devA, $devA);
    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => '2c.1.0',
        'description' => 'Blocker dependency closed',
    ]);
    ReleaseService::addItem($connection, $release, $project, $item, $releaseA, 'release_manager');
    DependencyService::add($connection, $project, $item, $dependency, $devA);

    $current = testIssueSnapshot($connection, $dependency);
    IssueService::update(
        $connection,
        $project,
        $devA,
        'developer',
        $dependency,
        (int)$current['version'],
        [
            'title' => (string)$current['title'],
            'description' => (string)$current['description'],
            'steps' => (string)$current['reproduction_steps'],
            'priority' => (string)$current['priority'],
            'status' => 'closed',
            'assignee_id' => (string)$current['assignee_id'],
            'commit_url' => (string)$current['commit_url'],
        ]
    );
    expectException(
        static fn() => ReleaseService::markReady(
            $connection,
            $release,
            $project,
            $releaseA,
            'release_manager'
        ),
        ValidationException::class,
        'chưa có kết quả kiểm thử lại',
        'Blocker dependency closed chưa có test phải chặn ready'
    );
    queryParams(
        $connection,
        "UPDATE releases SET status='ready' WHERE id=$1",
        [(string)$release]
    );
    expectException(
        static fn() => ReleaseService::release(
            $connection,
            $release,
            $project,
            $releaseA,
            'release_manager'
        ),
        ValidationException::class,
        'chưa có kết quả kiểm thử lại',
        'Blocker dependency closed chưa có test phải chặn chốt release'
    );

    $current = testIssueSnapshot($connection, $dependency);
    IssueService::update(
        $connection,
        $project,
        $devA,
        'developer',
        $dependency,
        (int)$current['version'],
        [
            'title' => (string)$current['title'],
            'description' => (string)$current['description'],
            'steps' => (string)$current['reproduction_steps'],
            'priority' => (string)$current['priority'],
            'status' => 'open',
            'assignee_id' => (string)$current['assignee_id'],
            'commit_url' => (string)$current['commit_url'],
        ]
    );
    IssueService::recordTest($connection, $project, $testerA, 'tester', $dependency, [
        'result' => 'passed',
        'notes' => 'Pass trước khi đóng lại',
    ]);
    $current = testIssueSnapshot($connection, $dependency);
    IssueService::update(
        $connection,
        $project,
        $devA,
        'developer',
        $dependency,
        (int)$current['version'],
        [
            'title' => (string)$current['title'],
            'description' => (string)$current['description'],
            'steps' => (string)$current['reproduction_steps'],
            'priority' => (string)$current['priority'],
            'status' => 'closed',
            'assignee_id' => (string)$current['assignee_id'],
            'commit_url' => (string)$current['commit_url'],
        ]
    );
    expectException(
        static fn() => ReleaseService::markReady(
            $connection,
            $release,
            $project,
            $releaseA,
            'release_manager'
        ),
        ValidationException::class,
        'lỗi thời',
        'Blocker dependency closed có test passed cũ phải chặn ready'
    );
    queryParams(
        $connection,
        "UPDATE releases SET status='ready' WHERE id=$1",
        [(string)$release]
    );
    expectException(
        static fn() => ReleaseService::release(
            $connection,
            $release,
            $project,
            $releaseA,
            'release_manager'
        ),
        ValidationException::class,
        'lỗi thời',
        'Blocker dependency closed có test passed cũ phải chặn chốt release'
    );
    assertSame('ready', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Chốt release thất bại không được đổi trạng thái ready');

    $current = testIssueSnapshot($connection, $dependency);
    IssueService::update(
        $connection,
        $project,
        $devA,
        'developer',
        $dependency,
        (int)$current['version'],
        [
            'title' => (string)$current['title'],
            'description' => (string)$current['description'],
            'steps' => (string)$current['reproduction_steps'],
            'priority' => (string)$current['priority'],
            'status' => 'open',
            'assignee_id' => (string)$current['assignee_id'],
            'commit_url' => (string)$current['commit_url'],
        ]
    );
    IssueService::recordTest($connection, $project, $testerA, 'tester', $dependency, [
        'result' => 'passed',
        'notes' => 'Retest sau khi mở lại',
    ]);
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');
    ReleaseService::release($connection, $release, $project, $releaseA, 'release_manager');
    assertSame('released', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Blocker dependency closed được retest hợp lệ phải phát hành được');
});

testCase('TEST 3a: optimistic locking tuần tự giữ nguyên dữ liệu của client thắng', function () use (
    $connection,
    $devA
): void {
    $project = testInsertProject($connection, 'Lock');
    $issue = testInsertIssue($connection, $project, 'Bản gốc', 'medium', 'open', $devA, $devA);
    $fields = [
        'title' => 'Client A',
        'description' => 'A',
        'steps' => 'A',
        'priority' => 'medium',
        'status' => 'in_progress',
        'assignee_id' => (string)$devA,
        'commit_url' => '',
    ];
    $newVersion = IssueService::update($connection, $project, $devA, 'developer', $issue, 1, $fields);
    assertSame(2, $newVersion, 'Version phải tăng đúng một lần');
    $fields['title'] = 'Client B';
    expectException(
        static fn() => IssueService::update(
            $connection,
            $project,
            $devA,
            'developer',
            $issue,
            1,
            $fields
        ),
        ConflictException::class,
        'XUNG ĐỘT',
        'Client B dùng version cũ phải nhận xung đột'
    );
    $stored = testIssueSnapshot($connection, $issue);
    assertSame('Client A', (string)$stored['title'], 'Không được ghi đè dữ liệu client A');
    assertSame(2, (int)$stored['version'], 'Version không được tăng sai');
});

testCase('TEST 3b: optimistic locking cạnh tranh bằng hai process', function () use (
    $connection,
    $devA
): void {
    $project = testInsertProject($connection, 'LockRace');
    $issue = testInsertIssue($connection, $project, 'Race gốc', 'medium', 'open', $devA, $devA);
    $base = [
        'description' => 'Race',
        'steps' => 'Race',
        'priority' => 'medium',
        'status' => 'in_progress',
        'assignee_id' => (string)$devA,
        'commit_url' => '',
    ];
    $results = runWorkers([
        [
            'mode' => 'issue_update',
            'input' => [
                'project_id' => $project,
                'user_id' => $devA,
                'role' => 'developer',
                'issue_id' => $issue,
                'base_version' => 1,
                'fields' => ['title' => 'Race A'] + $base,
            ],
        ],
        [
            'mode' => 'issue_update',
            'input' => [
                'project_id' => $project,
                'user_id' => $devA,
                'role' => 'developer',
                'issue_id' => $issue,
                'base_version' => 1,
                'fields' => ['title' => 'Race B'] + $base,
            ],
        ],
    ]);
    $wins = 0;
    $conflicts = 0;
    foreach ($results as $result) {
        assertSame(0, $result['exit'], 'Worker phải thoát bình thường: ' . $result['stderr']);
        assertTrue($result['json'] !== null, 'Worker phải trả JSON: ' . $result['stdout']);
        if (($result['json']['ok'] ?? false) === true) {
            $wins++;
        } elseif (str_contains((string)($result['json']['message'] ?? ''), 'XUNG ĐỘT')) {
            $conflicts++;
        }
    }
    assertSame(1, $wins, 'Phải có đúng một process cập nhật thành công');
    assertSame(1, $conflicts, 'Phải có đúng một process nhận xung đột');
    $stored = testIssueSnapshot($connection, $issue);
    assertSame(2, (int)$stored['version'], 'Version cuối phải là 2');
    assertTrue(
        in_array((string)$stored['title'], ['Race A', 'Race B'], true),
        'Title cuối phải là của một client thắng'
    );
});

testCase('TEST 4: phân quyền chéo dự án ở service/query', function () use (
    $connection,
    $core,
    $mobile,
    $devA,
    $testerA
): void {
    $mobileIssue = queryOne(
        $connection,
        'SELECT * FROM issues WHERE project_id=$1 ORDER BY id LIMIT 1',
        [(string)$mobile]
    );
    assertTrue($mobileIssue !== null, 'Cần issue Mobile');
    $before = testIssueSnapshot($connection, (int)$mobileIssue['id']);
    expectException(
        static fn() => IssueService::update(
            $connection,
            $core,
            $devA,
            'developer',
            (int)$mobileIssue['id'],
            (int)$before['version'],
            [
                'title' => 'Bị sửa chéo dự án',
                'description' => 'x',
                'steps' => 'x',
                'priority' => 'low',
                'status' => 'open',
                'assignee_id' => '',
                'commit_url' => '',
            ]
        ),
        NotFoundException::class,
        'dự án',
        'Service phải chặn sửa issue khác dự án'
    );
    expectException(
        static fn() => IssueService::addComment(
            $connection,
            $core,
            $devA,
            'developer',
            (int)$mobileIssue['id'],
            ['body' => 'Bình luận chéo dự án']
        ),
        NotFoundException::class,
        'dự án',
        'Service phải chặn comment issue khác dự án'
    );
    expectException(
        static fn() => IssueService::recordTest(
            $connection,
            $core,
            $testerA,
            'tester',
            (int)$mobileIssue['id'],
            ['result' => 'passed', 'notes' => 'x']
        ),
        NotFoundException::class,
        'dự án',
        'Service phải chặn test issue khác dự án'
    );
    $after = testIssueSnapshot($connection, (int)$mobileIssue['id']);
    assertSame((string)$before['title'], (string)$after['title'], 'Dữ liệu dự án B không được đổi');
    assertSame((int)$before['version'], (int)$after['version'], 'Version dự án B không được đổi');
});

testCase('TEST 5: snapshot và release notes bất biến sau khi sửa issue nguồn', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'Snapshot');
    $issue = testInsertIssue(
        $connection,
        $project,
        'Snapshot cũ',
        'medium',
        'open',
        $devA,
        $devA
    );
    IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
        'result' => 'passed',
        'notes' => 'Pass ban đầu',
    ]);
    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => '1.0.0',
        'description' => 'Snapshot test',
    ]);
    ReleaseService::addItem($connection, $release, $project, $issue, $releaseA, 'release_manager');
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');
    ReleaseService::release($connection, $release, $project, $releaseA, 'release_manager');
    $snapshotBefore = queryOne(
        $connection,
        'SELECT * FROM release_items WHERE release_id=$1 AND issue_id=$2',
        [(string)$release, (string)$issue]
    );
    assertTrue($snapshotBefore !== null, 'Phải có snapshot sau release');
    $notesBefore = ReleaseNotes::markdown($connection, $release);
    $current = testIssueSnapshot($connection, $issue);
    IssueService::update(
        $connection,
        $project,
        $devA,
        'developer',
        $issue,
        (int)$current['version'],
        [
            'title' => 'Snapshot mới',
            'description' => 'Mô tả mới',
            'steps' => 'Bước mới',
            'priority' => 'critical',
            'status' => 'in_progress',
            'assignee_id' => (string)$devA,
            'commit_url' => 'https://example.test/new',
        ]
    );
    $snapshotAfter = queryOne(
        $connection,
        'SELECT * FROM release_items WHERE release_id=$1 AND issue_id=$2',
        [(string)$release, (string)$issue]
    );
    assertSame($snapshotBefore, $snapshotAfter, 'Snapshot không được đổi');
    $notesAfter = ReleaseNotes::markdown($connection, $release);
    assertSame($notesBefore, $notesAfter, 'Release notes cũ không được đổi');
    assertContains('Snapshot cũ', $notesAfter, 'Notes phải lấy tiêu đề snapshot cũ');
    assertNotContains('Snapshot mới', $notesAfter, 'Notes không được lấy dữ liệu issue hiện tại');
    assertSame('released', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Release phải giữ trạng thái released');
    expectException(
        static fn() => ReleaseService::addItem($connection, $release, $project, $issue, $releaseA, 'release_manager'),
        ConflictException::class,
        'planned',
        'Không được thêm item vào release đã chốt'
    );
    $directUpdate = @pg_query_params(
        $connection,
        "UPDATE release_items SET snapshot_title='HACKED' WHERE release_id=\$1",
        [(string)$release]
    );
    assertSame(false, $directUpdate, 'Trigger phải chặn sửa snapshot đã chốt');
    $stillOld = queryOne(
        $connection,
        'SELECT snapshot_title FROM release_items WHERE release_id=$1 AND issue_id=$2',
        [(string)$release, (string)$issue]
    );
    assertSame('Snapshot cũ', (string)$stillOld['snapshot_title'], 'Snapshot vẫn phải giữ dữ liệu cũ');
});

testCase('TEST 5b: hai process chốt cùng release chỉ một thành công', function () use (
    $connection,
    $devA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'ReleaseRace');
    $issue = testInsertIssue($connection, $project, 'Release race issue', 'medium', 'open', $devA, $devA);
    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => '2.0.0',
        'description' => 'Race release',
    ]);
    ReleaseService::addItem($connection, $release, $project, $issue, $releaseA, 'release_manager');
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');
    $results = runWorkers([
        [
            'mode' => 'release',
            'input' => [
                'release_id' => $release,
                'project_id' => $project,
                'user_id' => $releaseA,
                'role' => 'release_manager',
            ],
        ],
        [
            'mode' => 'release',
            'input' => [
                'release_id' => $release,
                'project_id' => $project,
                'user_id' => $releaseA,
                'role' => 'release_manager',
            ],
        ],
    ]);
    $wins = 0;
    $conflicts = 0;
    foreach ($results as $result) {
        assertSame(0, $result['exit'], 'Worker release phải thoát bình thường: ' . $result['stderr']);
        assertTrue($result['json'] !== null, 'Worker phải trả JSON');
        if (($result['json']['ok'] ?? false) === true) {
            $wins++;
        } else {
            $conflicts++;
        }
    }
    assertSame(1, $wins, 'Chỉ một process được chốt release');
    assertSame(1, $conflicts, 'Process còn lại phải nhận lỗi');
    $releaseRow = queryOne($connection, 'SELECT * FROM releases WHERE id=$1', [(string)$release]);
    assertSame('released', (string)$releaseRow['status'], 'Release phải ở trạng thái released');
    $auditCount = queryOne(
        $connection,
        "SELECT count(*) AS total FROM audit_log
         WHERE action='release_snapshotted' AND entity_id=\$1",
        [(string)$release]
    );
    assertSame(1, (int)$auditCount['total'], 'Chỉ được ghi một audit chốt release');
});

testCase('transaction rollback khi lỗi giữa lúc chốt release', function () use (
    $connection,
    $devA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'Rollback');
    $issue = testInsertIssue($connection, $project, 'Rollback issue', 'medium', 'open', $devA, $devA);
    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => '3.0.0',
        'description' => 'Rollback test',
    ]);
    ReleaseService::addItem($connection, $release, $project, $issue, $releaseA, 'release_manager');
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');
    $snapshotBefore = queryOne(
        $connection,
        'SELECT * FROM release_items WHERE release_id=$1 AND issue_id=$2',
        [(string)$release, (string)$issue]
    );
    execSql($connection, "CREATE OR REPLACE FUNCTION rt_test_fail_release() RETURNS trigger AS \$\$
        BEGIN
            RAISE EXCEPTION 'TEST_FAIL_RELEASE';
        END;
        \$\$ LANGUAGE plpgsql");
    execSql(
        $connection,
        "CREATE TRIGGER trg_test_fail_release
         BEFORE UPDATE ON releases
         FOR EACH ROW WHEN (NEW.status='released')
         EXECUTE FUNCTION rt_test_fail_release()"
    );
    try {
        expectException(
            static fn() => ReleaseService::release($connection, $release, $project, $releaseA, 'release_manager'),
            RuntimeException::class,
            'DATABASE_OPERATION_FAILED',
            'Lỗi giữa transaction phải được ném ra'
        );
    } finally {
        execSql($connection, 'DROP TRIGGER IF EXISTS trg_test_fail_release ON releases');
        execSql($connection, 'DROP FUNCTION IF EXISTS rt_test_fail_release()');
    }
    assertSame('ready', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Release phải rollback về ready');
    $snapshotAfter = queryOne(
        $connection,
        'SELECT * FROM release_items WHERE release_id=$1 AND issue_id=$2',
        [(string)$release, (string)$issue]
    );
    assertSame($snapshotBefore, $snapshotAfter, 'Snapshot phải rollback, không ghi dở');
    assertSame(0, (int)queryOne(
        $connection,
        "SELECT count(*) AS total FROM audit_log
         WHERE action='release_snapshotted' AND entity_id=\$1",
        [(string)$release]
    )['total'], 'Không được ghi audit chốt khi transaction rollback');
    ReleaseService::release($connection, $release, $project, $releaseA, 'release_manager');
    assertSame('released', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Sau khi hết lỗi phải chốt được release');
});

testCase('quyền theo vai trò và validation đầu vào', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA,
    $adminA,
    $core
): void {
    $issue = testInsertIssue($connection, $core, 'Issue quyền ' . bin2hex(random_bytes(3)), 'medium', 'open', $devA, $devA);
    expectException(
        static fn() => IssueService::recordTest($connection, $core, $devA, 'developer', $issue, [
            'result' => 'passed',
        ]),
        ForbiddenException::class,
        'tester',
        'Developer không được ghi test result'
    );
    expectException(
        static fn() => IssueService::recordTest($connection, $core, $testerA, 'tester', $issue, [
            'result' => 'maybe',
        ]),
        ValidationException::class,
        'không hợp lệ',
        'Enum test result phải được validate'
    );
    expectException(
        static fn() => IssueService::addComment($connection, $core, $devA, 'developer', $issue, [
            'body' => '   ',
        ]),
        ValidationException::class,
        'không được để trống',
        'Comment rỗng phải bị từ chối'
    );
    assertSame(false, canManageProject('release_manager'), 'Release manager không phải admin dự án');
    assertSame(true, canManageProject('admin'), 'Admin được quản lý dự án');
    assertSame(false, canViewAudit('tester'), 'Tester không được xem audit');
    assertSame(true, canViewAudit('release_manager'), 'Release manager được xem audit');
    assertSame(true, canCreateIssue('developer'), 'Developer được tạo issue');
    assertSame(false, canTestIssue('developer'), 'Developer không được test');
    assertSame(true, canTestIssue('tester'), 'Tester được test');
    assertSame(true, isReleaseManager($connection, $core, ['id' => $releaseA]), 'Release manager hợp lệ');
    assertSame(true, isReleaseManager($connection, $core, ['id' => $adminA]), 'Admin có quyền quản lý release theo chính sách');
});

testCase('xác thực mật khẩu và CSRF không fail-open', function () use ($connection): void {
    $row = queryOne($connection, "SELECT password_hash FROM users WHERE username='dev_a'");
    assertTrue($row !== null, 'Phải có tài khoản dev_a');
    assertSame(true, password_verify('demo1234', (string)$row['password_hash']), 'Mật khẩu demo phải đúng');
    assertSame(false, password_verify('sai-mat-khau', (string)$row['password_hash']), 'Mật khẩu sai phải bị từ chối');
    $_SESSION['csrf'] = 'csrf-test-token';
    assertSame(true, isCsrfValid('csrf-test-token'), 'Token đúng phải hợp lệ');
    assertSame(false, isCsrfValid(''), 'Token rỗng phải bị từ chối');
    assertSame(false, isCsrfValid('sai'), 'Token sai phải bị từ chối');
    $_POST['csrf'] = '';
    assertSame(false, isCsrfValid(null), 'Session và request cùng rỗng không được fail-open');
    $_POST['csrf'] = 'csrf-test-token';
    assertSame(true, isCsrfValid(null), 'Token từ POST phải được kiểm tra');
    unset($_POST['csrf']);
    assertTrue(csrfToken() !== '', 'Phải sinh được CSRF token');
});

testCase('audit log không chứa mật khẩu/token', function () use ($connection, $core): void {
    $rows = queryAll(
        $connection,
        'SELECT details::text AS details FROM audit_log WHERE project_id=$1',
        [(string)$core]
    );
    foreach ($rows as $row) {
        $text = strtolower((string)$row['details']);
        assertNotContains('password', $text, 'Audit không được chứa password');
        assertNotContains('csrf', $text, 'Audit không được chứa CSRF token');
        assertNotContains('session', $text, 'Audit không được chứa session token');
    }
});

testCase('TEST A: no-op sau passed không tăng version, không mất test/release ready', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'Noop');
    $issue = testInsertIssue($connection, $project, 'No-op issue', 'medium', 'open', $devA, $devA);
    IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
        'result' => 'passed',
        'notes' => 'Pass trước no-op',
    ]);
    $before = testIssueSnapshot($connection, $issue);
    assertSame('verified', (string)$before['status'], 'Issue phải verified sau test passed');
    assertSame(2, (int)$before['version'], 'Version phải tăng sau test passed');

    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => 'A.1.0',
        'description' => 'Release cho test no-op',
    ]);
    ReleaseService::addItem($connection, $release, $project, $issue, $releaseA, 'release_manager');
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');
    $releaseBefore = queryOne($connection, 'SELECT status FROM releases WHERE id=$1', [(string)$release]);
    assertSame('ready', (string)$releaseBefore['status'], 'Release phải ready trước no-op');

    $latestBefore = queryOne(
        $connection,
        'SELECT result,issue_version FROM test_results WHERE issue_id=$1 ORDER BY id DESC LIMIT 1',
        [(string)$issue]
    );
    $auditBefore = queryOne(
        $connection,
        "SELECT count(*) AS total FROM audit_log WHERE action='issue_updated' AND entity_id=\$1",
        [(string)$issue]
    );
    $fields = [
        'title' => (string)$before['title'],
        'description' => (string)$before['description'],
        'steps' => (string)$before['reproduction_steps'],
        'priority' => (string)$before['priority'],
        'status' => (string)$before['status'],
        'assignee_id' => (string)$before['assignee_id'],
        'commit_url' => (string)$before['commit_url'],
    ];
    $returnedVersion = IssueService::update(
        $connection,
        $project,
        $devA,
        'developer',
        $issue,
        (int)$before['version'],
        $fields
    );
    $after = testIssueSnapshot($connection, $issue);
    $latestAfter = queryOne(
        $connection,
        'SELECT result,issue_version FROM test_results WHERE issue_id=$1 ORDER BY id DESC LIMIT 1',
        [(string)$issue]
    );
    $auditAfter = queryOne(
        $connection,
        "SELECT count(*) AS total FROM audit_log WHERE action='issue_updated' AND entity_id=\$1",
        [(string)$issue]
    );
    $releaseAfter = queryOne($connection, 'SELECT status FROM releases WHERE id=$1', [(string)$release]);

    assertSame((int)$before['version'], $returnedVersion, 'No-op phải trả lại version hiện tại');
    assertSame((int)$before['version'], (int)$after['version'], 'No-op không được tăng version');
    assertSame((string)$before['updated_at'], (string)$after['updated_at'], 'No-op không được đổi updated_at');
    assertSame('verified', (string)$after['status'], 'No-op phải giữ verified');
    assertSame((int)$latestBefore['issue_version'], (int)$latestAfter['issue_version'], 'No-op không được làm test cũ đi');
    assertSame('passed', (string)$latestAfter['result'], 'Test passed phải còn hiệu lực');
    assertSame(
        true,
        IssueService::hasValidPassedTest($connection, $issue, (int)$after['version']),
        'No-op phải giữ test passed hợp lệ với version hiện tại'
    );
    assertSame((int)$auditBefore['total'], (int)$auditAfter['total'], 'No-op không được ghi audit issue_updated');
    assertSame('ready', (string)$releaseAfter['status'], 'No-op không được hạ release ready');
});

testCase('TEST B: sửa nội dung sau passed phải làm mất hiệu lực xác nhận', function () use (
    $connection,
    $devA,
    $testerA
): void {
    $project = testInsertProject($connection, 'Changed');
    $issue = testInsertIssue($connection, $project, 'Issue đã xác nhận', 'medium', 'open', $devA, $devA);
    IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
        'result' => 'passed',
        'notes' => 'Pass ban đầu',
    ]);
    $before = testIssueSnapshot($connection, $issue);
    assertSame('verified', (string)$before['status'], 'Issue phải verified trước khi sửa');

    $changedFields = [
        'title' => 'Nội dung mới sau kiểm thử',
        'description' => (string)$before['description'],
        'steps' => (string)$before['reproduction_steps'],
        'priority' => (string)$before['priority'],
        'status' => 'verified',
        'assignee_id' => (string)$before['assignee_id'],
        'commit_url' => (string)$before['commit_url'],
    ];
    expectException(
        static fn() => IssueService::update(
            $connection,
            $project,
            $devA,
            'developer',
            $issue,
            (int)$before['version'],
            $changedFields
        ),
        ValidationException::class,
        'kiểm thử lại',
        'Không được nhận verified khi nội dung thay đổi nhưng chỉ có test cũ'
    );
    $unchanged = testIssueSnapshot($connection, $issue);
    assertSame((string)$before['title'], (string)$unchanged['title'], 'Yêu cầu bị từ chối không được sửa dữ liệu');
    assertSame((int)$before['version'], (int)$unchanged['version'], 'Yêu cầu bị từ chối không được tăng version');

    $changedFields['status'] = 'open';
    $newVersion = IssueService::update(
        $connection,
        $project,
        $devA,
        'developer',
        $issue,
        (int)$before['version'],
        $changedFields
    );
    $after = testIssueSnapshot($connection, $issue);
    $latest = queryOne(
        $connection,
        'SELECT result,issue_version FROM test_results WHERE issue_id=$1 ORDER BY id DESC LIMIT 1',
        [(string)$issue]
    );
    assertSame((int)$before['version'] + 1, $newVersion, 'Sửa nội dung phải tăng version đúng một lần');
    assertSame('open', (string)$after['status'], 'Issue không được giữ verified sau khi nội dung đổi');
    assertSame((int)$before['version'], (int)$latest['issue_version'], 'Test vẫn phải gắn với version cũ');
    assertSame(
        false,
        IssueService::hasValidPassedTest($connection, $issue, (int)$after['version']),
        'Test cũ không được coi là hợp lệ cho version mới'
    );
});

testCase('TEST C: release chặn dependency có kết quả kiểm thử lỗi thời', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'DepStale');
    $dependency = testInsertIssue($connection, $project, 'Dependency B', 'medium', 'open', $devA, $devA);
    $item = testInsertIssue($connection, $project, 'Release item A', 'medium', 'open', $devA, $devA);
    IssueService::recordTest($connection, $project, $testerA, 'tester', $dependency, [
        'result' => 'passed',
        'notes' => 'Pass ban đầu',
    ]);
    DependencyService::add($connection, $project, $item, $dependency, $devA);
    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => 'C.1.0',
        'description' => 'Release cho dependency stale',
    ]);
    ReleaseService::addItem($connection, $release, $project, $item, $releaseA, 'release_manager');
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');
    assertSame('ready', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Release phải ready khi dependency còn test hợp lệ');

    $dependencyBefore = testIssueSnapshot($connection, $dependency);
    IssueService::update(
        $connection,
        $project,
        $devA,
        'developer',
        $dependency,
        (int)$dependencyBefore['version'],
        [
            'title' => 'Dependency B đã đổi nội dung',
            'description' => (string)$dependencyBefore['description'],
            'steps' => (string)$dependencyBefore['reproduction_steps'],
            'priority' => (string)$dependencyBefore['priority'],
            'status' => 'open',
            'assignee_id' => (string)$dependencyBefore['assignee_id'],
            'commit_url' => (string)$dependencyBefore['commit_url'],
        ]
    );
    assertSame('planned', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Release ready phải bị hạ khi dependency thay đổi');
    queryParams(
        $connection,
        "UPDATE issues SET status='verified' WHERE id=$1 AND project_id=$2",
        [(string)$dependency, (string)$project]
    );
    $stale = testIssueSnapshot($connection, $dependency);
    $staleTest = queryOne(
        $connection,
        'SELECT result,issue_version FROM test_results WHERE issue_id=$1 ORDER BY id DESC LIMIT 1',
        [(string)$dependency]
    );
    assertSame('verified', (string)$stale['status'], 'Dữ liệu mô phỏng phải là verified');
    assertSame((int)$staleTest['issue_version'], (int)$stale['version'] - 1, 'Test phải lỗi thời sau khi đổi nội dung');

    $exception = expectException(
        static fn() => ReleaseService::markReady(
            $connection,
            $release,
            $project,
            $releaseA,
            'release_manager'
        ),
        ValidationException::class,
        'lỗi thời',
        'Release phải bị chặn khi dependency verified nhưng test lỗi thời'
    );
    assertContains('#' . $dependency, $exception->getMessage(), 'Thông báo phải nêu dependency chưa đạt');
    assertSame('planned', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Release phải ở planned sau lần ready thất bại');

    IssueService::recordTest($connection, $project, $testerA, 'tester', $dependency, [
        'result' => 'passed',
        'notes' => 'Retest sau khi đổi nội dung',
    ]);
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');
    assertSame('ready', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Release phải ready sau khi dependency được retest hợp lệ');
});

testCase('TEST C2: dependency verified có test legacy issue_version=0 bị chặn', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'DepLegacy');
    $dependency = testInsertIssue($connection, $project, 'Dependency legacy', 'medium', 'open', $devA, $devA);
    $item = testInsertIssue($connection, $project, 'Release item legacy', 'medium', 'open', $devA, $devA);
    IssueService::recordTest($connection, $project, $testerA, 'tester', $dependency, [
        'result' => 'passed',
        'notes' => 'Pass nhưng dữ liệu legacy',
    ]);
    queryParams(
        $connection,
        'UPDATE test_results SET issue_version=0 WHERE issue_id=$1',
        [(string)$dependency]
    );
    DependencyService::add($connection, $project, $item, $dependency, $devA);
    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => 'C2.1.0',
        'description' => 'Release cho test legacy',
    ]);
    ReleaseService::addItem($connection, $release, $project, $item, $releaseA, 'release_manager');

    $exception = expectException(
        static fn() => ReleaseService::markReady(
            $connection,
            $release,
            $project,
            $releaseA,
            'release_manager'
        ),
        ValidationException::class,
        'lỗi thời',
        'Release phải bị chặn khi test legacy issue_version=0'
    );
    assertContains('#' . $dependency, $exception->getMessage(), 'Thông báo phải nêu dependency legacy');
    assertSame('planned', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Release phải ở planned sau khi chặn test legacy');
});

testCase('TEST C3: dependency closed nhưng test mới nhất failed vẫn bị chặn', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'DepClosedFailed');
    $dependency = testInsertIssue($connection, $project, 'Dependency closed failed', 'medium', 'open', $devA, $devA);
    $item = testInsertIssue($connection, $project, 'Release item closed failed', 'medium', 'open', $devA, $devA);
    IssueService::recordTest($connection, $project, $testerA, 'tester', $dependency, [
        'result' => 'failed',
        'notes' => 'Test thất bại trước khi đóng',
    ]);
    queryParams(
        $connection,
        "UPDATE issues SET status='closed' WHERE id=$1 AND project_id=$2",
        [(string)$dependency, (string)$project]
    );
    DependencyService::add($connection, $project, $item, $dependency, $devA);
    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => 'C3.1.0',
        'description' => 'Release cho closed failed',
    ]);
    ReleaseService::addItem($connection, $release, $project, $item, $releaseA, 'release_manager');

    $exception = expectException(
        static fn() => ReleaseService::markReady(
            $connection,
            $release,
            $project,
            $releaseA,
            'release_manager'
        ),
        ValidationException::class,
        'failed',
        'Release phải bị chặn khi closed nhưng test mới nhất failed'
    );
    assertContains('#' . $dependency, $exception->getMessage(), 'Thông báo phải nêu dependency closed failed');
    assertSame('planned', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Release phải ở planned sau khi chặn closed failed');
});

testCase('TEST C4: ready -> released cũng phải kiểm tra lại dependency', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'ReleaseRecheck');
    $dependency = testInsertIssue($connection, $project, 'Dependency release recheck', 'medium', 'open', $devA, $devA);
    $item = testInsertIssue($connection, $project, 'Release item recheck', 'medium', 'open', $devA, $devA);
    IssueService::recordTest($connection, $project, $testerA, 'tester', $dependency, [
        'result' => 'passed',
        'notes' => 'Pass trước khi ready',
    ]);
    DependencyService::add($connection, $project, $item, $dependency, $devA);
    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => 'C4.1.0',
        'description' => 'Release kiểm tra lại khi chốt',
    ]);
    ReleaseService::addItem($connection, $release, $project, $item, $releaseA, 'release_manager');
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');
    assertSame('ready', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Release phải ready trước khi test đổi kết quả');

    queryParams(
        $connection,
        "UPDATE test_results SET result='failed' WHERE issue_id=$1",
        [(string)$dependency]
    );
    $exception = expectException(
        static fn() => ReleaseService::release(
            $connection,
            $release,
            $project,
            $releaseA,
            'release_manager'
        ),
        ValidationException::class,
        'failed',
        'Chốt release phải kiểm tra lại dependency'
    );
    assertContains('#' . $dependency, $exception->getMessage(), 'Thông báo phải nêu dependency khi chốt');
    assertSame('ready', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Release phải giữ ready khi chốt thất bại');
});

testCase('TEST D: version nền cũ vẫn bị coi là xung đột dù payload no-op', function () use (
    $connection,
    $devA
): void {
    $project = testInsertProject($connection, 'StaleNoop');
    $issue = testInsertIssue($connection, $project, 'Issue gốc', 'medium', 'open', $devA, $devA);
    $original = testIssueSnapshot($connection, $issue);
    $firstFields = [
        'title' => 'Client A đã sửa',
        'description' => (string)$original['description'],
        'steps' => (string)$original['reproduction_steps'],
        'priority' => (string)$original['priority'],
        'status' => 'in_progress',
        'assignee_id' => (string)$original['assignee_id'],
        'commit_url' => (string)$original['commit_url'],
    ];
    $newVersion = IssueService::update(
        $connection,
        $project,
        $devA,
        'developer',
        $issue,
        (int)$original['version'],
        $firstFields
    );
    assertSame((int)$original['version'] + 1, $newVersion, 'Client A phải tạo version mới');

    expectException(
        static fn() => IssueService::update(
            $connection,
            $project,
            $devA,
            'developer',
            $issue,
            (int)$original['version'],
            [
                'title' => (string)$original['title'],
                'description' => (string)$original['description'],
                'steps' => (string)$original['reproduction_steps'],
                'priority' => (string)$original['priority'],
                'status' => (string)$original['status'],
                'assignee_id' => (string)$original['assignee_id'],
                'commit_url' => (string)$original['commit_url'],
            ]
        ),
        ConflictException::class,
        'XUNG ĐỘT',
        'Payload no-op dùng version cũ vẫn phải xung đột'
    );
    $after = testIssueSnapshot($connection, $issue);
    assertSame('Client A đã sửa', (string)$after['title'], 'Không được ghi đè dữ liệu Client A');
    assertSame($newVersion, (int)$after['version'], 'Version không được thay đổi bởi request cũ');
});

testCase('TEST E: không thể đặt verified bằng form cập nhật, kể cả khi có test passed hiện tại', function () use (
    $connection,
    $devA,
    $testerA
): void {
    $project = testInsertProject($connection, 'ManualVerified');
    $issue = testInsertIssue($connection, $project, 'Issue không được verified thủ công', 'medium', 'open', $devA, $devA);
    IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
        'result' => 'passed',
        'notes' => 'Pass hợp lệ',
    ]);
    // Mô phỏng dữ liệu legacy/import: test passed đúng version nhưng status không phải verified.
    queryParams(
        $connection,
        "UPDATE issues SET status='retest' WHERE id=\$1 AND project_id=\$2",
        [(string)$issue, (string)$project]
    );
    $before = testIssueSnapshot($connection, $issue);
    $latestBefore = IssueService::latestTest($connection, $issue);
    $auditBefore = queryOne(
        $connection,
        "SELECT count(*) AS total FROM audit_log
         WHERE action='issue_updated' AND entity_type='issue' AND entity_id=\$1",
        [(string)$issue]
    );
    expectException(
        static fn() => IssueService::update(
            $connection,
            $project,
            $devA,
            'developer',
            $issue,
            (int)$before['version'],
            [
                'title' => (string)$before['title'],
                'description' => (string)$before['description'],
                'steps' => (string)$before['reproduction_steps'],
                'priority' => (string)$before['priority'],
                'status' => 'verified',
                'assignee_id' => (string)$before['assignee_id'],
                'commit_url' => (string)$before['commit_url'],
            ]
        ),
        ValidationException::class,
        'kết quả kiểm thử',
        'Form cập nhật không được đặt verified thay cho biểu mẫu kiểm thử'
    );
    $after = testIssueSnapshot($connection, $issue);
    $latestAfter = IssueService::latestTest($connection, $issue);
    $auditAfter = queryOne(
        $connection,
        "SELECT count(*) AS total FROM audit_log
         WHERE action='issue_updated' AND entity_type='issue' AND entity_id=\$1",
        [(string)$issue]
    );
    assertSame('retest', (string)$after['status'], 'Yêu cầu bị từ chối không được đổi trạng thái');
    assertSame((int)$before['version'], (int)$after['version'], 'Yêu cầu bị từ chối không được tăng version');
    assertSame((int)$latestBefore['id'], (int)$latestAfter['id'], 'Test hiện tại phải được giữ nguyên');
    assertSame(true, (bool)$latestAfter['test_provenance_trusted'], 'Test vẫn phải có provenance hợp lệ');
    assertSame((int)$auditBefore['total'], (int)$auditAfter['total'], 'Không được ghi audit khi từ chối');
});

testCase('TEST F: test passed đúng version nhưng thiếu provenance không được dùng làm bằng chứng', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'UntrustedProvenance');
    $issue = testInsertIssue($connection, $project, 'Blocker thiếu provenance', 'blocker', 'open', $devA, $devA);
    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => 'F.1.0',
        'description' => 'Release kiểm tra provenance',
    ]);
    ReleaseService::addItem($connection, $release, $project, $issue, $releaseA, 'release_manager');
    $version = (int)testIssueSnapshot($connection, $issue)['version'];
    queryParams(
        $connection,
        'INSERT INTO test_results(issue_id,project_id,tester_id,result,notes,issue_version)
         VALUES($1,$2,$3,\'passed\',$4,$5)',
        [(string)$issue, (string)$project, (string)$testerA, 'Chèn trực tiếp, không có audit', (string)$version]
    );
    assertSame(
        false,
        IssueService::hasValidPassedTest($connection, $issue, $version),
        'Test không có audit provenance không được coi là hợp lệ'
    );
    expectException(
        static fn() => ReleaseService::markReady(
            $connection,
            $release,
            $project,
            $releaseA,
            'release_manager'
        ),
        ValidationException::class,
        'nguồn gốc',
        'Release không được chấp nhận test passed thiếu provenance'
    );
    IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
        'result' => 'passed',
        'notes' => 'Retest qua service, có audit provenance',
    ]);
    $newVersion = (int)testIssueSnapshot($connection, $issue)['version'];
    assertSame(
        true,
        IssueService::hasValidPassedTest($connection, $issue, $newVersion),
        'Test ghi qua service phải có provenance hợp lệ'
    );
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');
    assertSame('ready', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Release phải ready sau khi có test có provenance');
});

testCase('TEST G: latest test chọn theo id, không theo tested_at bị lệch', function () use (
    $connection,
    $devA,
    $testerA
): void {
    $project = testInsertProject($connection, 'LatestById');
    $issue = testInsertIssue($connection, $project, 'Issue kiểm tra thứ tự test', 'medium', 'open', $devA, $devA);
    IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
        'result' => 'passed',
        'notes' => 'Test trước',
    ]);
    IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
        'result' => 'failed',
        'notes' => 'Test sau, bị backdate',
    ]);
    $second = queryOne(
        $connection,
        'SELECT id,result,issue_version FROM test_results WHERE issue_id=$1 ORDER BY id DESC LIMIT 1',
        [(string)$issue]
    );
    queryParams(
        $connection,
        "UPDATE test_results SET tested_at=now() - interval '1 day' WHERE id=\$1",
        [(string)$second['id']]
    );
    $latest = IssueService::latestTest($connection, $issue);
    assertSame((int)$second['id'], (int)$latest['id'], 'Latest test phải là id lớn nhất');
    assertSame('failed', (string)$latest['result'], 'Không được chọn test cũ chỉ vì tested_at mới hơn');
    assertSame((int)$second['issue_version'], (int)$latest['issue_version'], 'Latest test phải đúng version ghi nhận');
    assertSame(true, (bool)$latest['is_current'], 'Test mới nhất phải là current theo version issue');
});

testCase('TEST H: provenance dùng vai trò tại thời điểm ghi, không dùng membership hiện tại', function () use (
    $connection,
    $devA,
    $testerA
): void {
    $project = testInsertProject($connection, 'HistoricalRole');
    $issue = testInsertIssue($connection, $project, 'Issue đổi vai trò sau khi test', 'medium', 'open', $devA, $devA);
    IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
        'result' => 'passed',
        'notes' => 'Test khi còn là tester',
    ]);
    $auditRole = queryOne(
        $connection,
        "SELECT details->>'tester_role' AS tester_role
         FROM audit_log
         WHERE action='test_result_recorded' AND entity_type='test_result'
           AND project_id=\$1 AND user_id=\$2
         ORDER BY id DESC LIMIT 1",
        [(string)$project, (string)$testerA]
    );
    assertSame('tester', (string)($auditRole['tester_role'] ?? ''), 'Audit phải lưu vai trò tại thời điểm ghi');
    queryParams(
        $connection,
        "UPDATE project_members SET role='developer' WHERE project_id=\$1 AND user_id=\$2",
        [(string)$project, (string)$testerA]
    );
    $version = (int)testIssueSnapshot($connection, $issue)['version'];
    assertSame(
        true,
        IssueService::hasValidPassedTest($connection, $issue, $version),
        'Đổi vai trò hiện tại không được làm mất hiệu lực test lịch sử đã có provenance'
    );
    $latest = IssueService::latestTest($connection, $issue);
    assertSame('audited_role', (string)$latest['test_provenance'], 'Provenance phải lấy từ audit, không từ membership hiện tại');
});

testCase('TEST I: snapshot chỉ ghi passed khi test có provenance hợp lệ', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'SnapshotProvenance');
    $trustedIssue = testInsertIssue(
        $connection,
        $project,
        'Trusted snapshot pass',
        'medium',
        'open',
        $devA,
        $devA
    );
    IssueService::recordTest($connection, $project, $testerA, 'tester', $trustedIssue, [
        'result' => 'passed',
        'notes' => 'Test ghi qua service, có provenance',
    ]);

    $untrustedIssue = testInsertIssue(
        $connection,
        $project,
        'Untrusted snapshot pass',
        'medium',
        'verified',
        $devA,
        $devA
    );
    $untrustedVersion = (int)testIssueSnapshot($connection, $untrustedIssue)['version'];
    queryParams(
        $connection,
        'INSERT INTO test_results(issue_id,project_id,tester_id,result,notes,issue_version)
         VALUES($1,$2,$3,\'passed\',$4,$5)',
        [
            (string)$untrustedIssue,
            (string)$project,
            (string)$testerA,
            'Chèn trực tiếp, không có audit',
            (string)$untrustedVersion,
        ]
    );
    assertSame(
        false,
        IssueService::hasValidPassedTest($connection, $untrustedIssue, $untrustedVersion),
        'Fixture phải là test passed thiếu provenance'
    );

    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => 'I.1.0',
        'description' => 'Snapshot provenance',
    ]);
    ReleaseService::addItem($connection, $release, $project, $trustedIssue, $releaseA, 'release_manager');
    ReleaseService::addItem($connection, $release, $project, $untrustedIssue, $releaseA, 'release_manager');
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');
    ReleaseService::release($connection, $release, $project, $releaseA, 'release_manager');

    $rows = queryAll(
        $connection,
        'SELECT issue_id,snapshot_test_result,snapshot_test_version
         FROM release_items WHERE release_id=$1 ORDER BY issue_id',
        [(string)$release]
    );
    $byIssue = [];
    foreach ($rows as $row) {
        $byIssue[(int)$row['issue_id']] = $row;
    }
    assertSame('passed', (string)$byIssue[$trustedIssue]['snapshot_test_result'],
        'Test có provenance phải được ghi passed');
    assertSame('unverified', (string)$byIssue[$untrustedIssue]['snapshot_test_result'],
        'Test thiếu provenance không được ghi passed trong snapshot');
    assertSame($untrustedVersion, (int)$byIssue[$untrustedIssue]['snapshot_test_version'],
        'Snapshot vẫn phải giữ version của test để truy vết');

    $notes = ReleaseNotes::markdown($connection, $release);
    assertContains('unverified', $notes, 'Release notes phải nêu rõ test chưa được xác minh');
    $sections = explode('### #', $notes);
    $untrustedSection = '';
    foreach ($sections as $section) {
        if (str_starts_with($section, (string)$untrustedIssue . ' ')) {
            $untrustedSection = $section;
            break;
        }
    }
    assertTrue($untrustedSection !== '', 'Release notes phải có mục của issue thiếu provenance');
    assertContains('unverified', $untrustedSection, 'Mục issue thiếu provenance phải ghi unverified');
});

testCase('TEST J: migrate --status chỉ đọc, không tạo schema_migrations', function () use ($connection): void {
    $probe = 'rt_status_' . bin2hex(random_bytes(4));
    $quoted = pg_escape_identifier($connection, $probe);
    assertTrue(
        @pg_query($connection, 'CREATE SCHEMA ' . $quoted) !== false,
        'Phải tạo được schema probe cho migrate --status'
    );
    try {
        $command = [PHP_BINARY, __DIR__ . '/../tools/migrate.php', '--status'];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            dirname(__DIR__),
            environmentWith(['RT_DB_SCHEMA' => $probe])
        );
        assertTrue(is_resource($process), 'Phải khởi chạy được migrate --status');
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        assertSame(0, $exit, 'migrate --status phải thoát 0: ' . trim($stderr));
        assertContains('PENDING 001_initial_schema.sql', $stdout, 'Phải báo migration đang chờ');
        $table = queryOne(
            $connection,
            'SELECT to_regclass($1) AS name',
            [$probe . '.schema_migrations']
        );
        assertSame(null, $table['name'] ?? null, '--status không được tạo schema_migrations');
    } finally {
        @pg_query($connection, 'DROP SCHEMA IF EXISTS ' . $quoted . ' CASCADE');
    }
});

testCase('TEST K: recordTest tăng version đúng một lần và snapshot dùng version của test', function () use (
    $connection,
    $devA,
    $testerA,
    $releaseA
): void {
    $project = testInsertProject($connection, 'VersionRule');
    $issue = testInsertIssue($connection, $project, 'Version rule', 'medium', 'open', $devA, $devA);

    $first = IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
        'result' => 'passed',
        'notes' => 'Pass lần 1',
    ]);
    $afterFirst = testIssueSnapshot($connection, $issue);
    assertSame(2, $first, 'Ghi passed phải trả version mới');
    assertSame(2, (int)$afterFirst['version'], 'Ghi passed phải tăng version đúng 1');
    assertSame('verified', (string)$afterFirst['status'], 'Passed phải chuyển verified');
    $firstTest = IssueService::latestTest($connection, $issue);
    assertSame(2, (int)$firstTest['issue_version'], 'Test phải lưu version mới của issue');
    assertSame(true, (bool)$firstTest['is_current'], 'Test phải là current');

    $second = IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
        'result' => 'failed',
        'notes' => 'Fail lần 1',
    ]);
    $afterSecond = testIssueSnapshot($connection, $issue);
    assertSame(3, $second, 'Ghi failed cũng phải tăng version');
    assertSame(3, (int)$afterSecond['version'], 'Failed phải tăng version đúng 1');
    assertSame('retest', (string)$afterSecond['status'], 'Failed phải chuyển retest');
    $secondTest = IssueService::latestTest($connection, $issue);
    assertSame(3, (int)$secondTest['issue_version'], 'Test failed phải lưu version 3');
    assertSame(
        false,
        IssueService::hasValidPassedTest($connection, $issue, 3),
        'Failed không phải passed hợp lệ'
    );

    $third = IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
        'result' => 'passed',
        'notes' => 'Pass lần 2',
    ]);
    $afterThird = testIssueSnapshot($connection, $issue);
    assertSame(4, $third, 'Pass lại phải tăng version');
    assertSame(4, (int)$afterThird['version'], 'Pass lại phải tăng version đúng 1');
    assertSame('verified', (string)$afterThird['status'], 'Pass lại phải verified');

    $latest = IssueService::latestTest($connection, $issue);
    assertSame(4, (int)$latest['issue_version'], 'Latest test phải mang version 4');
    assertSame(
        true,
        IssueService::hasValidPassedTest($connection, $issue, 4),
        'Pass ở version 4 phải hợp lệ'
    );
    $audit = queryOne(
        $connection,
        "SELECT details->>'issue_version' AS issue_version
         FROM audit_log
         WHERE action='test_result_recorded' AND entity_type='test_result' AND entity_id=\$1
         ORDER BY id DESC LIMIT 1",
        [(string)$latest['id']]
    );
    assertSame('4', (string)($audit['issue_version'] ?? ''), 'Audit phải ghi version 4');

    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => 'K.1.0',
        'description' => 'Version rule',
    ]);
    ReleaseService::addItem($connection, $release, $project, $issue, $releaseA, 'release_manager');
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');
    ReleaseService::release($connection, $release, $project, $releaseA, 'release_manager');
    $snapshot = queryOne(
        $connection,
        'SELECT snapshot_test_result,snapshot_test_version
         FROM release_items WHERE release_id=$1 AND issue_id=$2',
        [(string)$release, (string)$issue]
    );
    assertSame('passed', (string)$snapshot['snapshot_test_result'], 'Snapshot phải ghi passed');
    assertSame(4, (int)$snapshot['snapshot_test_version'], 'Snapshot phải dùng version của test');
});

testCase('TEST L: PGSSLMODE/PGSSLROOTCERT được truyền và giá trị sai bị từ chối', function (): void {
    $dir = sys_get_temp_dir() . '/rt_cfg_' . bin2hex(random_bytes(4));
    assertTrue(mkdir($dir, 0700, true), 'Phải tạo được thư mục cấu hình tạm');
    $cfg = $dir . '/db.json';
    $writeConfig = static function (array $overrides) use ($cfg): void {
        $config = array_merge([
            'PGHOST' => '127.0.0.1',
            'PGPORT' => '5432',
            'PGDATABASE' => 'dummy',
            'PGUSER' => 'dummy',
            'PGPASSWORD' => 'dummy',
        ], $overrides);
        file_put_contents($cfg, json_encode($config, JSON_THROW_ON_ERROR));
        chmod($cfg, 0600);
    };
    $run = static function () use ($cfg): array {
        $code = 'require ' . var_export(__DIR__ . '/../src/Database.php', true) . ';'
            . 'loadDatabaseConfig();'
            . 'echo "MODE=" . (getenv("PGSSLMODE") === false ? "UNSET" : getenv("PGSSLMODE")) . "\n";'
            . 'echo "ROOT=" . (getenv("PGSSLROOTCERT") === false ? "UNSET" : getenv("PGSSLROOTCERT")) . "\n";';
        $environment = environmentWith([]);
        unset($environment['PGSSLMODE'], $environment['PGSSLROOTCERT']);
        $environment['RT_DB_CONFIG'] = $cfg;
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            $descriptors,
            $pipes,
            dirname(__DIR__),
            $environment
        );
        assertTrue(is_resource($process), 'Phải chạy được tiến trình kiểm tra cấu hình');
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    };
    try {
        $writeConfig(['PGSSLMODE' => 'require', 'PGSSLROOTCERT' => '/tmp/root.crt']);
        $ok = $run();
        assertSame(0, $ok['exit'], 'Cấu hình hợp lệ phải đọc được: ' . trim($ok['stderr']));
        assertContains('MODE=require', $ok['stdout'], 'PGSSLMODE phải được truyền vào môi trường libpq');
        assertContains('ROOT=/tmp/root.crt', $ok['stdout'], 'PGSSLROOTCERT phải được truyền');

        $writeConfig([]);
        $unset = $run();
        assertSame(0, $unset['exit'], 'Thiếu SSL mode vẫn phải đọc được cấu hình');
        assertContains('MODE=UNSET', $unset['stdout'], 'Không được tự đặt PGSSLMODE khi cấu hình không có');

        $writeConfig(['PGSSLMODE' => 'bogus']);
        $bad = $run();
        assertTrue($bad['exit'] !== 0, 'PGSSLMODE sai phải bị từ chối');
        assertContains(
            'DATABASE_CONFIG_INVALID',
            $bad['stdout'] . $bad['stderr'],
            'Phải báo lỗi cấu hình'
        );
    } finally {
        @unlink($cfg);
        @rmdir($dir);
    }
});

testCase('TEST P1-2 A: role bị hạ trước khi lấy được khóa phải bị từ chối', function () use (
    $connection,
    $devA,
    $releaseA,
    $adminA
): void {
    $project = testInsertProject($connection, 'RoleDowngradeRace');
    $issue = testInsertIssue($connection, $project, 'Issue cho release race', 'medium', 'open', $devA, $devA);
    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => 'P12-A.1.0',
        'description' => 'Release để kiểm tra role bị hạ',
    ]);
    ReleaseService::addItem($connection, $release, $project, $issue, $releaseA, 'release_manager');
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');

    execSql($connection, 'DROP TRIGGER IF EXISTS rt_test_slow_member_update ON project_members');
    execSql($connection, 'DROP FUNCTION IF EXISTS rt_test_slow_member_update()');
    execSql($connection, 'CREATE FUNCTION rt_test_slow_member_update() RETURNS trigger
        LANGUAGE plpgsql AS $$
        BEGIN
            PERFORM pg_sleep(2.0);
            RETURN NEW;
        END;
        $$');
    execSql($connection, 'CREATE TRIGGER rt_test_slow_member_update
        BEFORE UPDATE ON project_members
        FOR EACH ROW EXECUTE FUNCTION rt_test_slow_member_update()');
    try {
        $demote = startWorker([
            'mode' => 'member_set',
            'input' => [
                'project_id' => $project,
                'user_id' => $adminA,
                'username' => 'release_a',
                'member_role' => 'tester',
            ],
        ]);
        usleep(700000);
        $releaseAttempt = startWorker([
            'mode' => 'release',
            'input' => [
                'release_id' => $release,
                'project_id' => $project,
                'user_id' => $releaseA,
                'role' => 'release_manager',
            ],
        ]);
        $releaseResult = finishWorker($releaseAttempt);
        $demoteResult = finishWorker($demote);

        assertSame(0, $demoteResult['exit'], 'Worker hạ role phải thoát bình thường: ' . $demoteResult['stderr']);
        assertSame(true, $demoteResult['json']['ok'] ?? false, 'Admin phải hạ được role release manager');
        assertSame(0, $releaseResult['exit'], 'Worker release phải thoát bình thường: ' . $releaseResult['stderr']);
        assertSame(false, $releaseResult['json']['ok'] ?? true, 'Release cũ không được dùng quyền đã bị hạ');
        assertContains('release manager', (string)($releaseResult['json']['message'] ?? ''), 'Phải báo lỗi quyền');
        assertSame('ready', (string)queryOne(
            $connection,
            'SELECT status FROM releases WHERE id=$1',
            [(string)$release]
        )['status'], 'Release không được chốt sau khi role bị hạ');
        assertSame('tester', (string)queryOne(
            $connection,
            'SELECT role FROM project_members WHERE project_id=$1 AND user_id=$2',
            [(string)$project, (string)$releaseA]
        )['role'], 'Role cuối phải là tester');
    } finally {
        execSql($connection, 'DROP TRIGGER IF EXISTS rt_test_slow_member_update ON project_members');
        execSql($connection, 'DROP FUNCTION IF EXISTS rt_test_slow_member_update()');
    }
});

testCase('TEST P1-2 B1: membership bị thu hồi trong lúc request đang chờ khóa', function () use (
    $connection,
    $devA,
    $releaseA,
    $adminA
): void {
    $project = testInsertProject($connection, 'MembershipRevokeRace');
    $issue = testInsertIssue($connection, $project, 'Issue cho revoke race', 'medium', 'open', $devA, $devA);
    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => 'P12-B1.1.0',
        'description' => 'Release để kiểm tra thu hồi membership',
    ]);
    ReleaseService::addItem($connection, $release, $project, $issue, $releaseA, 'release_manager');
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');

    execSql($connection, 'DROP TRIGGER IF EXISTS rt_test_slow_member_delete ON project_members');
    execSql($connection, 'DROP FUNCTION IF EXISTS rt_test_slow_member_delete()');
    execSql($connection, 'CREATE FUNCTION rt_test_slow_member_delete() RETURNS trigger
        LANGUAGE plpgsql AS $$
        BEGIN
            PERFORM pg_sleep(2.0);
            RETURN OLD;
        END;
        $$');
    execSql($connection, 'CREATE TRIGGER rt_test_slow_member_delete
        BEFORE DELETE ON project_members
        FOR EACH ROW EXECUTE FUNCTION rt_test_slow_member_delete()');
    try {
        $revoke = startWorker([
            'mode' => 'member_remove',
            'input' => [
                'project_id' => $project,
                'user_id' => $adminA,
                'member_id' => $releaseA,
            ],
        ]);
        usleep(700000);
        $releaseAttempt = startWorker([
            'mode' => 'release',
            'input' => [
                'release_id' => $release,
                'project_id' => $project,
                'user_id' => $releaseA,
                'role' => 'release_manager',
            ],
        ]);
        $releaseResult = finishWorker($releaseAttempt);
        $revokeResult = finishWorker($revoke);

        assertSame(0, $revokeResult['exit'], 'Worker thu hồi phải thoát bình thường: ' . $revokeResult['stderr']);
        assertSame(true, $revokeResult['json']['ok'] ?? false, 'Admin phải thu hồi được membership');
        assertSame(0, $releaseResult['exit'], 'Worker release phải thoát bình thường: ' . $releaseResult['stderr']);
        assertSame(false, $releaseResult['json']['ok'] ?? true, 'Release không được dùng membership đã bị thu hồi');
        assertContains('thành viên', (string)($releaseResult['json']['message'] ?? ''), 'Phải báo mất membership');
        assertSame('ready', (string)queryOne(
            $connection,
            'SELECT status FROM releases WHERE id=$1',
            [(string)$release]
        )['status'], 'Release không được chốt sau khi membership bị thu hồi');
        assertSame(0, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM project_members WHERE project_id=$1 AND user_id=$2',
            [(string)$project, (string)$releaseA]
        )['total'], 'Membership phải bị thu hồi');
    } finally {
        execSql($connection, 'DROP TRIGGER IF EXISTS rt_test_slow_member_delete ON project_members');
        execSql($connection, 'DROP FUNCTION IF EXISTS rt_test_slow_member_delete()');
    }
});

testCase('TEST P1-2 B: member bị gỡ thì service vẫn từ chối dù role truyền vào còn cũ', function () use (
    $connection,
    $devA,
    $releaseA,
    $adminA
): void {
    $project = testInsertProject($connection, 'MembershipRemoved');
    $issue = testInsertIssue($connection, $project, 'Issue trước khi gỡ member', 'medium', 'open', $devA, $devA);
    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => 'P12-B.1.0',
        'description' => 'Release để kiểm tra member bị gỡ',
    ]);
    ReleaseService::addItem($connection, $release, $project, $issue, $releaseA, 'release_manager');
    ReleaseService::markReady($connection, $release, $project, $releaseA, 'release_manager');
    ProjectService::removeMember($connection, $project, $adminA, $releaseA);

    expectException(
        static fn() => ReleaseService::release(
            $connection,
            $release,
            $project,
            $releaseA,
            'release_manager'
        ),
        ForbiddenException::class,
        'thành viên',
        'Release phải đọc lại membership sau khi bị gỡ'
    );
    expectException(
        static fn() => IssueService::create($connection, $project, $releaseA, 'release_manager', [
            'title' => 'Không được tạo sau khi bị gỡ',
            'description' => '',
            'steps' => '',
            'issue_type' => 'bug',
            'priority' => 'medium',
            'assignee_id' => '',
        ]),
        ForbiddenException::class,
        'thành viên',
        'Issue service phải tự kiểm tra lại membership'
    );
    assertSame('ready', (string)queryOne(
        $connection,
        'SELECT status FROM releases WHERE id=$1',
        [(string)$release]
    )['status'], 'Release phải giữ ready khi member bị gỡ');
    assertSame(0, (int)queryOne(
        $connection,
        'SELECT count(*) AS total FROM issues WHERE project_id=$1 AND title=$2',
        [(string)$project, 'Không được tạo sau khi bị gỡ']
    )['total'], 'Không được tạo issue khi service tự từ chối');
});

testCase('TEST P1-2 C: role do caller truyền vào không được dùng để cấp quyền', function () use (
    $connection,
    $testerA
): void {
    $project = testInsertProject($connection, 'SpoofedRole');
    expectException(
        static fn() => ReleaseService::create($connection, $project, $testerA, 'release_manager', [
            'version_name' => 'P12-C.1.0',
            'description' => 'Role giả',
        ]),
        ForbiddenException::class,
        'release manager',
        'Tester truyền role release_manager vẫn phải bị từ chối'
    );
    assertSame(0, (int)queryOne(
        $connection,
        'SELECT count(*) AS total FROM releases WHERE project_id=$1',
        [(string)$project]
    )['total'], 'Không được tạo release bằng role giả');
});

testCase('TEST P1-2 E: không thêm issue dự án khác vào release qua service', function () use (
    $connection,
    $mobile,
    $releaseA
): void {
    $project = testInsertProject($connection, 'CrossProjectRelease');
    $foreignIssue = queryOne(
        $connection,
        'SELECT id FROM issues WHERE project_id=$1 ORDER BY id LIMIT 1',
        [(string)$mobile]
    );
    assertTrue($foreignIssue !== null, 'Cần issue ở dự án khác');
    $release = ReleaseService::create($connection, $project, $releaseA, 'release_manager', [
        'version_name' => 'P12-E.1.0',
        'description' => 'Release kiểm tra chéo dự án',
    ]);
    expectException(
        static fn() => ReleaseService::addItem(
            $connection,
            $release,
            $project,
            (int)$foreignIssue['id'],
            $releaseA,
            'release_manager'
        ),
        NotFoundException::class,
        'không thuộc dự án',
        'Không được thêm issue dự án khác vào release'
    );
    assertSame(0, (int)queryOne(
        $connection,
        'SELECT count(*) AS total FROM release_items WHERE release_id=$1',
        [(string)$release]
    )['total'], 'Release không được có issue chéo dự án');
});

testCase('TEST P1-3 A: issue dự án B không được thêm vào release dự án A (service)', function () use (
    $connection,
    $core,
    $mobile,
    $releaseA
): void {
    $release = queryOne(
        $connection,
        'SELECT id FROM releases WHERE project_id=$1 ORDER BY id LIMIT 1',
        [(string)$core]
    );
    $foreignIssue = queryOne(
        $connection,
        'SELECT id FROM issues WHERE project_id=$1 ORDER BY id LIMIT 1',
        [(string)$mobile]
    );
    assertTrue($release !== null && $foreignIssue !== null, 'Cần release Core và issue Mobile');
    $releaseId = (int)$release['id'];
    $beforeItems = (int)queryOne(
        $connection,
        'SELECT count(*) AS total FROM release_items WHERE release_id=$1',
        [(string)$releaseId]
    )['total'];
    $beforeAudit = (int)queryOne(
        $connection,
        "SELECT count(*) AS total FROM audit_log
         WHERE action='release_item_added' AND entity_type='release' AND entity_id=\$1",
        [(string)$releaseId]
    )['total'];

    expectException(
        static fn() => ReleaseService::addItem(
            $connection,
            $releaseId,
            $core,
            (int)$foreignIssue['id'],
            $releaseA,
            'release_manager'
        ),
        NotFoundException::class,
        'không thuộc dự án',
        'Service phải từ chối issue dự án B khi thêm vào release dự án A'
    );

    assertSame($beforeItems, (int)queryOne(
        $connection,
        'SELECT count(*) AS total FROM release_items WHERE release_id=$1',
        [(string)$releaseId]
    )['total'], 'Không được tạo release item chéo dự án');
    assertSame($beforeAudit, (int)queryOne(
        $connection,
        "SELECT count(*) AS total FROM audit_log
         WHERE action='release_item_added' AND entity_type='release' AND entity_id=\$1",
        [(string)$releaseId]
    )['total'], 'Request bị từ chối không được ghi audit thành công');
});

testCase('TEST P1-3 B1: admin cuối cùng không thể bị gỡ hoặc hạ quyền', function () use (
    $connection,
    $adminA
): void {
    $project = testInsertProject($connection, 'LastAdmin');
    $adminCount = queryOne(
        $connection,
        "SELECT count(*) AS total FROM project_members WHERE project_id=\$1 AND role='admin'",
        [(string)$project]
    );
    assertSame(1, (int)$adminCount['total'], 'Dự án kiểm thử phải có đúng một admin ban đầu');

    expectException(
        static fn() => ProjectService::removeMember($connection, $project, $adminA, $adminA),
        ConflictException::class,
        'ít nhất một admin',
        'Không được gỡ admin cuối cùng'
    );
    expectException(
        static fn() => ProjectService::setMemberRole($connection, $project, $adminA, 'admin_a', 'developer'),
        ConflictException::class,
        'ít nhất một admin',
        'Không được hạ admin cuối cùng xuống developer'
    );

    $after = queryOne(
        $connection,
        'SELECT role FROM project_members WHERE project_id=$1 AND user_id=$2',
        [(string)$project, (string)$adminA]
    );
    assertSame('admin', (string)($after['role'] ?? ''), 'Admin cuối cùng phải giữ nguyên vai trò');
    assertSame(1, (int)queryOne(
        $connection,
        "SELECT count(*) AS total FROM project_members WHERE project_id=\$1 AND role='admin'",
        [(string)$project]
    )['total'], 'Dự án vẫn phải còn đúng một admin');
});

testCase('TEST P1-3 B2: quản lý admin hợp lệ khi còn admin khác', function () use (
    $connection,
    $adminA,
    $devA
): void {
    $project = testInsertProject($connection, 'TwoAdmins');
    ProjectService::setMemberRole($connection, $project, $adminA, 'dev_a', 'admin');
    assertSame('admin', (string)queryOne(
        $connection,
        'SELECT role FROM project_members WHERE project_id=$1 AND user_id=$2',
        [(string)$project, (string)$devA]
    )['role'], 'Phải thêm được admin thứ hai');

    ProjectService::setMemberRole($connection, $project, $adminA, 'dev_a', 'developer');
    assertSame('developer', (string)queryOne(
        $connection,
        'SELECT role FROM project_members WHERE project_id=$1 AND user_id=$2',
        [(string)$project, (string)$devA]
    )['role'], 'Được hạ admin khi vẫn còn admin khác');
    assertSame(1, (int)queryOne(
        $connection,
        "SELECT count(*) AS total FROM project_members WHERE project_id=\$1 AND role='admin'",
        [(string)$project]
    )['total'], 'Sau khi hạ phải còn đúng một admin');

    ProjectService::setMemberRole($connection, $project, $adminA, 'dev_a', 'admin');
    ProjectService::removeMember($connection, $project, $adminA, $devA);
    assertSame(0, (int)queryOne(
        $connection,
        'SELECT count(*) AS total FROM project_members WHERE project_id=$1 AND user_id=$2',
        [(string)$project, (string)$devA]
    )['total'], 'Được gỡ admin khi vẫn còn admin khác');
    assertSame(1, (int)queryOne(
        $connection,
        "SELECT count(*) AS total FROM project_members WHERE project_id=\$1 AND role='admin'",
        [(string)$project]
    )['total'], 'Không được để dự án mất admin');
});

testCase('TEST P1-3 B3: hai process không thể đồng thời gỡ hết admin', function () use (
    $connection,
    $adminA,
    $devA
): void {
    $project = testInsertProject($connection, 'AdminRace');
    ProjectService::setMemberRole($connection, $project, $adminA, 'dev_a', 'admin');

    execSql($connection, 'DROP TRIGGER IF EXISTS rt_test_slow_admin_delete ON project_members');
    execSql($connection, 'DROP FUNCTION IF EXISTS rt_test_slow_admin_delete()');
    execSql($connection, 'CREATE FUNCTION rt_test_slow_admin_delete() RETURNS trigger
        LANGUAGE plpgsql AS $$
        BEGIN
            PERFORM pg_sleep(2.0);
            RETURN OLD;
        END;
        $$');
    execSql($connection, 'CREATE TRIGGER rt_test_slow_admin_delete
        BEFORE DELETE ON project_members
        FOR EACH ROW EXECUTE FUNCTION rt_test_slow_admin_delete()');
    try {
        $removeOne = startWorker([
            'mode' => 'member_remove',
            'input' => [
                'project_id' => $project,
                'user_id' => $adminA,
                'member_id' => $devA,
            ],
        ]);
        usleep(300000);
        $removeTwo = startWorker([
            'mode' => 'member_remove',
            'input' => [
                'project_id' => $project,
                'user_id' => $devA,
                'member_id' => $adminA,
            ],
        ]);
        $resultOne = finishWorker($removeOne);
        $resultTwo = finishWorker($removeTwo);

        foreach ([$resultOne, $resultTwo] as $result) {
            assertSame(0, $result['exit'], 'Worker gỡ admin phải thoát bình thường: ' . $result['stderr']);
            assertTrue($result['json'] !== null, 'Worker gỡ admin phải trả JSON: ' . $result['stdout']);
        }
        $successes = (int)($resultOne['json']['ok'] ?? false) + (int)($resultTwo['json']['ok'] ?? false);
        assertSame(1, $successes, 'Chỉ một process được gỡ admin trong tình huống cạnh tranh');
        $failed = ($resultOne['json']['ok'] ?? false) === true ? $resultTwo : $resultOne;
        $failedMessage = (string)($failed['json']['message'] ?? '');
        assertTrue(
            str_contains($failedMessage, 'thành viên')
                || str_contains($failedMessage, 'ít nhất một admin'),
            'Process thất bại phải nêu mất membership hoặc chặn admin cuối: ' . $failedMessage
        );

        assertSame(1, (int)queryOne(
            $connection,
            "SELECT count(*) AS total FROM project_members WHERE project_id=\$1 AND role='admin'",
            [(string)$project]
        )['total'], 'Dự án phải còn đúng một admin sau race');
        assertSame(1, (int)queryOne(
            $connection,
            "SELECT count(*) AS total FROM audit_log
             WHERE project_id=\$1 AND action='member_removed'",
            [(string)$project]
        )['total'], 'Chỉ một thao tác gỡ admin được ghi audit thành công');
    } finally {
        execSql($connection, 'DROP TRIGGER IF EXISTS rt_test_slow_admin_delete ON project_members');
        execSql($connection, 'DROP FUNCTION IF EXISTS rt_test_slow_admin_delete()');
    }
});

testCase('LEGACY REPORT: phân loại version 0, version hiện tại, failed và verified thiếu test', function () use (
    $connection,
    $testerA,
    $devA
): void {
    $project = testInsertProject($connection, 'LegacyReport');
    $insertTest = static function (int $issueId, int $version, string $result) use (
        $connection,
        $project,
        $testerA
    ): int {
        $inserted = queryParams(
            $connection,
            'INSERT INTO test_results(issue_id,project_id,tester_id,result,notes,issue_version)
             VALUES($1,$2,$3,$4,$5,$6) RETURNING id',
            [
                (string)$issueId,
                (string)$project,
                (string)$testerA,
                $result,
                'Fixture legacy report',
                (string)$version,
            ]
        );
        return (int)pg_fetch_result($inserted, 0, 0);
    };
    $setVersion = static function (int $issueId, int $version) use ($connection): void {
        queryParams(
            $connection,
            'UPDATE issues SET version=$2 WHERE id=$1',
            [(string)$issueId, (string)$version]
        );
    };

    $legacyIssue = testInsertIssue(
        $connection,
        $project,
        'Legacy unknown version',
        'low',
        'verified',
        $devA,
        $devA
    );
    $setVersion($legacyIssue, 2);
    $legacyTest = $insertTest($legacyIssue, 0, 'passed');

    $validIssue = testInsertIssue(
        $connection,
        $project,
        'Current valid pass',
        'medium',
        'open',
        $devA,
        $devA
    );
    IssueService::recordTest($connection, $project, $testerA, 'tester', $validIssue, [
        'result' => 'passed',
        'notes' => 'Fixture current pass',
    ]);
    $validTest = (int)queryOne(
        $connection,
        'SELECT id FROM test_results WHERE issue_id=$1 ORDER BY id DESC LIMIT 1',
        [(string)$validIssue]
    )['id'];

    $failedIssue = testInsertIssue(
        $connection,
        $project,
        'Latest failed',
        'medium',
        'retest',
        $devA,
        $devA
    );
    $failedTest = $insertTest($failedIssue, 1, 'failed');

    $blockedIssue = testInsertIssue(
        $connection,
        $project,
        'Latest blocked',
        'medium',
        'retest',
        $devA,
        $devA
    );
    $blockedTest = $insertTest($blockedIssue, 1, 'blocked');

    $noTestBlocker = testInsertIssue(
        $connection,
        $project,
        'Verified blocker without test',
        'blocker',
        'verified',
        $devA,
        $devA
    );

    $staleIssue = testInsertIssue(
        $connection,
        $project,
        'Stale version verified',
        'medium',
        'verified',
        $devA,
        $devA
    );
    $setVersion($staleIssue, 3);
    $staleTest = $insertTest($staleIssue, 2, 'passed');

    $counts = static fn(): array => queryOne(
        $connection,
        'SELECT
            (SELECT count(*) FROM issues WHERE project_id=$1) AS issues,
            (SELECT count(*) FROM test_results WHERE project_id=$1) AS tests,
            (SELECT count(*) FROM audit_log WHERE project_id=$1) AS audit',
        [(string)$project]
    );
    $before = $counts();

    $result = runLegacyReport([
        '--json',
        '--project=' . $project,
        '--schema=' . TestDatabase::schema(),
    ]);
    assertSame(0, $result['exit'], 'Legacy report phải chạy thành công: ' . $result['stderr']);
    $report = $result['json'];
    assertTrue(is_array($report), 'Legacy report phải trả JSON hợp lệ: ' . $result['stdout']);
    assertSame(true, $report['read_only'], 'Report phải khai báo read_only=true');
    assertSame(5, (int)$report['summary']['tests'], 'Report phải thấy 5 test của project lọc');
    assertSame(1, (int)$report['summary']['legacy_tests'], 'Report phải thấy 1 legacy test');
    assertSame(1, (int)$report['summary']['trusted_tests'], 'Chỉ test ghi qua service mới có provenance đáng tin');
    assertSame(4, (int)$report['summary']['untrusted_tests'], 'Test chèn trực tiếp phải là không có provenance');
    assertSame(1, (int)$report['summary']['valid_current_pass'], 'Report phải thấy 1 test hợp lệ hiện tại');
    assertSame(0, (int)$report['summary']['untrusted_current_pass'], 'Fixture không có passed hiện tại thiếu provenance');
    assertSame(1, (int)$report['summary']['latest_failed'], 'Report phải thấy 1 latest failed');
    assertSame(1, (int)$report['summary']['latest_blocked'], 'Report phải thấy 1 latest blocked');
    assertSame(3, (int)$report['summary']['verified_without_valid_test'], 'Phải cảnh báo 3 issue verified thiếu test hợp lệ');
    assertSame(1, (int)$report['summary']['blocker_without_valid_test'], 'Phải cảnh báo 1 blocker thiếu test hợp lệ');

    $testsByIssue = [];
    foreach ($report['tests'] as $test) {
        $testsByIssue[(int)$test['issue_id']][] = $test;
    }
    assertSame('legacy_unknown_pass', $testsByIssue[$legacyIssue][0]['validity'], 'Version 0 phải là legacy_unknown');
    assertSame('legacy_unknown', $testsByIssue[$legacyIssue][0]['version_state'], 'Version 0 không được suy đoán là current');
    assertSame('tester', $testsByIssue[$legacyIssue][0]['tester_role_current'], 'Report phải lấy được vai trò hiện tại');
    assertSame(null, $testsByIssue[$legacyIssue][0]['recorded_role'], 'Test legacy không có vai trò tại thời điểm ghi');
    assertSame('unverified', $testsByIssue[$legacyIssue][0]['provenance'], 'Test legacy phải là unverified');
    assertSame('valid_current_pass', $testsByIssue[$validIssue][0]['validity'], 'Test passed đúng version phải hợp lệ');
    assertSame('audited_role', $testsByIssue[$validIssue][0]['provenance'], 'Test ghi qua service phải có provenance');
    assertSame('tester', $testsByIssue[$validIssue][0]['recorded_role'], 'Audit phải lưu vai trò tại thời điểm ghi');
    assertSame('current_non_pass', $testsByIssue[$failedIssue][0]['validity'], 'Test failed đúng version là non-pass');
    assertSame('current_non_pass', $testsByIssue[$blockedIssue][0]['validity'], 'Test blocked đúng version là non-pass');
    assertSame('stale_version', $testsByIssue[$staleIssue][0]['validity'], 'Test version cũ phải là stale');

    $issuesById = [];
    foreach ($report['issues'] as $issue) {
        $issuesById[(int)$issue['issue_id']] = $issue;
    }
    assertSame('legacy_unknown_version', $issuesById[$legacyIssue]['evidence_state'], 'Issue chỉ có legacy test phải là legacy_unknown_version');
    assertSame('valid_current_pass', $issuesById[$validIssue]['evidence_state'], 'Issue có pass đúng version phải hợp lệ');
    assertSame('latest_failed', $issuesById[$failedIssue]['evidence_state'], 'Issue có latest failed phải báo latest_failed');
    assertSame('latest_blocked', $issuesById[$blockedIssue]['evidence_state'], 'Issue có latest blocked phải báo latest_blocked');
    assertSame('no_test', $issuesById[$noTestBlocker]['evidence_state'], 'Issue verified không test phải báo no_test');
    assertSame('stale_version', $issuesById[$staleIssue]['evidence_state'], 'Issue có test version cũ phải báo stale_version');
    assertTrue(
        in_array('VERIFIED_WITHOUT_VALID_TEST', $issuesById[$noTestBlocker]['warnings'], true),
        'Verified thiếu test phải có cảnh báo VERIFIED_WITHOUT_VALID_TEST'
    );
    assertTrue(
        in_array('BLOCKER_WITHOUT_VALID_TEST', $issuesById[$noTestBlocker]['warnings'], true),
        'Blocker thiếu test phải có cảnh báo BLOCKER_WITHOUT_VALID_TEST'
    );
    assertTrue(
        in_array('LATEST_TEST_FAILED', $issuesById[$failedIssue]['warnings'], true),
        'Latest failed phải có cảnh báo LATEST_TEST_FAILED'
    );
    assertTrue(
        in_array('LATEST_TEST_BLOCKED', $issuesById[$blockedIssue]['warnings'], true),
        'Latest blocked phải có cảnh báo LATEST_TEST_BLOCKED'
    );
    assertTrue(
        in_array('STALE_TEST_VERSION', $issuesById[$staleIssue]['warnings'], true),
        'Test version cũ phải có cảnh báo STALE_TEST_VERSION'
    );
    assertSame([], $issuesById[$validIssue]['warnings'], 'Issue có bằng chứng hợp lệ không được cảnh báo');

    assertSame($before, $counts(), 'Legacy report chỉ đọc, không được thay đổi issue/test/audit');
});

testFinish();
