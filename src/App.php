<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Validation.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function currentUser(): ?array
{
    $user = $_SESSION['user'] ?? null;
    return is_array($user) ? $user : null;
}

function currentUserId(): ?int
{
    $user = currentUser();
    return $user === null ? null : (int)$user['id'];
}

function requireLogin(): array
{
    $user = currentUser();
    if ($user === null) {
        header('Location: /login.php');
        exit;
    }
    return $user;
}

function projectRole(PgSql\Connection $connection, int $projectId, array $user): ?string
{
    $row = queryOne(
        $connection,
        'SELECT role FROM project_members WHERE project_id=$1 AND user_id=$2',
        [(string)$projectId, (string)$user['id']]
    );
    return $row === null ? null : (string)$row['role'];
}

function canAccessProject(PgSql\Connection $connection, int $projectId, array $user): bool
{
    return projectRole($connection, $projectId, $user) !== null;
}

function requireProjectAccess(PgSql\Connection $connection, int $projectId, array $user): void
{
    if (!canAccessProject($connection, $projectId, $user)) {
        http_response_code(403);
        exit('Bạn không có quyền truy cập dự án này.');
    }
}

function canAssignToProject(PgSql\Connection $connection, int $projectId, int $userId): bool
{
    $row = queryOne(
        $connection,
        'SELECT 1 FROM project_members WHERE project_id=$1 AND user_id=$2',
        [(string)$projectId, (string)$userId]
    );
    return $row !== null;
}

function hasProjectRole(?string $role, array $allowed): bool
{
    if ($role === null) {
        return false;
    }
    if ($role === 'admin') {
        return true;
    }
    return in_array($role, $allowed, true);
}

function requireProjectRole(
    PgSql\Connection $connection,
    int $projectId,
    array $user,
    array $allowed,
    string $message = 'Bạn không có quyền thực hiện thao tác này.'
): string {
    $role = projectRole($connection, $projectId, $user);
    if ($role === null) {
        http_response_code(403);
        exit('Bạn không có quyền truy cập dự án này.');
    }
    if (!hasProjectRole($role, $allowed)) {
        http_response_code(403);
        exit($message);
    }
    return $role;
}

function projectRoleForUpdate(
    PgSql\Connection $connection,
    int $projectId,
    int $userId
): ?string {
    $row = queryOne(
        $connection,
        'SELECT role FROM project_members
         WHERE project_id=$1 AND user_id=$2
         FOR UPDATE',
        [(string)$projectId, (string)$userId]
    );
    return $row === null ? null : (string)$row['role'];
}

function requireProjectMembershipInTransaction(
    PgSql\Connection $connection,
    int $projectId,
    int $userId,
    string $message = 'Bạn không có quyền thực hiện thao tác này.'
): string {
    $role = projectRoleForUpdate($connection, $projectId, $userId);
    if ($role === null) {
        throw new ForbiddenException(
            'Bạn không còn là thành viên của dự án này. '
            . 'Vui lòng tải lại trang hoặc đăng nhập lại.'
        );
    }
    return $role;
}

function requireProjectRoleInTransaction(
    PgSql\Connection $connection,
    int $projectId,
    int $userId,
    array $allowed,
    string $message = 'Bạn không có quyền thực hiện thao tác này.'
): string {
    $role = requireProjectMembershipInTransaction($connection, $projectId, $userId, $message);
    if (!hasProjectRole($role, $allowed)) {
        throw new ForbiddenException($message . ' Vui lòng tải lại trang.');
    }
    return $role;
}

function requireAnyProjectAdminInTransaction(
    PgSql\Connection $connection,
    int $userId,
    string $message = 'Chỉ admin mới được thực hiện thao tác này.'
): void {
    $rows = queryAll(
        $connection,
        "SELECT project_id FROM project_members
         WHERE user_id=\$1 AND role='admin'
         ORDER BY project_id
         FOR UPDATE",
        [(string)$userId]
    );
    if ($rows === []) {
        throw new ForbiddenException($message . ' Vui lòng tải lại trang.');
    }
}

function canCreateIssue(?string $role): bool
{
    return hasProjectRole($role, ['developer', 'tester', 'release_manager']);
}

