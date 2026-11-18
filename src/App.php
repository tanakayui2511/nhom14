<?php

declare(strict_types=1);

/*
 * Danh sách giá trị hợp lệ cho các cột enum. Đây là nguồn duy nhất: kiểm tra
 * dữ liệu (postEnum), form hiển thị và test đều dùng lại các hằng này để PHP
 * từ chối giá trị sai bằng thông báo tiếng Việt trước khi CSDL báo lỗi CHECK.
 */
const ISSUE_PRIORITIES = ['blocker', 'critical', 'high', 'medium', 'low'];
const ISSUE_STATUSES = ['open', 'in_progress', 'resolved', 'retest', 'verified', 'closed'];
const ISSUE_TYPES = ['bug', 'task', 'feature'];
const TEST_RESULTS = ['passed', 'failed', 'blocked'];
const PROJECT_ROLES = ['developer', 'tester', 'release_manager', 'admin'];
const RELEASE_STATUSES = ['planned', 'ready', 'released'];

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Validation.php';
require_once __DIR__ . '/Labels.php';

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

/** Brand logo (git-branch mark), stroke = currentColor. */
function brandLogoSvg(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . '<circle cx="6" cy="6" r="2.2"/><circle cx="6" cy="18" r="2.2"/><path d="M6 8.2v7.6"/>'
        . '<path d="M6 13h5a4 4 0 0 0 4-4V7.6"/><circle cx="15" cy="5.2" r="2.4" fill="currentColor" stroke="none"/></svg>';
}

