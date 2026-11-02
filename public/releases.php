<?php

declare(strict_types=1);

require __DIR__ . '/../src/ReleaseService.php';

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
$selected = (int)($_GET['id'] ?? $_POST['release_id'] ?? 0);

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
                $id = ReleaseService::create($connection, $projectId, (int)$user['id'], $role, $_POST);
                flash('ok', 'Đã tạo phiên bản.');
                redirect('/releases.php?project_id=' . $projectId . '&id=' . $id);
            }
            $releaseId = postInt($_POST, 'release_id', 1, PHP_INT_MAX, 'ID phiên bản');
            $selected = $releaseId;
            if ($action === 'add_item') {
                $issueId = postInt($_POST, 'issue_id', 1, PHP_INT_MAX, 'Công việc');
                ReleaseService::addItem($connection, $releaseId, $projectId, $issueId, (int)$user['id'], $role);
                flash('ok', 'Đã thêm công việc vào phiên bản.');
            } elseif ($action === 'remove_item') {
                $issueId = postInt($_POST, 'issue_id', 1, PHP_INT_MAX, 'Công việc');
                ReleaseService::removeItem($connection, $releaseId, $projectId, $issueId, (int)$user['id'], $role);
                flash('ok', 'Đã gỡ công việc khỏi phiên bản.');
            } elseif ($action === 'ready') {
                ReleaseService::markReady($connection, $releaseId, $projectId, (int)$user['id'], $role);
                flash('ok', 'Phiên bản đã sẵn sàng phát hành.');
            } elseif ($action === 'release') {
                ReleaseService::release($connection, $releaseId, $projectId, (int)$user['id'], $role);
                flash('ok', 'Đã chốt phát hành và lưu snapshot.');
            } elseif ($action === 'check') {
                $releaseForCheck = queryOne(
                    $connection,
                    'SELECT status FROM releases WHERE id=$1 AND project_id=$2',
                    [(string)$releaseId, (string)$projectId]
                );
                if ($releaseForCheck === null) {
                    throw new NotFoundException('Không tìm thấy phiên bản trong dự án.');
                }
                if ((string)$releaseForCheck['status'] === 'released') {
                    throw new ConflictException('Phiên bản đã chốt chỉ hiển thị snapshot lịch sử, không kiểm tra lại dữ liệu hiện tại.');
                }
                $check = ReleaseService::check($connection, $releaseId, $projectId, $role);
                flash(
                    $check['problems'] === [] ? 'ok' : 'error',
                    $check['problems'] === []
                        ? 'Kiểm tra đạt: phiên bản có thể chuyển sang sẵn sàng.'
                        : "Chưa đủ điều kiện:\n- " . implode("\n- ", $check['problems'])
                );
            } else {
                throw new ValidationException('Thao tác không hợp lệ.');
            }
            redirect('/releases.php?project_id=' . $projectId . '&id=' . $releaseId);
        } catch (Throwable $exception) {
            $error = userMessage($exception);
            $selected = (int)($_POST['release_id'] ?? $selected);
        }
    }
}

$releases = $projectId > 0
    ? queryAll(
        $connection,
        'SELECT r.*,
                (SELECT count(*) FROM release_items ri WHERE ri.release_id=r.id) AS item_count
         FROM releases r
         WHERE r.project_id=$1
         ORDER BY r.id DESC',
        [(string)$projectId]
    )
    : [];
$issues = $projectId > 0
    ? queryAll(
        $connection,
        'SELECT id,title,priority,status FROM issues WHERE project_id=$1 ORDER BY id',
        [(string)$projectId]
    )
    : [];
