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

function appIcon(string $name): string
{
    $paths = [
        'overview' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'projects' => '<path d="M4 7h16v12H4z"/><path d="M8 7V5h5l2 2"/>',
        'issues' => '<path d="M12 3 2.8 19h18.4L12 3Z"/><path d="M12 9v4"/><path d="M12 16.5h.01"/>',
        'dependencies' => '<circle cx="6" cy="6" r="3"/><circle cx="18" cy="18" r="3"/><path d="m8.2 8.2 7.6 7.6"/><path d="M15 6h3a3 3 0 0 1 3 3v3"/><path d="M9 18H6a3 3 0 0 1-3-3v-3"/>',
        'releases' => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/><path d="M5 4h4"/><path d="M15 4h4"/>',
        'audit' => '<path d="M5 4h14v16H5z"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/>',
        'portfolio' => '<path d="M4 7h16v12H4z"/><path d="M9 7V5h6v2"/><path d="M4 12h16"/><path d="M10 12v2h4v-2"/>',
    ];
    $content = $paths[$name] ?? $paths['overview'];
    return '<span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24">' . $content . '</svg></span>';
}

function pageStart(string $title): void
{
    $user = currentUser();
    $flash = consumeFlash();
    $pageName = pathinfo((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'), PATHINFO_FILENAME);
    $isLoginPage = strtolower($pageName) === 'login';
    $pageClass = preg_replace('/[^a-z0-9_-]/i', '', $pageName) ?: 'index';

    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light dark">'
        . '<title>' . h($title) . ' | ReleaseTracker</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">'
        . '<script>try{const t=localStorage.getItem("rt-theme")||(matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.dataset.theme=t;document.documentElement.style.colorScheme=t}catch(e){}</script>'
        . '<link rel="stylesheet" href="/assets/css/tokens.css?v=' . filemtime(__DIR__ . '/../public/assets/css/tokens.css') . '">'
        . '<link rel="stylesheet" href="/assets/css/app.css?v=' . filemtime(__DIR__ . '/../public/assets/css/app.css') . '">'
        . '</head><body class="page-' . h($pageClass) . ($isLoginPage ? ' login-page' : '') . '">';

    if ($isLoginPage) {
        echo '<header class="login-header"><a class="brand" href="/" aria-label="ReleaseTracker">'
            . '<span class="brand-mark" aria-hidden="true">R<span>.</span></span>'
            . '<span class="brand-copy"><strong>ReleaseTracker</strong><small>Ch&#7845;t l&#432;&#7907;ng ph&#225;t h&#224;nh minh b&#7841;ch.</small></span></a>'
            . '<button class="theme-toggle" type="button" data-theme-toggle aria-pressed="false"><span class="theme-icon" aria-hidden="true"></span><span data-theme-label>Giao di&#7879;n t&#7889;i</span></button></header>'
            . '<main class="login-main"><div class="login-heading"><span class="eyebrow">CH&#192;O M&#7914;NG TR&#7902; L&#7840;I</span><h1>' . h($title) . '</h1></div>';
    } else {
        echo '<div class="app-shell"><button class="sidebar-scrim" type="button" data-nav-close aria-label="&#272;&#243;ng menu"></button><aside class="app-sidebar" id="app-sidebar">'
            . '<a class="brand" href="/" aria-label="ReleaseTracker, trang t&#7893;ng quan">'
            . '<span class="brand-mark" aria-hidden="true">R<span>.</span></span>'
            . '<span class="brand-copy"><strong>ReleaseTracker</strong><small>V&#7852;N H&#192;NH PH&#193;T H&#192;NH</small></span></a>'
            . '<button class="sidebar-close" type="button" data-nav-close aria-label="&#272;&#243;ng menu"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>'
            . '<span class="nav-caption">KH&#212;NG GIAN L&#192;M VI&#7878;C</span><nav class="main-nav" aria-label="&#272;i&#7873;u h&#432;&#7899;ng ch&#237;nh">'
            . '<a href="/" data-module="dashboard">' . appIcon('overview') . '<span>T&#7893;ng quan</span></a>'
            . '<a href="/projects.php" data-module="projects">' . appIcon('projects') . '<span>D&#7921; &#225;n</span></a>'
            . '<a href="/issues.php" data-module="issues">' . appIcon('issues') . '<span>L&#7895;i &amp; c&#244;ng vi&#7879;c</span></a>'
            . '<a href="/dependencies.php" data-module="dependencies">' . appIcon('dependencies') . '<span>Ph&#7909; thu&#7897;c</span></a>'
            . '<a href="/releases.php" data-module="releases">' . appIcon('releases') . '<span>Phi&#234;n b&#7843;n ph&#225;t h&#224;nh</span></a>'
            . '<a href="/audit.php" data-module="audit">' . appIcon('audit') . '<span>L&#7883;ch s&#7917;</span></a>'
            . '<a href="/portfolio.php" data-module="portfolio">' . appIcon('portfolio') . '<span>Portfolio</span></a></nav>'
            . '<div class="sidebar-bottom"><span class="workspace-dot"></span><span>Release workspace</span></div></aside>'
            . '<div class="app-content"><header class="topbar"><button class="menu-toggle" type="button" data-nav-open aria-controls="app-sidebar" aria-expanded="false" aria-label="M&#7903; menu"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="topbar-context"><span class="eyebrow">V&#7852;N H&#192;NH D&#7920; &#193;N</span><span class="topbar-title">' . h($title) . '</span></div>';
        if ($user !== null) {
            $initial = function_exists('mb_substr') ? mb_substr((string)$user['full_name'], 0, 1, 'UTF-8') : substr((string)$user['full_name'], 0, 1);
            $initial = function_exists('mb_strtoupper') ? mb_strtoupper($initial, 'UTF-8') : strtoupper($initial);
            echo '<div class="topbar-user"><span class="avatar" data-avatar-name="' . h($user['full_name']) . '">' . h($initial) . '</span><span>' . h($user['full_name']) . '</span>'
                . '<form method="post" action="/logout.php"><input type="hidden" name="csrf" value="' . h(csrfToken()) . '">'
                . '<button class="link-button" type="submit">&#272;&#259;ng xu&#7845;t</button></form></div>';
        }
        echo '<button class="theme-toggle" type="button" data-theme-toggle aria-pressed="false"><span class="theme-icon" aria-hidden="true"></span><span data-theme-label>Giao di&#7879;n t&#7889;i</span></button></header><main class="app-main"><div class="page-heading"><span class="eyebrow">RELEASETRACKER / ' . h(strtoupper($pageClass)) . '</span><h1>' . h($title) . '</h1></div>';
    }
    if ($pageClass === 'releases') {
        echo '<ol class="release-flow" aria-label="Ti&#7871;n tr&#236;nh ph&#225;t h&#224;nh">'
            . '<li data-stage="planned">D&#7921; ki&#7871;n</li><li data-stage="ready">S&#7861;n s&#224;ng</li>'
            . '<li data-stage="released">&#272;&#227; ph&#225;t h&#224;nh</li></ol>';
    }
    foreach ($flash as $message) {
        $class = ($message['type'] ?? '') === 'error' ? 'error' : 'ok';
        echo '<p class="' . h($class) . '">' . nl2br(h($message['message'] ?? '')) . '</p>';
    }
}

function pageEnd(): void
{
    $isLoginPage = strtolower(pathinfo((string)($_SERVER['SCRIPT_NAME'] ?? ''), PATHINFO_FILENAME)) === 'login';
    if ($isLoginPage) {
        echo '</main><footer class="site-footer login-footer">ReleaseTracker | D&#7919; li&#7879;u m&#7851;u ph&#7909;c v&#7909; h&#7885;c t&#7853;p | PHP + PostgreSQL</footer>';
    } else {
        echo '</main><footer class="site-footer">ReleaseTracker <span>Release quality, made visible.</span></footer></div></div>';
    }
    echo '<script src="/assets/js/app.js?v=' . filemtime(__DIR__ . '/../public/assets/js/app.js') . '" defer></script></body></html>'; 
}
