<?php

declare(strict_types=1);

require __DIR__ . '/../src/App.php';

if (currentUser() !== null) {
    redirect('/');
}

$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $error = 'Vui lòng nhập tên đăng nhập và mật khẩu.';
    } else {
        try {
            $connection = db();
            $account = queryOne(
                $connection,
                'SELECT id,username,full_name,password_hash FROM users WHERE username=$1',
                [$username]
            );
            if ($account !== null && password_verify($password, (string)$account['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id' => (int)$account['id'],
                    'username' => (string)$account['username'],
                    'full_name' => (string)$account['full_name'],
                ];
                redirect('/');
            }
            $error = 'Tên đăng nhập hoặc mật khẩu không đúng.';
        } catch (Throwable $exception) {
            error_log('Login database error: ' . $exception->getMessage());
            $error = 'Không kết nối được cơ sở dữ liệu. Hãy kiểm tra cấu hình PostgreSQL.';
        }
    }
}

$demoUsers = ['admin_a', 'dev_a', 'tester_a', 'release_a', 'dev_b'];

pageStart('Đăng nhập');
?>
<div class="auth-wrap">
<div class="auth-logo"><?= brandLogoSvg() ?></div>
<section class="auth-card">
    <h1>Đăng nhập</h1>
    <p class="auth-intro">Đăng nhập để thao tác trong dự án được phân công.</p>
    <?php if ($error !== ''): ?>
        <p class="error" role="alert"><?= h($error) ?></p>
    <?php endif; ?>
    <form method="post" id="login-form">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <label class="field" for="login-username">Tên đăng nhập</label>
        <input class="auth-input" id="login-username" name="username" required maxlength="60"
               autocomplete="username" value="<?= h($username) ?>" autofocus>
        <label class="field" for="login-password">Mật khẩu</label>
        <input class="auth-input" id="login-password" type="password" name="password" required
               autocomplete="current-password">
        <button type="submit" class="auth-submit">Đăng nhập</button>
    </form>
    <div class="demo-box">
        <p>Tài khoản demo — bấm để điền sẵn (mật khẩu: <b>demo1234</b>)</p>
        <div class="demo-list">
            <?php foreach ($demoUsers as $demoUser): ?>
                <button type="button" class="secondary" data-demo-user="<?= h($demoUser) ?>"><?= h($demoUser) ?></button>
            <?php endforeach; ?>
        </div>
    </div>
</section>
</div>
<script>
// Demo buttons fill both fields; the user then presses "Đăng nhập".
document.querySelectorAll('[data-demo-user]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById('login-username').value = button.getAttribute('data-demo-user');
        var password = document.getElementById('login-password');
        password.value = 'demo1234';
        password.focus();
    });
});
</script>
<?php pageEnd(); ?>
