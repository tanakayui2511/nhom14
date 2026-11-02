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

pageStart('Đăng nhập');
if ($error !== '') {
    echo '<p class="error">' . h($error) . '</p>';
}
?>
<section class="panel">
    <p>Đăng nhập để thao tác trong dự án được phân công.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <label class="field">Tên đăng nhập
            <input name="username" required maxlength="60" autocomplete="username" value="<?= h($username) ?>">
        </label>
        <label class="field">Mật khẩu
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button>Đăng nhập</button>
    </form>
    <p class="muted">Tài khoản demo: admin_a, dev_a, tester_a, release_a, dev_b · Mật khẩu: demo1234</p>
</section>
<?php pageEnd(); ?>