function canEditIssue(?string $role, int $userId, ?int $reporterId, ?int $assigneeId): bool
{
    if (hasProjectRole($role, ['developer', 'release_manager'])) {
        return true;
    }
    if ($role === 'tester') {
        return $reporterId === $userId || $assigneeId === $userId;
    }
    return false;
}

function canTestIssue(?string $role): bool
{
    return hasProjectRole($role, ['tester', 'release_manager']);
}

function canManageRelease(?string $role): bool
{
    return hasProjectRole($role, ['release_manager']);
}

function canManageProject(?string $role): bool
{
    return $role === 'admin';
}

function canViewAudit(?string $role): bool
{
    return hasProjectRole($role, ['release_manager']);
}

function isReleaseManager(PgSql\Connection $connection, int $projectId, array $user): bool
{
    return canManageRelease(projectRole($connection, $projectId, $user));
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function isCsrfValid(?string $token = null): bool
{
    $stored = $_SESSION['csrf'] ?? '';
    $sent = $token ?? ($_POST['csrf'] ?? '');
    return is_string($stored) && $stored !== ''
        && is_string($sent) && $sent !== ''
        && hash_equals($stored, $sent);
}

function checkCsrf(): void
{
    if (!isCsrfValid()) {
        http_response_code(419);
        exit('CSRF token không hợp lệ.');
    }
}

function audit(
    PgSql\Connection $connection,
    int $projectId,
    int $userId,
    string $action,
    string $type,
    int $id,
    array $details = []
): void {
    $encoded = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        $encoded = '{}';
    }
    queryParams(
        $connection,
        'INSERT INTO audit_log(project_id,user_id,action,entity_type,entity_id,details)
         VALUES($1,$2,$3,$4,$5,$6)',
        [(string)$projectId, (string)$userId, $action, $type, (string)$id, $encoded]
    );
}

function authorizeReleaseTransition(PgSql\Connection $connection): void
{
    queryParams(
        $connection,
        "SELECT set_config('rt.release_transition','service',true)",
        []
    );
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consumeFlash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($messages) ? $messages : [];
}

function redirect(string $target, int $status = 303): never
{
    header('Location: ' . $target, true, $status);
    exit;
}

function pageStart(string $title): void
{
    $user = currentUser();
    $flash = consumeFlash();
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . ' · ReleaseTracker</title>'
        . '<link rel="stylesheet" href="/assets/css/tokens.css">'
        . '<link rel="stylesheet" href="/assets/css/app.css">'
        . '</head><body>'
        . '<header class="site-header"><strong>ReleaseTracker</strong>'
        . '<p>Theo dõi lỗi · Quản lý kế hoạch phát hành phần mềm</p></header>'
        . '<nav class="main-nav">'
        . '<a href="/">Tổng quan</a>'
        . '<a href="/projects.php">Dự án</a>'
        . '<a href="/issues.php">Lỗi &amp; công việc</a>'
        . '<a href="/dependencies.php">Phụ thuộc</a>'
        . '<a href="/releases.php">Phiên bản phát hành</a>'
        . '<a href="/audit.php">Lịch sử</a>';
    if ($user !== null) {
        echo '<span class="user-name">' . h($user['full_name']) . '</span>'
            . '<form method="post" action="/logout.php">'
            . '<input type="hidden" name="csrf" value="' . h(csrfToken()) . '">'
            . '<button class="link-button" type="submit">Đăng xuất</button></form>';
    } else {
        echo '<a class="login-link" href="/login.php">Đăng nhập</a>';
    }
    echo '</nav><main><h1>' . h($title) . '</h1>';
    foreach ($flash as $message) {
        $class = ($message['type'] ?? '') === 'error' ? 'error' : 'ok';
        echo '<p class="' . h($class) . '">' . nl2br(h($message['message'] ?? '')) . '</p>';
    }
}

function pageEnd(): void
{
    echo '</main><footer class="site-footer">ReleaseTracker · Dữ liệu mẫu phục vụ học tập · PHP + PostgreSQL</footer>'
        . '</body></html>';
}
