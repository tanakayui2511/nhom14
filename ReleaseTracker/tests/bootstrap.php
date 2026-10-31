<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/IssueService.php';
require_once __DIR__ . '/../src/ProjectService.php';
require_once __DIR__ . '/../src/ReleaseService.php';
require_once __DIR__ . '/../src/ReleaseNotes.php';
require_once __DIR__ . '/helper.php';

final class TestDatabase
{
    private static ?string $schema = null;
    private static ?PgSql\Connection $base = null;

    public static function boot(): PgSql\Connection
    {
        if (getenv('RT_DB_SCHEMA') !== false) {
            putenv('RT_DB_SCHEMA');
        }
        try {
            $base = db();
        } catch (Throwable $exception) {
            fwrite(STDERR, 'NOT_RUN: không kết nối được PostgreSQL: ' . $exception->getMessage() . "\n");
            exit(2);
        }
        $schema = 'rt_test_' . bin2hex(random_bytes(6));
        $quoted = pg_escape_identifier($base, $schema);
        $result = @pg_query($base, 'CREATE SCHEMA ' . $quoted);
        if ($result === false) {
            fwrite(STDERR, 'NOT_RUN: không tạo được schema test: ' . pg_last_error($base) . "\n");
            exit(2);
        }
        self::$schema = $schema;
        self::$base = $base;
        if (@pg_query_params($base, "SELECT set_config('search_path',\$1,false)", [$schema]) === false) {
            self::drop();
            fwrite(STDERR, 'NOT_RUN: không đặt được search_path cho schema test.' . "\n");
            exit(2);
        }
        try {
            $files = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
            sort($files, SORT_STRING);
            if ($files === []) {
                throw new RuntimeException('Không tìm thấy migration.');
            }
            foreach ($files as $file) {
                $sql = (string)file_get_contents($file);
                $result = @pg_query($base, $sql);
                if ($result === false) {
                    throw new RuntimeException(
                        'Migration lỗi ' . basename($file) . ': ' . pg_last_error($base)
                    );
                }
            }
            $seed = (string)file_get_contents(__DIR__ . '/../database/seed.sql');
            $result = @pg_query($base, $seed);
            if ($result === false) {
                throw new RuntimeException('Seed lỗi: ' . pg_last_error($base));
            }
        } catch (Throwable $exception) {
            self::drop();
            fwrite(STDERR, 'NOT_RUN: ' . $exception->getMessage() . "\n");
            exit(2);
        }
        putenv('RT_DB_SCHEMA=' . $schema);
        register_shutdown_function([self::class, 'drop']);
        return $base;
    }

    public static function schema(): string
    {
        return self::$schema ?? '';
    }

    public static function drop(): void
    {
        if (self::$schema === null) {
            return;
        }
        $schema = self::$schema;
        self::$schema = null;
        try {
            $connection = db();
            $quoted = pg_escape_identifier($connection, $schema);
            @pg_query($connection, 'DROP SCHEMA IF EXISTS ' . $quoted . ' CASCADE');
        } catch (Throwable $exception) {
            fwrite(STDERR, 'WARN: không dọn được schema test ' . $schema . ': '
                . $exception->getMessage() . "\n");
        }
    }
}

function testProjectId(PgSql\Connection $connection, string $name): int
{
    $row = queryOne($connection, 'SELECT id FROM projects WHERE name=$1', [$name]);
    if ($row === null) {
        throw new RuntimeException('Không tìm thấy dự án mẫu: ' . $name);
    }
    return (int)$row['id'];
}

function testUserId(PgSql\Connection $connection, string $username): int
{
    $row = queryOne($connection, 'SELECT id FROM users WHERE username=$1', [$username]);
    if ($row === null) {
        throw new RuntimeException('Không tìm thấy người dùng mẫu: ' . $username);
    }
    return (int)$row['id'];
}

function testInsertProject(PgSql\Connection $connection, string $prefix): int
{
    $name = $prefix . ' ' . bin2hex(random_bytes(4));
    $result = queryParams(
        $connection,
        'INSERT INTO projects(name,description) VALUES($1,$2) RETURNING id',
        [$name, 'Dự án kiểm thử tự động']
    );
    $projectId = (int)pg_fetch_result($result, 0, 0);
    $members = [
        'dev_a' => 'developer',
        'tester_a' => 'tester',
        'release_a' => 'release_manager',
        'admin_a' => 'admin',
    ];
    foreach ($members as $username => $role) {
        queryParams(
            $connection,
            'INSERT INTO project_members(project_id,user_id,role)
             SELECT $1,id,$2 FROM users WHERE username=$3',
            [(string)$projectId, $role, $username]
        );
    }
    return $projectId;
}

function testInsertIssue(
    PgSql\Connection $connection,
    int $projectId,
    string $title,
    string $priority = 'medium',
    string $status = 'open',
    ?int $reporterId = null,
    ?int $assigneeId = null
): int {
    $result = queryParams(
        $connection,
        'INSERT INTO issues(
            project_id,title,description,reproduction_steps,issue_type,priority,status,
            reporter_id,assignee_id
         ) VALUES($1,$2,$3,$4,\'bug\',$5,$6,$7,$8) RETURNING id',
        [
            (string)$projectId,
            $title,
            'Mô tả ' . $title,
            'Bước 1; Bước 2',
            $priority,
            $status,
            $reporterId === null ? null : (string)$reporterId,
            $assigneeId === null ? null : (string)$assigneeId,
        ]
    );
    return (int)pg_fetch_result($result, 0, 0);
}