function pageStart(string $title): void
{
    $user = currentUser();
    $flash = consumeFlash();
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    // The login page uses a centered card layout and prints its own heading.
    $isAuthPage = $script === 'login.php';
    $activeScript = $script === 'release-notes.php' ? 'releases.php' : $script;
    $subtitles = [
        'index.php' => 'Tổng hợp dự án, công việc và phiên bản bạn đang tham gia.',
        'projects.php' => 'Quản lý dự án, thành viên và vai trò trong từng dự án.',
        'issues.php' => 'Theo dõi lỗi, phân công, bình luận và kết quả kiểm thử.',
        'dependencies.php' => 'Quan hệ tiên quyết giữa các công việc và những việc đang bị chặn.',
        'releases.php' => 'Lập kế hoạch phiên bản, kiểm tra điều kiện và chốt phát hành.',
        'release-notes.php' => 'Ghi chú phát hành được tạo từ snapshot đã chốt.',
        'audit.php' => 'Nhật ký thao tác của dự án: chỉ ghi thêm, không sửa.',
    ];
    // Nav: [href, label, inline SVG paths on a 24px grid].
    $navItems = [
        'index.php' => ['/', 'Tổng quan', '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/><path d="M10 20v-5h4v5"/>'],
        'projects.php' => ['/projects.php', 'Dự án', '<path d="M3 6.5A1.5 1.5 0 0 1 4.5 5H9l2 2h8.5A1.5 1.5 0 0 1 21 8.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 17.5z"/>'],
        'issues.php' => ['/issues.php', 'Lỗi & công việc', '<rect x="7" y="8" width="10" height="12" rx="5"/><path d="M12 8V5M9 5.5 7.5 4M15 5.5 16.5 4M7 13H3.5M20.5 13H17M7.5 17.5 5 19.5M16.5 17.5 19 19.5"/>'],
        'dependencies.php' => ['/dependencies.php', 'Phụ thuộc', '<circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="12" cy="18" r="2.5"/><path d="M7.4 8.3 10.9 15.6M16.6 8.3 13.1 15.6"/>'],
        'releases.php' => ['/releases.php', 'Phiên bản phát hành', '<path d="M3 12V4.5A1.5 1.5 0 0 1 4.5 3H12l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.5"/>'],
        'audit.php' => ['/audit.php', 'Lịch sử', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'],
    ];
    $accents = ['indigo' => 'Indigo', 'ocean' => 'Xanh biển', 'forest' => 'Xanh lá', 'sunset' => 'Cam', 'rose' => 'Hồng'];

    // Runs before the CSS so the page never flashes the wrong theme or accent.
    $boot = '<script>(function(){var d=document.documentElement,t=null,a=null;d.classList.add("js");'
        . 'try{t=localStorage.getItem("rt-theme");a=localStorage.getItem("rt-accent")}catch(e){}'
        . 'if(t!=="light"&&t!=="dark"){t=window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"}'
        . 'if(!/^(indigo|ocean|forest|sunset|rose)$/.test(a||"")){a="indigo"}'
        . 'd.setAttribute("data-theme",t);d.setAttribute("data-accent",a)})();</script>';

    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light dark">'
        . '<title>' . h($title) . ' · ReleaseTracker</title>'
        . $boot
        . '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&amp;display=swap">'
        . '<link rel="stylesheet" href="/assets/css/tokens.css?v=4">'
        . '<link rel="stylesheet" href="/assets/css/app.css?v=4">'
        . '<script src="/assets/js/theme.js?v=4" defer></script>'
        . '</head><body' . ($isAuthPage ? ' class="auth-page"' : '') . '>'
        . '<a class="skip-link" href="#main-content">Bỏ qua điều hướng</a>'
        . '<header class="site-header"><div class="header-inner">'
        . '<a class="brand" href="/" aria-label="ReleaseTracker – Tổng quan">'
        . '<span class="brand-mark">' . brandLogoSvg() . '</span>'
        . '<span class="brand-text"><strong>Release<span class="brand-em">Tracker</span></strong>'
        . '<span class="brand-tagline">Theo dõi lỗi · Quản lý kế hoạch phát hành</span></span></a>';
    if (!$isAuthPage) {
        echo '<nav class="main-nav" aria-label="Điều hướng chính">';
        foreach ($navItems as $file => [$href, $label, $paths]) {
            $current = $file === $activeScript ? ' aria-current="page"' : '';
            echo '<a href="' . h($href) . '"' . $current . '>'
                . '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
                . $paths . '</svg><span>' . h($label) . '</span></a>';
        }
        echo '</nav>';
    }
    echo '<div class="header-actions"><div class="accent-picker" role="group" aria-label="Màu chủ đạo">';
    foreach ($accents as $key => $label) {
        echo '<button type="button" class="accent-dot accent-' . h($key) . '" data-accent-option="' . h($key) . '"'
            . ' aria-pressed="' . ($key === 'indigo' ? 'true' : 'false') . '" aria-label="' . h('Màu ' . $label) . '" title="' . h($label) . '"></button>';
    }
    echo '</div>'
        . '<button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Chuyển sang chế độ tối">'
        . '<svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20.5 14.2A8.5 8.5 0 1 1 9.8 3.5a6.8 6.8 0 0 0 10.7 10.7z"/></svg>'
        . '<svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/></svg>'
        . '<span class="theme-label" data-theme-label>Tối</span></button>';
    if (!$isAuthPage) {
        if ($user !== null) {
            // Avatar: initials of first + last word, color picked from a hash of the name.
            $fullName = trim((string)$user['full_name']);
            $words = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $initials = '';
            foreach (count($words) > 1 ? [$words[0], $words[count($words) - 1]] : $words as $word) {
                if (preg_match('/^\X/u', $word, $match) === 1) {
                    $initials .= $match[0];
                }
            }
            $initials = function_exists('mb_strtoupper') ? mb_strtoupper($initials, 'UTF-8') : strtoupper($initials);
            $tones = ['indigo', 'sky', 'emerald', 'amber', 'rose', 'violet'];
            $tone = $tones[crc32($fullName) % count($tones)];
            echo '<span class="user-chip"><span class="avatar tone-' . h($tone) . '" aria-hidden="true">' . h($initials !== '' ? $initials : '?') . '</span>'
                . '<span class="user-name">' . h($user['full_name']) . '</span></span>'
                . '<form method="post" action="/logout.php">'
                . '<input type="hidden" name="csrf" value="' . h(csrfToken()) . '">'
                . '<button class="link-button" type="submit">Đăng xuất</button></form>';
        } else {
            echo '<a class="login-link" href="/login.php">Đăng nhập</a>';
        }
    }
    echo '</div></div></header><main id="main-content" tabindex="-1">';
    if (!$isAuthPage) {
        $subtitle = $subtitles[$script] ?? '';
        echo '<div class="page-head"><h1>' . h($title) . '</h1>'
            . ($subtitle !== '' ? '<p class="page-subtitle">' . h($subtitle) . '</p>' : '') . '</div>';
    }
    foreach ($flash as $message) {
        $isError = ($message['type'] ?? '') === 'error';
        echo '<p class="' . ($isError ? 'error' : 'ok') . '" role="' . ($isError ? 'alert' : 'status') . '">'
            . nl2br(h($message['message'] ?? '')) . '</p>';
    }
}

function pageEnd(): void
{
    echo '</main><footer class="site-footer">ReleaseTracker · Dữ liệu mẫu phục vụ học tập · PHP + PostgreSQL</footer>'
        . '</body></html>';
}
