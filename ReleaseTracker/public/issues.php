<?php

declare(strict_types=1);

require __DIR__ . '/../src/IssueService.php';

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
$selected = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $projectId = (int)($_POST['project_id'] ?? 0);
    if ($projectId <= 0) {
        $error = 'Dự án không hợp lệ.';
    } else {
        requireProjectAccess($connection, $projectId, $user);
        $role = projectRole($connection, $projectId, $user);
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'create') {
                $id = IssueService::create($connection, $projectId, (int)$user['id'], $role, $_POST);
                flash('ok', 'Đã tạo công việc #' . $id . '.');
                redirect('/issues.php?project_id=' . $projectId . '&id=' . $id);
            }
            if ($action === 'update') {
                $id = postInt($_POST, 'id', 1, PHP_INT_MAX, 'ID công việc');
                $baseVersion = postInt($_POST, 'version', 1, PHP_INT_MAX, 'Phiên bản dữ liệu');
                $newVersion = IssueService::update(
                    $connection,
                    $projectId,
                    (int)$user['id'],
                    $role,
                    $id,
                    $baseVersion,
                    $_POST
                );
                flash('ok', 'Đã cập nhật công việc #' . $id . ' lên phiên bản ' . $newVersion . '.');
                redirect('/issues.php?project_id=' . $projectId . '&id=' . $id);
            }
            if ($action === 'comment') {
                $id = postInt($_POST, 'id', 1, PHP_INT_MAX, 'ID công việc');
                IssueService::addComment($connection, $projectId, (int)$user['id'], $role, $id, $_POST);
                flash('ok', 'Đã thêm bình luận.');
                redirect('/issues.php?project_id=' . $projectId . '&id=' . $id);
            }
            if ($action === 'test') {
                $id = postInt($_POST, 'id', 1, PHP_INT_MAX, 'ID công việc');
                $newVersion = IssueService::recordTest(
                    $connection,
                    $projectId,
                    (int)$user['id'],
                    $role,
                    $id,
                    $_POST
                );
                flash('ok', 'Đã lưu kết quả kiểm thử; công việc ở phiên bản ' . $newVersion . '.');
                redirect('/issues.php?project_id=' . $projectId . '&id=' . $id);
            }
            throw new ValidationException('Thao tác không hợp lệ.');
        } catch (Throwable $exception) {
            $error = userMessage($exception);
            $selected = (int)($_POST['id'] ?? $selected);
        }
    }
}

$members = $projectId > 0
    ? queryAll(
        $connection,
        'SELECT u.id,u.full_name
         FROM users u
         JOIN project_members pm ON pm.user_id=u.id
         WHERE pm.project_id=$1
         ORDER BY u.full_name',
        [(string)$projectId]
    )
    : [];
$issues = $projectId > 0
    ? queryAll(
        $connection,
        "SELECT i.*,a.full_name AS assignee
         FROM issues i
         LEFT JOIN users a ON a.id=i.assignee_id
         WHERE i.project_id=$1
         ORDER BY CASE i.priority
             WHEN 'blocker' THEN 1 WHEN 'critical' THEN 2 WHEN 'high' THEN 3
             WHEN 'medium' THEN 4 ELSE 5 END, i.id",
        [(string)$projectId]
    )
    : [];
$detail = null;
$comments = [];
$testResults = [];
if ($selected > 0 && $projectId > 0) {
    $detail = queryOne(
        $connection,
        'SELECT i.*,a.full_name AS assignee,r.full_name AS reporter
         FROM issues i
         LEFT JOIN users a ON a.id=i.assignee_id
         LEFT JOIN users r ON r.id=i.reporter_id
         WHERE i.id=$1 AND i.project_id=$2',
        [(string)$selected, (string)$projectId]
    );
    if ($detail !== null) {
        $comments = queryAll(
            $connection,
            'SELECT c.body,c.created_at,u.full_name
             FROM comments c JOIN users u ON u.id=c.user_id
             WHERE c.issue_id=$1 ORDER BY c.id DESC',
            [(string)$selected]
        );
        $testResults = queryAll(
            $connection,
            'SELECT t.*,u.full_name AS tester_name,(t.issue_version=i.version) AS is_current
             FROM test_results t
             JOIN users u ON u.id=t.tester_id
             JOIN issues i ON i.id=t.issue_id
             WHERE t.issue_id=$1 ORDER BY t.id DESC',
            [(string)$selected]
        );
    }
}