$check = null;
$items = [];
$selectedRelease = null;
if ($selected > 0 && $projectId > 0) {
    $selectedRelease = queryOne(
        $connection,
        'SELECT * FROM releases WHERE id=$1 AND project_id=$2',
        [(string)$selected, (string)$projectId]
    );
    if ($selectedRelease !== null) {
        if ((string)$selectedRelease['status'] !== 'released') {
            $check = ReleaseService::check($connection, $selected, $projectId, $role);
        }
        $items = queryAll(
            $connection,
            'SELECT * FROM release_items WHERE release_id=$1 ORDER BY id',
            [(string)$selected]
        );
    }
}
$canManage = canManageRelease($role);
pageStart('Phiên bản phát hành');
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
    </section>

    <section class="panel">
        <h2>Điều kiện phát hành đã công bố</h2>
        <ul>
            <?php foreach (ReleaseService::conditions() as $condition): ?>
                <li><b><?= h($condition['title']) ?></b>: <?= h($condition['description']) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <?php if ($canManage): ?>
        <section class="panel">
            <h2>Lập phiên bản dự kiến</h2>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <label class="field">Tên phiên bản<input name="version_name" placeholder="1.2.0" required maxlength="80"></label>
                <label class="field">Mô tả<textarea name="description" maxlength="20000"></textarea></label>
                <button>Tạo phiên bản</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Các phiên bản</h2>
        <?php if ($releases === []): ?>
            <p class="muted">Chưa có phiên bản nào.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Phiên bản</th><th>Trạng thái</th><th>Số mục</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($releases as $release): ?>
                        <tr>
                            <td><?= h($release['version_name']) ?> — <?= h($release['description']) ?></td>
                            <td><span class="pill"><?= h($release['status']) ?></span></td>
                            <td><?= (int)$release['item_count'] ?></td>
                            <td>
                                <a href="?project_id=<?= $projectId ?>&id=<?= (int)$release['id'] ?>">Chi tiết</a> ·
                                <a href="/release-notes.php?id=<?= (int)$release['id'] ?>">Ghi chú</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($selectedRelease !== null): ?>
        <?php $release = $selectedRelease; ?>
        <section class="panel">
            <h2>Chi tiết <?= h($release['version_name']) ?> · <span class="pill"><?= h($release['status']) ?></span></h2>
            <p><?= h($release['description']) ?></p>

            <?php if ($check !== null): ?>
            <h3>Điều kiện phát hành hiện tại</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Điều kiện</th><th>Kết quả</th><th>Chi tiết</th></tr></thead>
                    <tbody>
                    <?php foreach ($check['checks'] as $condition): ?>
                        <tr>
                            <td><b><?= h($condition['title']) ?></b><br><span class="muted"><?= h($condition['description']) ?></span></td>
                            <td><span class="pill <?= $condition['passed'] ? 'ok' : 'bad' ?>"><?= $condition['passed'] ? 'Đạt' : 'Chưa đạt' ?></span></td>
                            <td>
                                <?php if ($condition['problems'] === []): ?>
                                    <span class="muted">Không có vấn đề.</span>
                                <?php else: ?>
                                    <ul>
                                        <?php foreach ($condition['problems'] as $problem): ?>
                                            <li><?= h($problem) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <p class="ok">Đây là snapshot lịch sử đã chốt; không đánh giá lại role, issue, test hoặc dependency hiện tại.</p>
            <?php endif; ?>

            <h3>Danh sách snapshot</h3>
            <?php if ($items === []): ?>
                <p>Chưa gắn công việc.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Issue</th><th>Tiêu đề snapshot</th><th>Ưu tiên</th><th>Trạng thái</th><th>Kiểm thử</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>#<?= (int)$item['issue_id'] ?></td>
                                <td><?= h($item['snapshot_title']) ?></td>
                                <td><?= h($item['snapshot_priority']) ?></td>
                                <td><?= h($item['snapshot_status']) ?></td>
                                <td>
                                    <?php if ((string)$item['snapshot_test_result'] === 'unverified'): ?>
                                        <strong>unverified</strong>
                                        <span class="muted">(thiếu bằng chứng nguồn gốc)</span>
                                    <?php else: ?>
                                        <?= h($item['snapshot_test_result']) ?>
                                    <?php endif; ?>
                                    <?php if ($item['snapshot_test_version'] !== null): ?>
                                        <span class="muted">(v<?= (int)$item['snapshot_test_version'] ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($release['status'] === 'planned' && $canManage): ?>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="remove_item">
                                            <input type="hidden" name="project_id" value="<?= $projectId ?>">
                                            <input type="hidden" name="release_id" value="<?= (int)$release['id'] ?>">
                                            <input type="hidden" name="issue_id" value="<?= (int)$item['issue_id'] ?>">
                                            <button class="danger" type="submit">Gỡ</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h3>Thao tác</h3>
            <?php if ($release['status'] !== 'released'): ?>
            <form method="post" class="inline-form">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="check">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <input type="hidden" name="release_id" value="<?= (int)$release['id'] ?>">
                <button class="secondary" type="submit">Kiểm tra lại</button>
            </form>
            <?php endif; ?>

            <?php if ($release['status'] === 'planned' && $canManage): ?>
                <form method="post" class="inline-form">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="ready">
                    <input type="hidden" name="project_id" value="<?= $projectId ?>">
                    <input type="hidden" name="release_id" value="<?= (int)$release['id'] ?>">
                    <button type="submit">Chuyển sang sẵn sàng</button>
                </form>
            <?php endif; ?>

            <?php if ($release['status'] === 'ready' && $canManage): ?>
                <form method="post" class="inline-form">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="release">
                    <input type="hidden" name="project_id" value="<?= $projectId ?>">
                    <input type="hidden" name="release_id" value="<?= (int)$release['id'] ?>">
                    <button type="submit">Chốt phát hành</button>
                </form>
            <?php endif; ?>

            <?php if ($release['status'] === 'released'): ?>
                <p class="ok">Phiên bản đã chốt. Snapshot và ghi chú phát hành không thay đổi khi issue nguồn được sửa.</p>
                <p>
                    <a class="btn" href="/release-notes.php?id=<?= (int)$release['id'] ?>">Xem ghi chú</a>
                    <a class="btn secondary" href="/release-notes.php?id=<?= (int)$release['id'] ?>&format=markdown">Tải Markdown</a>
                </p>
            <?php endif; ?>

            <?php if ($release['status'] === 'planned' && $canManage): ?>
                <h3>Thêm công việc vào phiên bản</h3>
                <?php if ($issues === []): ?>
                    <p class="muted">Dự án chưa có công việc.</p>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="add_item">
                        <input type="hidden" name="project_id" value="<?= $projectId ?>">
                        <input type="hidden" name="release_id" value="<?= (int)$release['id'] ?>">
                        <label class="field">Công việc
                            <select name="issue_id">
                                <?php foreach ($issues as $issue): ?>
                                    <option value="<?= (int)$issue['id'] ?>">
                                        #<?= (int)$issue['id'] ?> · <?= h($issue['title']) ?>
                                        (<?= h($issue['priority']) ?> / <?= h($issue['status']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit">Thêm vào phiên bản</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php pageEnd(); ?>
