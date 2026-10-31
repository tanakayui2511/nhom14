<?php

declare(strict_types=1);

require __DIR__ . '/../src/DependencyService.php';

$user = requireLogin();
$connection = db();
$projects = queryAll(
    $connection,
    'SELECT p.id,p.name,pm.role
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
$role = null;
if ($projectId > 0) {
    requireProjectAccess($connection, $projectId, $user);
    $role = projectRole($connection, $projectId, $user);
}
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $projectId = (int)($_POST['project_id'] ?? 0);
    if ($projectId <= 0) {
        $error = 'Dự án không hợp lệ.';
    } else {
        requireProjectAccess($connection, $projectId, $user);
        $role = projectRole($connection, $projectId, $user);
        try {
            if (!hasProjectRole($role, ['developer', 'release_manager'])) {
                throw new ForbiddenException('Bạn không có quyền thay đổi quan hệ phụ thuộc.');
            }
            $action = (string)($_POST['action'] ?? 'add');
            $issueId = postInt($_POST, 'issue_id', 1, PHP_INT_MAX, 'Công việc');
            $dependsOnId = postInt($_POST, 'depends_on_id', 1, PHP_INT_MAX, 'Công việc tiên quyết');
            if ($action === 'remove') {
                DependencyService::remove($connection, $projectId, $issueId, $dependsOnId, (int)$user['id']);
                flash('ok', 'Đã gỡ quan hệ phụ thuộc.');
            } else {
                DependencyService::add($connection, $projectId, $issueId, $dependsOnId, (int)$user['id']);
                flash('ok', 'Đã thêm quan hệ phụ thuộc.');
            }
            redirect('/dependencies.php?project_id=' . $projectId);
        } catch (Throwable $exception) {
            $error = userMessage($exception);
        }
    }
}

$issues = $projectId > 0
    ? queryAll(
        $connection,
        'SELECT id,title,status FROM issues WHERE project_id=$1 ORDER BY id',
        [(string)$projectId]
    )
    : [];
$dependencies = $projectId > 0 ? DependencyService::listForProject($connection, $projectId) : [];
$blocked = $projectId > 0 ? DependencyService::blockedForProject($connection, $projectId) : [];
$cycle = $projectId > 0 ? DependencyService::projectCycle($connection, $projectId) : null;
$canChange = hasProjectRole($role, ['developer', 'release_manager']);

pageStart('Quan hệ phụ thuộc');
if ($error !== '') {
    echo '<p class="error">' . nl2br(h($error)) . '</p>';
}
?>
<?php if ($projects === []): ?>
    <section class="panel"><p>Bạn chưa thuộc dự án nào.</p></section>
<?php else: ?>
    <section class="panel">
        <form method="get">
            <label class="field">Dự án
                <select name="project_id" onchange="this.form.submit()">
                    <?php foreach ($projects as $project): ?>
                        <option value="<?= (int)$project['id'] ?>" <?= $projectId === (int)$project['id'] ? 'selected' : '' ?>>
                            <?= h($project['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <p class="muted">A phụ thuộc B nghĩa là B cần hoàn thành trước khi A có thể tiếp tục.</p>
        <?php if ($cycle !== null): ?>
            <p class="error">Phát hiện chu trình:
                <?= h(implode(' -> ', array_map(static fn(int $id): string => '#' . $id, $cycle))) ?>
            </p>
        <?php else: ?>
            <p class="ok">Đồ thị phụ thuộc hiện tại không có chu trình.</p>
        <?php endif; ?>
    </section>

    <?php if ($canChange && $issues !== []): ?>
        <section class="panel">
            <h2>Thêm quan hệ phụ thuộc</h2>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <label class="field">Công việc phụ thuộc
                    <select name="issue_id" required>
                        <?php foreach ($issues as $issue): ?>
                            <option value="<?= (int)$issue['id'] ?>">#<?= (int)$issue['id'] ?> · <?= h($issue['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">Phải hoàn thành trước (B)
                    <select name="depends_on_id" required>
                        <?php foreach ($issues as $issue): ?>
                            <option value="<?= (int)$issue['id'] ?>">#<?= (int)$issue['id'] ?> · <?= h($issue['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button>Kiểm tra và thêm</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Đang chặn tiến độ</h2>
        <?php if ($blocked === []): ?>
            <p>Không có quan hệ nào đang chặn theo trạng thái hiện tại.</p>
        <?php else: ?>
            <ul class="dependency-map">
                <?php foreach ($blocked as $row): ?>
                    <li class="dependency-edge is-blocked">
                        <span class="dependency-node" data-id="#<?= (int)$row['id'] ?>"><span><?= h($row['title']) ?></span></span>
                        <span class="dependency-arrow" aria-label="đang chờ"></span>
                        <span class="dependency-node" data-id="#<?= (int)$row['blocked_by_id'] ?>"><span><?= h($row['blocked_by']) ?></span></span>
                        <span class="pill"><?= h($row['dependency_status']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Quan hệ đã khai báo (<?= count($dependencies) ?>)</h2>
        <?php if ($dependencies === []): ?>
            <p class="muted">Chưa có quan hệ phụ thuộc.</p>
        <?php else: ?>
            <ul class="dependency-map">
                <?php foreach ($dependencies as $dependency): ?>
                    <li class="dependency-edge">
                        <span class="dependency-node" data-id="#<?= (int)$dependency['issue_id'] ?>"><span><?= h($dependency['issue_title']) ?></span></span>
                        <span class="dependency-arrow" aria-label="phụ thuộc"></span>
                        <span class="dependency-node" data-id="#<?= (int)$dependency['depends_on_id'] ?>"><span><?= h($dependency['dependency_title']) ?></span></span>
                        <span class="pill"><?= h($dependency['dependency_status']) ?></span>
                        <?php if ($canChange): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                                <input type="hidden" name="issue_id" value="<?= (int)$dependency['issue_id'] ?>">
                                <input type="hidden" name="depends_on_id" value="<?= (int)$dependency['depends_on_id'] ?>">
                                <button class="danger" type="submit">Gỡ</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php pageEnd(); ?>