function testIssueSnapshot(PgSql\Connection $connection, int $issueId): array
{
    $row = queryOne($connection, 'SELECT * FROM issues WHERE id=$1', [(string)$issueId]);
    if ($row === null) {
        throw new RuntimeException('Không tìm thấy issue #' . $issueId);
    }
    return $row;
}

function environmentWith(array $extra): array
{
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    return array_merge($environment, $extra);
}

function startWorker(array $job): array
{
    $command = [PHP_BINARY, __DIR__ . '/worker.php'];
    $environment = environmentWith([
        'RT_WORKER_MODE' => (string)$job['mode'],
        'RT_WORKER_INPUT' => (string)json_encode($job['input'], JSON_UNESCAPED_UNICODE),
    ]);
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Không khởi chạy được worker.');
    }
    return ['process' => $process, 'pipes' => $pipes];
}

function finishWorker(array $entry): array
{
    $stdout = stream_get_contents($entry['pipes'][1]);
    $stderr = stream_get_contents($entry['pipes'][2]);
    fclose($entry['pipes'][1]);
    fclose($entry['pipes'][2]);
    $code = proc_close($entry['process']);
    $decoded = json_decode((string)$stdout, true);
    return [
        'exit' => $code,
        'stdout' => trim((string)$stdout),
        'stderr' => trim((string)$stderr),
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function runLegacyReport(array $arguments): array
{
    $command = array_merge([PHP_BINARY, __DIR__ . '/../tools/legacy-report.php'], $arguments);
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__), environmentWith([]));
    if (!is_resource($process)) {
        throw new RuntimeException('Không khởi chạy được legacy report.');
    }
    return finishWorker(['process' => $process, 'pipes' => $pipes]);
}

function runWorkers(array $jobs): array
{
    $processes = [];
    foreach ($jobs as $index => $job) {
        $processes[$index] = startWorker($job);
    }
    $results = [];
    foreach ($processes as $index => $entry) {
        $results[$index] = finishWorker($entry);
    }
    return $results;
}

function integrationHttp(
    int $port,
    string $method,
    string $path,
    array $post = [],
    array &$cookies = []
): array {
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 5);
    if (!is_resource($socket)) {
        throw new RuntimeException('Không kết nối được HTTP test server: ' . $errorMessage);
    }
    $body = http_build_query($post);
    $request = $method . ' ' . $path . " HTTP/1.1\r\n"
        . 'Host: 127.0.0.1:' . $port . "\r\n"
        . "Connection: close\r\n"
        . "Accept: text/html,application/json\r\n";
    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $request .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    if ($method === 'POST') {
        $request .= "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n";
    }
    $request .= "\r\n" . $body;
    fwrite($socket, $request);
    $response = stream_get_contents($socket);
    fclose($socket);
    if ($response === false) {
        throw new RuntimeException('Không đọc được phản hồi HTTP.');
    }
    $parts = explode("\r\n\r\n", $response, 2);
    $head = $parts[0] ?? '';
    $responseBody = $parts[1] ?? '';
    $lines = explode("\r\n", $head);
    $statusLine = array_shift($lines) ?: '';
    preg_match('/^HTTP\/\d\.\d\s+(\d{3})/', $statusLine, $matches);
    $status = isset($matches[1]) ? (int)$matches[1] : 0;
    $headers = [];
    foreach ($lines as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))][] = trim($value);
        }
    }
    foreach ($headers['set-cookie'] ?? [] as $cookie) {
        $pair = explode(';', $cookie, 2)[0];
        if (str_contains($pair, '=')) {
            [$name, $value] = explode('=', $pair, 2);
            if ($value === '') {
                unset($cookies[$name]);
            } else {
                $cookies[$name] = $value;
            }
        }
    }
    return [
        'status' => $status,
        'headers' => $headers,
        'body' => $responseBody,
    ];
}

function csrfFromHtml(string $html): string
{
    if (preg_match('/name="csrf" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Không tìm thấy CSRF token trong HTML.');
    }
    return $matches[1];
}

function integrationLogin(int $port, string $username, string $password = 'demo1234'): array
{
    $cookies = [];
    $loginPage = integrationHttp($port, 'GET', '/login.php', [], $cookies);
    assertSame(200, $loginPage['status'], 'Trang login phải trả 200 trước khi đăng nhập ' . $username);
    $token = csrfFromHtml($loginPage['body']);
    $login = integrationHttp($port, 'POST', '/login.php', [
        'csrf' => $token,
        'username' => $username,
        'password' => $password,
    ], $cookies);
    assertSame(303, $login['status'], 'Đăng nhập ' . $username . ' phải redirect');
    return $cookies;
}

function integrationToken(int $port, string $path, array &$cookies): string
{
    $page = integrationHttp($port, 'GET', $path, [], $cookies);
    assertSame(200, $page['status'], 'Trang lấy CSRF token phải trả 200: ' . $path);
    return csrfFromHtml($page['body']);
}

function integrationExpectCsrfRejected(
    int $port,
    string $path,
    array $post,
    array &$cookies,
    string $message
): void {
    $missing = $post;
    unset($missing['csrf']);
    $response = integrationHttp($port, 'POST', $path, $missing, $cookies);
    assertSame(419, $response['status'], $message . ': thiếu CSRF phải trả 419');
    assertContains('CSRF', $response['body'], $message . ': thiếu CSRF phải có thông báo');

    $invalid = $post;
    $invalid['csrf'] = 'csrf-khong-hop-le-' . bin2hex(random_bytes(6));
    $response = integrationHttp($port, 'POST', $path, $invalid, $cookies);
    assertSame(419, $response['status'], $message . ': CSRF sai phải trả 419');
    assertContains('CSRF', $response['body'], $message . ': CSRF sai phải có thông báo');
}
