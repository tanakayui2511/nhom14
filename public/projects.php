<?php

declare(strict_types=1);

require __DIR__ . '/../src/App.php';
require __DIR__ . '/../src/ProjectService.php';

$user = requireLogin();
$connection = db();
$roleOptions = ['developer', 'tester', 'release_manager', 'admin'];
$error = '';

$isAnyAdmin = queryOne(
    $connection,
    "SELECT 1 FROM project_members WHERE user_id=\$1 AND role='admin'",
    [(string)$user['id']]
) !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            $projectId = ProjectService::create($connection, (int)$user['id'], $_POST);
            flash('ok', 'Đã tạo dự án.');
            redirect('/projects.php?project_id=' . $projectId);
        }

        $projectId = (int)($_POST['project_id'] ?? 0);
        if ($projectId <= 0) {
            throw new ValidationException('Dự án không hợp lệ.');
        }
        requireProjectAccess($connection, $projectId, $user);
        requireProjectRole($connection, $projectId, $user, ['admin']);

        if ($action === 'update') {
            ProjectService::update($connection, $projectId, (int)$user['id'], $_POST);
            flash('ok', 'Đã cập nhật dự án.');
        } elseif ($action === 'add_member' || $action === 'update_member') {
            $username = postString($_POST, 'username', 60, true, 'Tên đăng nhập');
            $newRole = postEnum($_POST, 'member_role', $roleOptions, 'Vai trò thành viên');
            ProjectService::setMemberRole(
                $connection,
                $projectId,
                (int)$user['id'],
                $username,
                $newRole
            );
            flash('ok', 'Đã lưu thành viên.');
        } elseif ($action === 'remove_member') {
            $memberId = postInt($_POST, 'user_id', 1, PHP_INT_MAX, 'Thành viên');
            ProjectService::removeMember($connection, $projectId, (int)$user['id'], $memberId);
            flash('ok', 'Đã gỡ thành viên.');
        } else {
            throw new ValidationException('Thao tác không hợp lệ.');
        }
        redirect('/projects.php?project_id=' . $projectId);
    } catch (Throwable $exception) {
        $error = userMessage($exception);
    }
}

$projects = queryAll(
    $connection,
    'SELECT p.id,p.name,p.description,pm.role,
            (SELECT count(*) FROM project_members x WHERE x.project_id=p.id) AS member_count,
            (SELECT count(*) FROM issues i WHERE i.project_id=p.id) AS issue_count
     FROM projects p
     JOIN project_members pm ON pm.project_id=p.id
     WHERE pm.user_id=$1
     ORDER BY p.id',
    [(string)$user['id']]
);
$projectId = (int)($_REQUEST['project_id'] ?? 0);
if ($projectId === 0 && $projects !== []) {
    $projectId = (int)$projects[0]['id'];
}
$selectedProject = null;
$members = [];
$canAdmin = false;
if ($projectId > 0) {
    requireProjectAccess($connection, $projectId, $user);
    $selectedProject = queryOne(
        $connection,
        'SELECT p.*,
                (SELECT count(*) FROM project_members x WHERE x.project_id=p.id) AS member_count,
                (SELECT count(*) FROM issues i WHERE i.project_id=p.id) AS issue_count
         FROM projects p WHERE p.id=$1',
        [(string)$projectId]
    );
    $canAdmin = canManageProject(projectRole($connection, $projectId, $user));
    $members = queryAll(
        $connection,
        'SELECT u.id,u.username,u.full_name,pm.role
         FROM project_members pm JOIN users u ON u.id=pm.user_id
         WHERE pm.project_id=$1
         ORDER BY CASE pm.role WHEN \'admin\' THEN 0 WHEN \'release_manager\' THEN 1 WHEN \'tester\' THEN 2 ELSE 3 END,u.full_name',
        [(string)$projectId]
    );
}

pageStart('Dự án');
if ($error !== '') {
    echo '<p class="error">' . nl2br(h($error)) . '</p>';
}
?>
<section class="panel">
    <h2>Dự án của bạn</h2>
    <?php if ($projects === []): ?>
        <p class="muted">Bạn chưa thuộc dự án nào.</p>
    <?php else: ?>
        <form method="get">
            <label class="field">Chọn dự án
                <select name="project_id" onchange="this.form.submit()">
                    <?php foreach ($projects as $project): ?>
                        <option value="<?= (int)$project['id'] ?>" <?= $projectId === (int)$project['id'] ? 'selected' : '' ?>>
                            <?= h($project['name']) ?> (<?= h($project['role']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    <?php endif; ?>
</section>

<?php if ($isAnyAdmin): ?>
    <section class="panel">
        <h2>Tạo dự án</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="create">
            <label class="field">Tên dự án<input name="name" required maxlength="160"></label>
            <label class="field">Mô tả<textarea name="description" maxlength="20000"></textarea></label>
            <button>Tạo dự án</button>
        </form>
    </section>
<?php endif; ?>

<?php if ($selectedProject !== null): ?>
    <section class="panel">
        <h2><?= h($selectedProject['name']) ?></h2>
        <p><?= nl2br(h($selectedProject['description'])) ?></p>
        <p class="muted"><?= (int)$selectedProject['member_count'] ?> thành viên · <?= (int)$selectedProject['issue_count'] ?> công việc</p>
        <?php if ($canAdmin): ?>
            <h3>Cập nhật thông tin</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <label class="field">Tên dự án<input name="name" required maxlength="160" value="<?= h($selectedProject['name']) ?>"></label>
                <label class="field">Mô tả<textarea name="description" maxlength="20000"><?= h($selectedProject['description']) ?></textarea></label>
                <button>Lưu thông tin</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Thành viên</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Họ tên</th><th>Tên đăng nhập</th><th>Vai trò</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($members as $member): ?>
                    <tr>
                        <td><?= h($member['full_name']) ?></td>
                        <td><?= h($member['username']) ?></td>
                        <td><span class="pill"><?= h($member['role']) ?></span></td>
                        <td>
                            <?php if ($canAdmin): ?>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="update_member">
                                    <input type="hidden" name="project_id" value="<?= $projectId ?>">
                                    <input type="hidden" name="username" value="<?= h($member['username']) ?>">
                                    <select name="member_role">
                                        <?php foreach ($roleOptions as $option): ?>
                                            <option value="<?= h($option) ?>" <?= $member['role'] === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="secondary" type="submit">Đổi vai trò</button>
                                </form>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="remove_member">
                                    <input type="hidden" name="project_id" value="<?= $projectId ?>">
                                    <input type="hidden" name="user_id" value="<?= (int)$member['id'] ?>">
                                    <button class="danger" type="submit">Gỡ</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canAdmin): ?>
            <h3>Thêm / cập nhật thành viên</h3>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_member">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <label class="field">Tên đăng nhập<input name="username" required maxlength="60" placeholder="dev_c"></label>
                <label class="field">Vai trò
                    <select name="member_role">
                        <?php foreach ($roleOptions as $option): ?>
                            <option value="<?= h($option) ?>"><?= h($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button>Lưu thành viên</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php pageEnd(); ?>
