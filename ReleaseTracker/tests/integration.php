<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$connection = TestDatabase::boot();
$core = testProjectId($connection, 'ReleaseTracker Core');
$mobile = testProjectId($connection, 'Mobile Client');
$devA = testUserId($connection, 'dev_a');
$testerA = testUserId($connection, 'tester_a');
$adminA = testUserId($connection, 'admin_a');
$coreIssue = queryOne(
    $connection,
    'SELECT id,title,version FROM issues WHERE project_id=$1 ORDER BY id LIMIT 1',
    [(string)$core]
);
$mobileIssue = queryOne(
    $connection,
    'SELECT id,title,version FROM issues WHERE project_id=$1 ORDER BY id LIMIT 1',
    [(string)$mobile]
);
$release = queryOne(
    $connection,
    'SELECT id FROM releases WHERE project_id=$1 ORDER BY id LIMIT 1',
    [(string)$core]
);
if ($coreIssue === null || $mobileIssue === null || $release === null) {
    fwrite(STDERR, "NOT_RUN: thiếu dữ liệu seed cho smoke test.\n");
    exit(2);
}

$port = 0;
for ($attempt = 0; $attempt < 10; $attempt++) {
    $candidate = random_int(18000, 19000);
    $probe = @fsockopen('127.0.0.1', $candidate, $errorNumber, $errorMessage, 1);
    if ($probe === false) {
        $port = $candidate;
        break;
    }
    fclose($probe);
}
if ($port === 0) {
    fwrite(STDERR, "NOT_RUN: không tìm được cổng trống cho HTTP server.\n");
    exit(2);
}

$log = sys_get_temp_dir() . '/rt_server_' . bin2hex(random_bytes(4)) . '.log';
$command = [
    PHP_BINARY,
    '-d',
    'session.name=RTTEST' . $port,
    '-d',
    'session.save_path=' . sys_get_temp_dir(),
    '-d',
    'display_errors=0',
    '-S',
    '127.0.0.1:' . $port,
    '-t',
    'public',
];
$environment = environmentWith(['RT_DB_SCHEMA' => TestDatabase::schema()]);
$descriptors = [
    1 => ['file', $log, 'a'],
    2 => ['file', $log, 'a'],
];
$server = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $environment);
if (!is_resource($server)) {
    fwrite(STDERR, "NOT_RUN: không khởi chạy được PHP built-in server.\n");
    exit(2);
}
$started = false;
for ($attempt = 0; $attempt < 50; $attempt++) {
    $probe = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 1);
    if ($probe !== false) {
        fclose($probe);
        $started = true;
        break;
    }
    usleep(100000);
}
if (!$started) {
    proc_terminate($server);
    proc_close($server);
    fwrite(STDERR, "NOT_RUN: HTTP server không sẵn sàng.\n");
    exit(2);
}