pageStart('Lỗi và công việc');
if ($error !== '') {
    echo '<p class="error">' . nl2br(h($error)) . '</p>';
}
?>
<?php if ($projects === []): ?>
    <section class="panel"><p>Bạn chưa thuộc dự án nào. Hãy liên hệ quản trị viên dự án.</p></section>
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
        <p class="muted">Vai trò của bạn trong dự án: <span class="pill"><?= h((string)$role) ?></span> ·
            <a href="/dependencies.php?project_id=<?= $projectId ?>">Xem quan hệ phụ thuộc</a>
        </p>
    </section>

    <?php if (canCreateIssue($role)): ?>
        <section class="panel">
            <h2>Tạo lỗi hoặc công việc</h2>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <label class="field">Tiêu đề<input name="title" required maxlength="200"></label>
                <label class="field">Mô tả<textarea name="description" rows="2" maxlength="20000"></textarea></label>
                <label class="field">Các bước tái hiện<textarea name="steps" rows="2" maxlength="20000"></textarea></label>
                <div class="grid">
                    <label class="field">Loại
                        <select name="issue_type">
                            <option value="bug">Lỗi</option>
                            <option value="task">Công việc</option>
                            <option value="feature">Tính năng</option>
                        </select>
                    </label>
                    <label class="field">Ưu tiên
                        <select name="priority">
                            <?php foreach (issuePriorities() as $value): ?>
                                <option value="<?= h($value) ?>" <?= $value === 'medium' ? 'selected' : '' ?>>
                                    <?= h(ucfirst($value)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">Phân công
                        <select name="assignee_id">
                            <option value="">Chưa phân công</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?= (int)$member['id'] ?>"><?= h($member['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <button>Tạo mới</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($detail !== null): ?>
        <?php
        $reporterId = $detail['reporter_id'] === null ? null : (int)$detail['reporter_id'];
        $assigneeId = $detail['assignee_id'] === null ? null : (int)$detail['assignee_id'];
        $canEdit = canEditIssue($role, (int)$user['id'], $reporterId, $assigneeId);
        ?>
        <section class="panel">
            <h2>Chi tiết #<?= (int)$detail['id'] ?> · <?= h($detail['title']) ?></h2>
            <p><?= nl2br(h($detail['description'])) ?></p>
            <p><b>Các bước tái hiện:</b> <?= nl2br(h($detail['reproduction_steps'])) ?></p>
            <p>
                Trạng thái: <span class="pill"><?= h($detail['status']) ?></span> ·
                Ưu tiên: <span class="pill"><?= h($detail['priority']) ?></span> ·
                Phiên bản dữ liệu: <?= (int)$detail['version'] ?> ·
                Người phụ trách: <?= h($detail['assignee'] ?? 'Chưa phân công') ?> ·
                Người báo cáo: <?= h($detail['reporter'] ?? 'Không xác định') ?>
            </p>
            <?php if ((string)$detail['commit_url'] !== ''): ?>
                <p>Commit / PR: <a href="<?= h($detail['commit_url']) ?>" rel="noopener noreferrer"><?= h($detail['commit_url']) ?></a></p>
            <?php endif; ?>

            <?php if ($canEdit): ?>
                <h3>Cập nhật công việc</h3>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                    <input type="hidden" name="project_id" value="<?= $projectId ?>">
                    <input type="hidden" name="version" value="<?= (int)$detail['version'] ?>">
                    <label class="field">Tiêu đề<input name="title" value="<?= h($detail['title']) ?>" required maxlength="200"></label>
                    <label class="field">Mô tả<textarea name="description" maxlength="20000"><?= h($detail['description']) ?></textarea></label>
                    <label class="field">Bước tái hiện<textarea name="steps" maxlength="20000"><?= h($detail['reproduction_steps']) ?></textarea></label>
                    <div class="grid">
                        <label class="field">Ưu tiên
                            <select name="priority">
                                <?php foreach (issuePriorities() as $value): ?>
                                    <option value="<?= h($value) ?>" <?= $detail['priority'] === $value ? 'selected' : '' ?>><?= h($value) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field">Trạng thái
                            <select name="status">
                                <?php foreach (issueStatuses() as $value): ?>
                                    <?php if ($value === 'verified' && (string)$detail['status'] !== 'verified') { continue; } ?>
                                    <option value="<?= h($value) ?>" <?= $detail['status'] === $value ? 'selected' : '' ?>><?= h($value) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="muted">Chỉ chuyển sang verified bằng cách ghi kết quả kiểm thử passed.</small>
                        </label>
                        <label class="field">Người phụ trách
                            <select name="assignee_id">
                                <option value="">Chưa phân công</option>
                                <?php foreach ($members as $member): ?>
                                    <option value="<?= (int)$member['id'] ?>" <?= (string)$detail['assignee_id'] === (string)$member['id'] ? 'selected' : '' ?>>
                                        <?= h($member['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <label class="field">Commit / PR URL<input name="commit_url" value="<?= h($detail['commit_url']) ?>" maxlength="500"></label>
                    <button>Lưu có kiểm soát phiên bản</button>
                </form>
            <?php endif; ?>

            <div class="grid">
                <form method="post" class="panel">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="comment">
                    <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                    <input type="hidden" name="project_id" value="<?= $projectId ?>">
                    <label class="field">Bình luận<textarea name="body" required maxlength="2000"></textarea></label>
                    <button>Gửi bình luận</button>
                </form>
                <?php if (canTestIssue($role) && (string)$detail['status'] !== 'closed'): ?>
                    <form method="post" class="panel">
                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="test">
                        <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                        <input type="hidden" name="project_id" value="<?= $projectId ?>">
                        <label class="field">Kết quả kiểm thử
                            <select name="result">
                                <?php foreach (testResults() as $value): ?>
                                    <option value="<?= h($value) ?>"><?= h($value) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field">Ghi chú<textarea name="notes" maxlength="5000"></textarea></label>
                        <button>Lưu kết quả</button>
                    </form>
                <?php endif; ?>
            </div>

            <h3>Bình luận</h3>
            <?php if ($comments === []): ?>
                <p class="muted">Chưa có bình luận.</p>
            <?php else: ?>
                <?php foreach ($comments as $comment): ?>
                    <p><?= h($comment['full_name']) ?>: <?= nl2br(h($comment['body'])) ?>
                        <span class="muted"><?= h($comment['created_at']) ?></span>
                    </p>
                <?php endforeach; ?>
            <?php endif; ?>

            <h3>Kết quả kiểm thử</h3>
            <?php if ($testResults === []): ?>
                <p class="muted">Chưa có kết quả kiểm thử.</p>
            <?php else: ?>
                <?php foreach ($testResults as $test): ?>
                    <p>
                        <span class="pill <?= h($test['result']) === 'passed' ? 'ok' : 'bad' ?>"><?= h($test['result']) ?></span>
                        <?= h($test['notes']) ?> · <?= h($test['tester_name']) ?>
                        · phiên bản test: <?= (int)$test['issue_version'] ?>
                        <?php if (!(bool)$test['is_current']): ?>
                            <span class="pill warn">đã lỗi thời</span>
                        <?php endif; ?>
                    </p>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Danh sách công việc (<?= count($issues) ?>)</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Tiêu đề</th><th>Ưu tiên</th><th>Trạng thái</th><th>Phụ trách</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($issues as $issue): ?>
                    <tr data-priority="<?= h($issue['priority']) ?>">
                        <td>#<?= (int)$issue['id'] ?></td>
                        <td><?= h($issue['title']) ?></td>
                        <td><span class="pill"><?= h($issue['priority']) ?></span></td>
                        <td><span class="pill"><?= h($issue['status']) ?></span></td>
                        <td><?= h($issue['assignee'] ?? 'Chưa phân công') ?></td>
                        <td><a href="?project_id=<?= $projectId ?>&id=<?= (int)$issue['id'] ?>">Mở / sửa</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<?php pageEnd(); ?>