try {
    testCase('HTTP: trang chưa đăng nhập chuyển về login', function () use ($port): void {
        $cookies = [];
        $response = integrationHttp($port, 'GET', '/', [], $cookies);
        assertSame(302, $response['status'], 'Trang chủ phải chuyển hướng');
        assertContains('/login.php', implode(' ', $response['headers']['location'] ?? []), 'Location login');
    });

    testCase('HTTP: đăng nhập đúng và truy cập dashboard', function () use ($port): void {
        $cookies = [];
        $loginPage = integrationHttp($port, 'GET', '/login.php', [], $cookies);
        assertSame(200, $loginPage['status'], 'Trang login phải trả 200');
        $token = csrfFromHtml($loginPage['body']);
        $login = integrationHttp($port, 'POST', '/login.php', [
            'csrf' => $token,
            'username' => 'dev_a',
            'password' => 'demo1234',
        ], $cookies);
        assertSame(303, $login['status'], 'Đăng nhập đúng phải redirect');
        assertContains('/', implode(' ', $login['headers']['location'] ?? []), 'Redirect về trang chủ');
        assertTrue($cookies !== [], 'Phải nhận session cookie');
        $dashboard = integrationHttp($port, 'GET', '/', [], $cookies);
        assertSame(200, $dashboard['status'], 'Dashboard phải trả 200');
        assertContains('Nguyễn An', $dashboard['body'], 'Dashboard phải hiển thị người dùng');
        assertContains('Tổng quan', $dashboard['body'], 'Dashboard phải có tiêu đề');
    });

    testCase('HTTP: đăng nhập sai bị từ chối', function () use ($port): void {
        $cookies = [];
        $loginPage = integrationHttp($port, 'GET', '/login.php', [], $cookies);
        $token = csrfFromHtml($loginPage['body']);
        $login = integrationHttp($port, 'POST', '/login.php', [
            'csrf' => $token,
            'username' => 'dev_a',
            'password' => 'sai-mat-khau',
        ], $cookies);
        assertSame(200, $login['status'], 'Đăng nhập sai không được redirect');
        assertContains('không đúng', $login['body'], 'Phải báo sai tài khoản/mật khẩu');
        $dashboard = integrationHttp($port, 'GET', '/', [], $cookies);
        assertSame(302, $dashboard['status'], 'Session sai không được vào dashboard');
    });

    testCase('HTTP: CSRF thiếu token bị 419', function () use ($port): void {
        $cookies = [];
        integrationHttp($port, 'GET', '/login.php', [], $cookies);
        $login = integrationHttp($port, 'POST', '/login.php', [
            'username' => 'dev_a',
            'password' => 'demo1234',
        ], $cookies);
        assertSame(419, $login['status'], 'Thiếu CSRF phải trả 419');
        assertContains('CSRF', $login['body'], 'Thông báo CSRF');
    });

    testCase('HTTP: phân quyền chéo dự án không đổi dữ liệu', function () use (
        $port,
        $connection,
        $core,
        $mobile,
        $coreIssue,
        $mobileIssue
    ): void {
        $cookies = [];
        $loginPage = integrationHttp($port, 'GET', '/login.php', [], $cookies);
        $token = csrfFromHtml($loginPage['body']);
        integrationHttp($port, 'POST', '/login.php', [
            'csrf' => $token,
            'username' => 'dev_a',
            'password' => 'demo1234',
        ], $cookies);
        $page = integrationHttp(
            $port,
            'GET',
            '/issues.php?project_id=' . $core . '&id=' . (int)$coreIssue['id'],
            [],
            $cookies
        );
        assertSame(200, $page['status'], 'Trang issue Core phải trả 200');
        $pageToken = csrfFromHtml($page['body']);
        $before = testIssueSnapshot($connection, (int)$mobileIssue['id']);
        $post = integrationHttp($port, 'POST', '/issues.php', [
            'csrf' => $pageToken,
            'action' => 'update',
            'project_id' => (string)$mobile,
            'id' => (string)$mobileIssue['id'],
            'version' => (string)$mobileIssue['version'],
            'title' => 'Sửa chéo dự án qua HTTP',
            'description' => 'x',
            'steps' => 'x',
            'priority' => 'low',
            'status' => 'open',
            'assignee_id' => '',
            'commit_url' => '',
        ], $cookies);
        assertSame(403, $post['status'], 'Phải trả 403 khi gửi project B');
        $after = testIssueSnapshot($connection, (int)$mobileIssue['id']);
        assertSame((string)$before['title'], (string)$after['title'], 'Dữ liệu Mobile không được đổi');
        assertSame((int)$before['version'], (int)$after['version'], 'Version Mobile không được đổi');
        $audit = integrationHttp($port, 'GET', '/audit.php?project_id=' . $mobile, [], $cookies);
        assertSame(403, $audit['status'], 'Dev dự án A không được xem audit dự án B');
    });

    testCase('HTTP: admin tạo dự án, xem audit và các trang quản lý', function () use (
        $port,
        $connection,
        $core,
        $release
    ): void {
        $cookies = [];
        $loginPage = integrationHttp($port, 'GET', '/login.php', [], $cookies);
        $token = csrfFromHtml($loginPage['body']);
        integrationHttp($port, 'POST', '/login.php', [
            'csrf' => $token,
            'username' => 'admin_a',
            'password' => 'demo1234',
        ], $cookies);
        $projectsPage = integrationHttp($port, 'GET', '/projects.php', [], $cookies);
        assertSame(200, $projectsPage['status'], 'Admin phải xem được trang dự án');
        assertContains('Tạo dự án', $projectsPage['body'], 'Admin phải thấy form tạo dự án');
        $projectToken = csrfFromHtml($projectsPage['body']);
        $name = 'Smoke Project ' . bin2hex(random_bytes(4));
        $created = integrationHttp($port, 'POST', '/projects.php', [
            'csrf' => $projectToken,
            'action' => 'create',
            'name' => $name,
            'description' => 'Tạo qua HTTP smoke test',
        ], $cookies);
        assertSame(303, $created['status'], 'Tạo dự án phải redirect');
        $createdRow = queryOne($connection, 'SELECT id FROM projects WHERE name=$1', [$name]);
        assertTrue($createdRow !== null, 'Dự án mới phải có trong database');
        $member = queryOne(
            $connection,
            "SELECT role FROM project_members WHERE project_id=\$1 AND user_id=(SELECT id FROM users WHERE username='admin_a')",
            [(string)$createdRow['id']]
        );
        assertSame('admin', (string)($member['role'] ?? ''), 'Người tạo phải là admin dự án');
        $auditPage = integrationHttp($port, 'GET', '/audit.php?project_id=' . $core, [], $cookies);
        assertSame(200, $auditPage['status'], 'Admin phải xem được audit');
        $dependenciesPage = integrationHttp(
            $port,
            'GET',
            '/dependencies.php?project_id=' . $core,
            [],
            $cookies
        );
        assertSame(200, $dependenciesPage['status'], 'Trang phụ thuộc phải chạy');
        $releasesPage = integrationHttp(
            $port,
            'GET',
            '/releases.php?project_id=' . $core . '&id=' . (int)$release['id'],
            [],
            $cookies
        );
        assertSame(200, $releasesPage['status'], 'Trang release phải chạy');
        assertContains('Điều kiện phát hành', $releasesPage['body'], 'Phải công bố điều kiện phát hành');
    });

    testCase('HTTP: release manager tạo, ready và chốt release', function () use (
        $port,
        $connection,
        $core
    ): void {
        $issue = queryOne(
            $connection,
            "SELECT id FROM issues
             WHERE project_id=\$1 AND priority <> 'blocker'
               AND id NOT IN (SELECT issue_id FROM dependencies)
             ORDER BY id LIMIT 1",
            [(string)$core]
        );
        assertTrue($issue !== null, 'Cần issue không blocker, không phụ thuộc');
        $cookies = [];
        $loginPage = integrationHttp($port, 'GET', '/login.php', [], $cookies);
        $token = csrfFromHtml($loginPage['body']);
        integrationHttp($port, 'POST', '/login.php', [
            'csrf' => $token,
            'username' => 'release_a',
            'password' => 'demo1234',
        ], $cookies);
        $releasePage = integrationHttp($port, 'GET', '/releases.php?project_id=' . $core, [], $cookies);
        assertSame(200, $releasePage['status'], 'Release manager phải xem được trang release');
        $releaseToken = csrfFromHtml($releasePage['body']);
        $version = '9.9.' . random_int(1, 9999);
        $created = integrationHttp($port, 'POST', '/releases.php', [
            'csrf' => $releaseToken,
            'action' => 'create',
            'project_id' => (string)$core,
            'version_name' => $version,
            'description' => 'Release qua HTTP',
        ], $cookies);
        assertSame(303, $created['status'], 'Tạo release phải redirect');
        $row = queryOne(
            $connection,
            'SELECT id FROM releases WHERE project_id=$1 AND version_name=$2',
            [(string)$core, $version]
        );
        assertTrue($row !== null, 'Release mới phải có trong database');
        $releaseId = (int)$row['id'];
        $added = integrationHttp($port, 'POST', '/releases.php', [
            'csrf' => $releaseToken,
            'action' => 'add_item',
            'project_id' => (string)$core,
            'release_id' => (string)$releaseId,
            'issue_id' => (string)$issue['id'],
        ], $cookies);
        assertSame(303, $added['status'], 'Thêm item phải redirect');
        $ready = integrationHttp($port, 'POST', '/releases.php', [
            'csrf' => $releaseToken,
            'action' => 'ready',
            'project_id' => (string)$core,
            'release_id' => (string)$releaseId,
        ], $cookies);
        assertSame(303, $ready['status'], 'Ready phải redirect');
        $releaseResult = integrationHttp($port, 'POST', '/releases.php', [
            'csrf' => $releaseToken,
            'action' => 'release',
            'project_id' => (string)$core,
            'release_id' => (string)$releaseId,
        ], $cookies);
        assertSame(303, $releaseResult['status'], 'Chốt release phải redirect');
        $stored = queryOne($connection, 'SELECT status FROM releases WHERE id=$1', [(string)$releaseId]);
        assertSame('released', (string)$stored['status'], 'Release phải ở trạng thái released');
        $item = queryOne(
            $connection,
            'SELECT snapshot_title FROM release_items WHERE release_id=$1 AND issue_id=$2',
            [(string)$releaseId, (string)$issue['id']]
        );
        assertTrue($item !== null && (string)$item['snapshot_title'] !== '', 'Phải có snapshot item');
    });

    testCase('HTTP: xuất release notes Markdown từ snapshot', function () use (
        $port,
        $core,
        $release
    ): void {
        $cookies = [];
        $loginPage = integrationHttp($port, 'GET', '/login.php', [], $cookies);
        $token = csrfFromHtml($loginPage['body']);
        integrationHttp($port, 'POST', '/login.php', [
            'csrf' => $token,
            'username' => 'dev_a',
            'password' => 'demo1234',
        ], $cookies);
        $response = integrationHttp(
            $port,
            'GET',
            '/release-notes.php?id=' . (int)$release['id'] . '&format=markdown',
            [],
            $cookies
        );
        assertSame(200, $response['status'], 'Release notes phải trả 200');
        assertContains(
            'text/markdown',
            implode(' ', $response['headers']['content-type'] ?? []),
            'Content-Type phải là Markdown'
        );
        assertContains('Ghi chú phát hành', $response['body'], 'Nội dung release notes');
    });
    testCase('HTTP P1-3 A: issue dự án B không được thêm vào release dự án A', function () use (
        $port,
        $connection,
        $core,
        $mobile
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
        $cookies = integrationLogin($port, 'release_a');
        $token = integrationToken($port, '/releases.php?project_id=' . $core, $cookies);
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

        $response = integrationHttp($port, 'POST', '/releases.php', [
            'csrf' => $token,
            'action' => 'add_item',
            'project_id' => (string)$core,
            'release_id' => (string)$releaseId,
            'issue_id' => (string)$foreignIssue['id'],
        ], $cookies);
        assertSame(200, $response['status'], 'Thêm issue chéo dự án phải trả trang lỗi, không redirect');
        assertContains('không thuộc dự án', $response['body'], 'Phải báo issue không thuộc dự án release');
        assertSame($beforeItems, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM release_items WHERE release_id=$1',
            [(string)$releaseId]
        )['total'], 'Không được có release item chéo dự án');
        assertSame($beforeAudit, (int)queryOne(
            $connection,
            "SELECT count(*) AS total FROM audit_log
             WHERE action='release_item_added' AND entity_type='release' AND entity_id=\$1",
            [(string)$releaseId]
        )['total'], 'Request bị từ chối không được ghi audit thành công');
    });

    testCase('HTTP P1-3 C1: CSRF cho cập nhật issue, bình luận và kết quả kiểm thử', function () use (
        $port,
        $connection,
        $devA,
        $testerA
    ): void {
        $project = testInsertProject($connection, 'CsrfIssue');
        $issue = testInsertIssue($connection, $project, 'CSRF issue', 'medium', 'open', $devA, $devA);
        $path = '/issues.php?project_id=' . $project . '&id=' . $issue;
        $cookies = integrationLogin($port, 'dev_a');
        $token = integrationToken($port, $path, $cookies);

        $update = [
            'action' => 'update',
            'project_id' => (string)$project,
            'id' => (string)$issue,
            'version' => '1',
            'title' => 'CSRF issue đã sửa',
            'description' => 'Mô tả CSRF',
            'steps' => 'Bước CSRF',
            'priority' => 'medium',
            'status' => 'open',
            'assignee_id' => (string)$devA,
            'commit_url' => '',
        ];
        $before = testIssueSnapshot($connection, $issue);
        $auditBefore = (int)queryOne(
            $connection,
            "SELECT count(*) AS total FROM audit_log
             WHERE action='issue_updated' AND entity_type='issue' AND entity_id=\$1",
            [(string)$issue]
        )['total'];
        integrationExpectCsrfRejected($port, '/issues.php', $update, $cookies, 'Cập nhật issue');
        $after = testIssueSnapshot($connection, $issue);
        assertSame((string)$before['title'], (string)$after['title'], 'CSRF sai không được đổi tiêu đề issue');
        assertSame((int)$before['version'], (int)$after['version'], 'CSRF sai không được tăng version issue');
        assertSame($auditBefore, (int)queryOne(
            $connection,
            "SELECT count(*) AS total FROM audit_log
             WHERE action='issue_updated' AND entity_type='issue' AND entity_id=\$1",
            [(string)$issue]
        )['total'], 'CSRF sai không được ghi audit cập nhật issue');

        $update['csrf'] = $token;
        $response = integrationHttp($port, 'POST', '/issues.php', $update, $cookies);
        assertSame(303, $response['status'], 'CSRF hợp lệ phải cập nhật issue và redirect');
        $afterUpdate = testIssueSnapshot($connection, $issue);
        assertSame('CSRF issue đã sửa', (string)$afterUpdate['title'], 'Cập nhật hợp lệ phải ghi dữ liệu');
        assertSame((int)$before['version'] + 1, (int)$afterUpdate['version'], 'Cập nhật hợp lệ phải tăng version');

        $comment = [
            'action' => 'comment',
            'project_id' => (string)$project,
            'id' => (string)$issue,
            'body' => 'Bình luận CSRF',
        ];
        $commentBefore = (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM comments WHERE issue_id=$1',
            [(string)$issue]
        )['total'];
        integrationExpectCsrfRejected($port, '/issues.php', $comment, $cookies, 'Bình luận');
        assertSame($commentBefore, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM comments WHERE issue_id=$1',
            [(string)$issue]
        )['total'], 'CSRF sai không được thêm bình luận');
        $comment['csrf'] = $token;
        $response = integrationHttp($port, 'POST', '/issues.php', $comment, $cookies);
        assertSame(303, $response['status'], 'CSRF hợp lệ phải thêm bình luận');
        assertSame($commentBefore + 1, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM comments WHERE issue_id=$1',
            [(string)$issue]
        )['total'], 'Bình luận hợp lệ phải được lưu');

        $testerCookies = integrationLogin($port, 'tester_a');
        $testerToken = integrationToken($port, $path, $testerCookies);
        $testPost = [
            'action' => 'test',
            'project_id' => (string)$project,
            'id' => (string)$issue,
            'result' => 'passed',
            'notes' => 'CSRF test',
        ];
        $testBefore = (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM test_results WHERE issue_id=$1',
            [(string)$issue]
        )['total'];
        integrationExpectCsrfRejected($port, '/issues.php', $testPost, $testerCookies, 'Ghi kết quả kiểm thử');
        assertSame($testBefore, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM test_results WHERE issue_id=$1',
            [(string)$issue]
        )['total'], 'CSRF sai không được ghi kết quả kiểm thử');
        $testPost['csrf'] = $testerToken;
        $response = integrationHttp($port, 'POST', '/issues.php', $testPost, $testerCookies);
        assertSame(303, $response['status'], 'CSRF hợp lệ phải ghi kết quả kiểm thử');
        assertSame($testBefore + 1, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM test_results WHERE issue_id=$1',
            [(string)$issue]
        )['total'], 'Kết quả kiểm thử hợp lệ phải được lưu');
        assertSame('verified', (string)testIssueSnapshot($connection, $issue)['status'], 'Test passed phải đưa issue sang verified');
        $version = (int)testIssueSnapshot($connection, $issue)['version'];
        assertSame(
            true,
            IssueService::hasValidPassedTest($connection, $issue, $version),
            'Test ghi qua HTTP phải có audit provenance đáng tin'
        );
        $recordedRole = queryOne(
            $connection,
            "SELECT details->>'tester_role' AS tester_role
             FROM audit_log
             WHERE action='test_result_recorded' AND entity_type='test_result'
               AND project_id=\$1 AND user_id=\$2
             ORDER BY id DESC LIMIT 1",
            [(string)$project, (string)$testerA]
        );
        assertSame('tester', (string)($recordedRole['tester_role'] ?? ''), 'Audit HTTP phải lưu vai trò tại thời điểm ghi');
    });

    testCase('HTTP P1-3 C2: CSRF cho thêm và xóa dependency', function () use (
        $port,
        $connection,
        $devA
    ): void {
        $project = testInsertProject($connection, 'CsrfDependency');
        $issueA = testInsertIssue($connection, $project, 'CSRF dependency A', 'medium', 'open', $devA, $devA);
        $issueB = testInsertIssue($connection, $project, 'CSRF dependency B', 'medium', 'open', $devA, $devA);
        $cookies = integrationLogin($port, 'dev_a');
        $path = '/dependencies.php?project_id=' . $project;
        $token = integrationToken($port, $path, $cookies);

        $add = [
            'action' => 'add',
            'project_id' => (string)$project,
            'issue_id' => (string)$issueA,
            'depends_on_id' => (string)$issueB,
        ];
        integrationExpectCsrfRejected($port, '/dependencies.php', $add, $cookies, 'Thêm dependency');
        assertSame(0, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM dependencies WHERE issue_id=$1 AND depends_on_id=$2',
            [(string)$issueA, (string)$issueB]
        )['total'], 'CSRF sai không được thêm dependency');
        assertSame(0, (int)queryOne(
            $connection,
            "SELECT count(*) AS total FROM audit_log WHERE project_id=\$1 AND action='dependency_added'",
            [(string)$project]
        )['total'], 'CSRF sai không được ghi audit thêm dependency');

        $add['csrf'] = $token;
        $response = integrationHttp($port, 'POST', '/dependencies.php', $add, $cookies);
        assertSame(303, $response['status'], 'CSRF hợp lệ phải thêm dependency');
        assertSame(1, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM dependencies WHERE issue_id=$1 AND depends_on_id=$2',
            [(string)$issueA, (string)$issueB]
        )['total'], 'Dependency hợp lệ phải được lưu');

        $remove = [
            'action' => 'remove',
            'project_id' => (string)$project,
            'issue_id' => (string)$issueA,
            'depends_on_id' => (string)$issueB,
        ];
        integrationExpectCsrfRejected($port, '/dependencies.php', $remove, $cookies, 'Xóa dependency');
        assertSame(1, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM dependencies WHERE issue_id=$1 AND depends_on_id=$2',
            [(string)$issueA, (string)$issueB]
        )['total'], 'CSRF sai không được xóa dependency');
        assertSame(0, (int)queryOne(
            $connection,
            "SELECT count(*) AS total FROM audit_log WHERE project_id=\$1 AND action='dependency_removed'",
            [(string)$project]
        )['total'], 'CSRF sai không được ghi audit xóa dependency');

        $remove['csrf'] = $token;
        $response = integrationHttp($port, 'POST', '/dependencies.php', $remove, $cookies);
        assertSame(303, $response['status'], 'CSRF hợp lệ phải xóa dependency');
        assertSame(0, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM dependencies WHERE issue_id=$1 AND depends_on_id=$2',
            [(string)$issueA, (string)$issueB]
        )['total'], 'Dependency hợp lệ phải bị xóa');
    });

    testCase('HTTP P1-3 C3: CSRF cho thêm, xóa thành viên và bảo vệ admin cuối', function () use (
        $port,
        $connection,
        $adminA
    ): void {
        $project = testInsertProject($connection, 'CsrfMember');
        $devB = testUserId($connection, 'dev_b');
        $cookies = integrationLogin($port, 'admin_a');
        $path = '/projects.php?project_id=' . $project;
        $token = integrationToken($port, $path, $cookies);

        $add = [
            'action' => 'add_member',
            'project_id' => (string)$project,
            'username' => 'dev_b',
            'member_role' => 'developer',
        ];
        integrationExpectCsrfRejected($port, '/projects.php', $add, $cookies, 'Thêm thành viên');
        assertSame(0, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM project_members WHERE project_id=$1 AND user_id=$2',
            [(string)$project, (string)$devB]
        )['total'], 'CSRF sai không được thêm thành viên');
        assertSame(0, (int)queryOne(
            $connection,
            "SELECT count(*) AS total FROM audit_log WHERE project_id=\$1 AND action='member_set'",
            [(string)$project]
        )['total'], 'CSRF sai không được ghi audit thành viên');

        $add['csrf'] = $token;
        $response = integrationHttp($port, 'POST', '/projects.php', $add, $cookies);
        assertSame(303, $response['status'], 'CSRF hợp lệ phải thêm thành viên');
        assertSame('developer', (string)queryOne(
            $connection,
            'SELECT role FROM project_members WHERE project_id=$1 AND user_id=$2',
            [(string)$project, (string)$devB]
        )['role'], 'Thành viên hợp lệ phải được lưu');

        $remove = [
            'action' => 'remove_member',
            'project_id' => (string)$project,
            'user_id' => (string)$devB,
        ];
        integrationExpectCsrfRejected($port, '/projects.php', $remove, $cookies, 'Xóa thành viên');
        assertSame(1, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM project_members WHERE project_id=$1 AND user_id=$2',
            [(string)$project, (string)$devB]
        )['total'], 'CSRF sai không được xóa thành viên');
        assertSame(0, (int)queryOne(
            $connection,
            "SELECT count(*) AS total FROM audit_log WHERE project_id=\$1 AND action='member_removed'",
            [(string)$project]
        )['total'], 'CSRF sai không được ghi audit xóa thành viên');

        $remove['csrf'] = $token;
        $response = integrationHttp($port, 'POST', '/projects.php', $remove, $cookies);
        assertSame(303, $response['status'], 'CSRF hợp lệ phải xóa thành viên');
        assertSame(0, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM project_members WHERE project_id=$1 AND user_id=$2',
            [(string)$project, (string)$devB]
        )['total'], 'Thành viên hợp lệ phải bị xóa');

        $lastAdmin = [
            'csrf' => $token,
            'action' => 'remove_member',
            'project_id' => (string)$project,
            'user_id' => (string)$adminA,
        ];
        $response = integrationHttp($port, 'POST', '/projects.php', $lastAdmin, $cookies);
        assertSame(200, $response['status'], 'Gỡ admin cuối phải trả trang lỗi, không redirect thành công');
        assertContains('ít nhất một admin', $response['body'], 'Phải báo không thể gỡ admin cuối');
        assertSame('admin', (string)queryOne(
            $connection,
            'SELECT role FROM project_members WHERE project_id=$1 AND user_id=$2',
            [(string)$project, (string)$adminA]
        )['role'], 'Admin cuối phải giữ nguyên');
    });

    testCase('HTTP P1-3 C4: CSRF cho tạo release, thêm/gỡ item, ready và released', function () use (
        $port,
        $connection,
        $devA,
        $testerA
    ): void {
        $project = testInsertProject($connection, 'CsrfRelease');
        $issue = testInsertIssue($connection, $project, 'CSRF release issue', 'medium', 'open', $devA, $devA);
        IssueService::recordTest($connection, $project, $testerA, 'tester', $issue, [
            'result' => 'passed',
            'notes' => 'Pass trước khi kiểm tra CSRF release',
        ]);
        $cookies = integrationLogin($port, 'release_a');
        $path = '/releases.php?project_id=' . $project;
        $token = integrationToken($port, $path, $cookies);
        $version = 'CSRF-' . bin2hex(random_bytes(4));

        $create = [
            'action' => 'create',
            'project_id' => (string)$project,
            'version_name' => $version,
            'description' => 'Release kiểm tra CSRF',
        ];
        integrationExpectCsrfRejected($port, '/releases.php', $create, $cookies, 'Tạo release');
        assertSame(0, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM releases WHERE project_id=$1 AND version_name=$2',
            [(string)$project, $version]
        )['total'], 'CSRF sai không được tạo release');
        assertSame(0, (int)queryOne(
            $connection,
            "SELECT count(*) AS total FROM audit_log WHERE project_id=\$1 AND action='release_created'",
            [(string)$project]
        )['total'], 'CSRF sai không được ghi audit tạo release');

        $create['csrf'] = $token;
        $response = integrationHttp($port, 'POST', '/releases.php', $create, $cookies);
        assertSame(303, $response['status'], 'CSRF hợp lệ phải tạo release');
        $releaseRow = queryOne(
            $connection,
            'SELECT id FROM releases WHERE project_id=$1 AND version_name=$2',
            [(string)$project, $version]
        );
        assertTrue($releaseRow !== null, 'Release hợp lệ phải có trong database');
        $releaseId = (int)$releaseRow['id'];

        $addItem = [
            'action' => 'add_item',
            'project_id' => (string)$project,
            'release_id' => (string)$releaseId,
            'issue_id' => (string)$issue,
        ];
        integrationExpectCsrfRejected($port, '/releases.php', $addItem, $cookies, 'Thêm release item');
        assertSame(0, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM release_items WHERE release_id=$1',
            [(string)$releaseId]
        )['total'], 'CSRF sai không được thêm release item');
        $addItem['csrf'] = $token;
        $response = integrationHttp($port, 'POST', '/releases.php', $addItem, $cookies);
        assertSame(303, $response['status'], 'CSRF hợp lệ phải thêm release item');

        $removeItem = [
            'action' => 'remove_item',
            'project_id' => (string)$project,
            'release_id' => (string)$releaseId,
            'issue_id' => (string)$issue,
        ];
        integrationExpectCsrfRejected($port, '/releases.php', $removeItem, $cookies, 'Gỡ release item');
        assertSame(1, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM release_items WHERE release_id=$1',
            [(string)$releaseId]
        )['total'], 'CSRF sai không được gỡ release item');
        $removeItem['csrf'] = $token;
        $response = integrationHttp($port, 'POST', '/releases.php', $removeItem, $cookies);
        assertSame(303, $response['status'], 'CSRF hợp lệ phải gỡ release item');
        assertSame(0, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM release_items WHERE release_id=$1',
            [(string)$releaseId]
        )['total'], 'Release item hợp lệ phải bị gỡ');
        $addItem['csrf'] = $token;
        integrationHttp($port, 'POST', '/releases.php', $addItem, $cookies);

        $ready = [
            'action' => 'ready',
            'project_id' => (string)$project,
            'release_id' => (string)$releaseId,
        ];
        integrationExpectCsrfRejected($port, '/releases.php', $ready, $cookies, 'Chuyển ready');
        assertSame('planned', (string)queryOne(
            $connection,
            'SELECT status FROM releases WHERE id=$1',
            [(string)$releaseId]
        )['status'], 'CSRF sai không được chuyển release sang ready');
        assertSame(0, (int)queryOne(
            $connection,
            "SELECT count(*) AS total FROM audit_log
             WHERE action='release_ready' AND entity_type='release' AND entity_id=\$1",
            [(string)$releaseId]
        )['total'], 'CSRF sai không được ghi audit ready');
        $ready['csrf'] = $token;
        $response = integrationHttp($port, 'POST', '/releases.php', $ready, $cookies);
        assertSame(303, $response['status'], 'CSRF hợp lệ phải chuyển release sang ready');
        assertSame('ready', (string)queryOne(
            $connection,
            'SELECT status FROM releases WHERE id=$1',
            [(string)$releaseId]
        )['status'], 'Release phải ở trạng thái ready');

        $final = [
            'action' => 'release',
            'project_id' => (string)$project,
            'release_id' => (string)$releaseId,
        ];
        integrationExpectCsrfRejected($port, '/releases.php', $final, $cookies, 'Chốt release');
        assertSame('ready', (string)queryOne(
            $connection,
            'SELECT status FROM releases WHERE id=$1',
            [(string)$releaseId]
        )['status'], 'CSRF sai không được chốt release');
        assertSame(0, (int)queryOne(
            $connection,
            "SELECT count(*) AS total FROM audit_log
             WHERE action='release_snapshotted' AND entity_type='release' AND entity_id=\$1",
            [(string)$releaseId]
        )['total'], 'CSRF sai không được ghi audit chốt release');
        $final['csrf'] = $token;
        $response = integrationHttp($port, 'POST', '/releases.php', $final, $cookies);
        assertSame(303, $response['status'], 'CSRF hợp lệ phải chốt release');
        assertSame('released', (string)queryOne(
            $connection,
            'SELECT status FROM releases WHERE id=$1',
            [(string)$releaseId]
        )['status'], 'Release phải ở trạng thái released');
        $snapshot = queryOne(
            $connection,
            'SELECT snapshot_test_result,snapshot_test_version FROM release_items WHERE release_id=$1 AND issue_id=$2',
            [(string)$releaseId, (string)$issue]
        );
        assertSame('passed', (string)($snapshot['snapshot_test_result'] ?? ''), 'Snapshot phải ghi test passed');
    });

    testCase('HTTP P1-3 C5: POST chưa đăng nhập không được ghi dữ liệu', function () use (
        $port,
        $connection,
        $core,
        $mobile
    ): void {
        $count = static fn(): array => queryOne(
            $connection,
            'SELECT
                (SELECT count(*) FROM issues) AS issues,
                (SELECT count(*) FROM dependencies) AS dependencies,
                (SELECT count(*) FROM project_members) AS members,
                (SELECT count(*) FROM releases) AS releases,
                (SELECT count(*) FROM release_items) AS items,
                (SELECT count(*) FROM comments) AS comments,
                (SELECT count(*) FROM test_results) AS tests,
                (SELECT count(*) FROM audit_log) AS audit'
        );
        $before = $count();
        $requests = [
            ['/issues.php', ['action' => 'create', 'project_id' => (string)$core, 'title' => 'Không được tạo']],
            ['/dependencies.php', ['action' => 'add', 'project_id' => (string)$core, 'issue_id' => '1', 'depends_on_id' => '2']],
            ['/projects.php', ['action' => 'add_member', 'project_id' => (string)$mobile, 'username' => 'dev_a', 'member_role' => 'developer']],
            ['/releases.php', ['action' => 'create', 'project_id' => (string)$core, 'version_name' => 'UNAUTH']],
        ];
        $cookies = [];
        foreach ($requests as [$path, $post]) {
            $response = integrationHttp($port, 'POST', $path, $post, $cookies);
            assertSame(302, $response['status'], 'POST chưa đăng nhập phải redirect: ' . $path);
            assertContains('/login.php', implode(' ', $response['headers']['location'] ?? []), 'Redirect login: ' . $path);
        }
        $after = $count();
        assertSame($before, $after, 'POST chưa đăng nhập không được làm thay đổi dữ liệu');

        $guestCookies = [];
        $loginPage = integrationHttp($port, 'GET', '/login.php', [], $guestCookies);
        $guestToken = csrfFromHtml($loginPage['body']);
        $response = integrationHttp($port, 'POST', '/issues.php', [
            'csrf' => $guestToken,
            'action' => 'create',
            'project_id' => (string)$core,
            'title' => 'Không được tạo dù có CSRF',
        ], $guestCookies);
        assertSame(302, $response['status'], 'Có CSRF nhưng chưa đăng nhập vẫn phải redirect login');
    });

    testCase('HTTP P1-3 C6: logout là POST và bắt buộc CSRF', function () use ($port): void {
        $cookies = integrationLogin($port, 'dev_a');
        $response = integrationHttp($port, 'POST', '/logout.php', [], $cookies);
        assertSame(419, $response['status'], 'Logout thiếu CSRF phải trả 419');
        $stillLoggedIn = integrationHttp($port, 'GET', '/', [], $cookies);
        assertSame(200, $stillLoggedIn['status'], 'Logout thiếu CSRF không được hủy phiên');

        $token = integrationToken($port, '/', $cookies);
        $response = integrationHttp($port, 'POST', '/logout.php', ['csrf' => $token], $cookies);
        assertSame(303, $response['status'], 'Logout có CSRF phải redirect');
        $afterLogout = integrationHttp($port, 'GET', '/', [], $cookies);
        assertSame(302, $afterLogout['status'], 'Sau logout phải quay về login');
    });

    testCase('HTTP P1-3 C7: CSRF cho tạo issue, tạo và cập nhật dự án', function () use (
        $port,
        $connection,
        $core,
        $devA
    ): void {
        $cookies = integrationLogin($port, 'dev_a');
        $issueToken = integrationToken($port, '/issues.php?project_id=' . $core, $cookies);
        $issueCount = static fn(): int => (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM issues WHERE project_id=$1',
            [(string)$core]
        )['total'];
        $issueAuditCount = static fn(): int => (int)queryOne(
            $connection,
            "SELECT count(*) AS total FROM audit_log
             WHERE project_id=\$1 AND action='issue_created'",
            [(string)$core]
        )['total'];
        $issueBefore = $issueCount();
        $auditBefore = $issueAuditCount();
        $createIssue = [
            'action' => 'create',
            'project_id' => (string)$core,
            'title' => 'CSRF issue create',
            'description' => 'Mô tả',
            'steps' => 'Bước',
            'issue_type' => 'task',
            'priority' => 'medium',
            'assignee_id' => (string)$devA,
            'commit_url' => '',
        ];
        integrationExpectCsrfRejected($port, '/issues.php', $createIssue, $cookies, 'Tạo issue');
        assertSame($issueBefore, $issueCount(), 'CSRF sai không được tạo issue');
        assertSame($auditBefore, $issueAuditCount(), 'CSRF sai không được ghi audit tạo issue');

        $createIssue['csrf'] = $issueToken;
        $response = integrationHttp($port, 'POST', '/issues.php', $createIssue, $cookies);
        assertSame(303, $response['status'], 'CSRF hợp lệ phải tạo issue');
        assertSame($issueBefore + 1, $issueCount(), 'CSRF hợp lệ phải lưu issue mới');
        assertSame($auditBefore + 1, $issueAuditCount(), 'CSRF hợp lệ phải ghi audit tạo issue');
        assertTrue(queryOne(
            $connection,
            'SELECT id FROM issues WHERE project_id=$1 AND title=$2',
            [(string)$core, 'CSRF issue create']
        ) !== null, 'Issue mới phải có trong database');

        $adminCookies = integrationLogin($port, 'admin_a');
        $projectToken = integrationToken($port, '/projects.php', $adminCookies);
        $name = 'CSRF Project ' . bin2hex(random_bytes(4));
        $createProject = [
            'action' => 'create',
            'name' => $name,
            'description' => 'Dự án kiểm tra CSRF',
        ];
        integrationExpectCsrfRejected($port, '/projects.php', $createProject, $adminCookies, 'Tạo dự án');
        assertSame(0, (int)queryOne(
            $connection,
            'SELECT count(*) AS total FROM projects WHERE name=$1',
            [$name]
        )['total'], 'CSRF sai không được tạo dự án');

        $createProject['csrf'] = $projectToken;
        $response = integrationHttp($port, 'POST', '/projects.php', $createProject, $adminCookies);
        assertSame(303, $response['status'], 'CSRF hợp lệ phải tạo dự án');
        $created = queryOne($connection, 'SELECT id FROM projects WHERE name=$1', [$name]);
        assertTrue($created !== null, 'Dự án mới phải có trong database');
        $projectId = (int)$created['id'];

        $updateToken = integrationToken(
            $port,
            '/projects.php?project_id=' . $projectId,
            $adminCookies
        );
        $updatedName = $name . ' updated';
        $updateProject = [
            'action' => 'update',
            'project_id' => (string)$projectId,
            'name' => $updatedName,
            'description' => 'Dự án đã cập nhật',
        ];
        integrationExpectCsrfRejected($port, '/projects.php', $updateProject, $adminCookies, 'Cập nhật dự án');
        assertSame($name, (string)queryOne(
            $connection,
            'SELECT name FROM projects WHERE id=$1',
            [(string)$projectId]
        )['name'], 'CSRF sai không được đổi tên dự án');

        $updateProject['csrf'] = $updateToken;
        $response = integrationHttp($port, 'POST', '/projects.php', $updateProject, $adminCookies);
        assertSame(303, $response['status'], 'CSRF hợp lệ phải cập nhật dự án');
        assertSame($updatedName, (string)queryOne(
            $connection,
            'SELECT name FROM projects WHERE id=$1',
            [(string)$projectId]
        )['name'], 'CSRF hợp lệ phải lưu tên dự án mới');
    });
} finally {
    proc_terminate($server);
    proc_close($server);
    @unlink($log);
}

testFinish('INTEGRATION');
