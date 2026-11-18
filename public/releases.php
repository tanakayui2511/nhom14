<?php

declare(strict_types=1);

require __DIR__ . '/../src/ReleaseService.php';

$user = requireLogin();
$userId = (int)$user['id'];
$connection = db();
[$projects, $projectId, $role] = selectProject($connection, $user);
$error = '';
$errorForm = '';
$old = [];
$selected = (int)($_GET['id'] ?? $_POST['release_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ((int)($_POST['project_id'] ?? 0) <= 0) {
            throw new ValidationException('Dự án không hợp lệ.');
        }
        $back = '/releases.php?project_id=' . $projectId;
        if ($action === 'create') {
            $id = ReleaseService::create($connection, $projectId, $userId, $role, $_POST);
            flash('ok', 'Đã tạo phiên bản dự kiến. Hãy thêm công việc vào phiên bản.');
            redirect($back . '&id=' . $id);
        }
        $releaseId = postInt($_POST, 'release_id', 1, PHP_INT_MAX, 'ID phiên bản');
        $selected = $releaseId;
        if ($action === 'add_item') {
            $issueId = postInt($_POST, 'issue_id', 1, PHP_INT_MAX, 'Công việc');
            ReleaseService::addItem($connection, $releaseId, $projectId, $issueId, $userId, $role);
            flash('ok', 'Đã thêm công việc #' . $issueId . ' vào phiên bản.');
        } elseif ($action === 'remove_item') {
            $issueId = postInt($_POST, 'issue_id', 1, PHP_INT_MAX, 'Công việc');
            ReleaseService::removeItem($connection, $releaseId, $projectId, $issueId, $userId, $role);
            flash('ok', 'Đã gỡ công việc #' . $issueId . ' khỏi phiên bản.');
        } elseif ($action === 'ready') {
            ReleaseService::markReady($connection, $releaseId, $projectId, $userId, $role);
            flash('ok', 'Đã xác nhận sẵn sàng: mọi điều kiện phát hành đều đạt.');
        } elseif ($action === 'release') {
            ReleaseService::release($connection, $releaseId, $projectId, $userId, $role);
            flash('ok', 'Đã chốt phát hành và lưu snapshot. Ghi chú phát hành sẽ không đổi nữa.');
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
                    ? 'Kiểm tra đạt: có thể xác nhận sẵn sàng.'
                    : 'Chưa đủ điều kiện: ' . count($check['problems']) . ' vấn đề, xem checklist bên dưới.'
            );
        } else {
            throw new ValidationException('Thao tác không hợp lệ.');
        }
        redirect($back . '&id=' . $releaseId);
    } catch (Throwable $exception) {
        $error = userMessage($exception);
        $errorForm = $action;
        $old = $_POST;
        $selected = (int)($_POST['release_id'] ?? $selected);
    }
}

$releases = $projectId > 0
    ? queryAll(
        $connection,
        'SELECT r.*,
                (SELECT count(*) FROM release_items ri WHERE ri.release_id=r.id) AS item_count
         FROM releases r
         WHERE r.project_id=$1
         ORDER BY CASE r.status WHEN \'ready\' THEN 0 WHEN \'planned\' THEN 1 ELSE 2 END, r.id DESC',
        [(string)$projectId]
    )
    : [];
$check = null;
$items = [];
$selectedRelease = null;
$candidates = [];
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
            'SELECT ri.*,i.title AS live_title,i.status AS live_status,i.priority AS live_priority
             FROM release_items ri LEFT JOIN issues i ON i.id=ri.issue_id
             WHERE ri.release_id=$1 ORDER BY ri.id',
            [(string)$selected]
        );
        $candidates = queryAll(
            $connection,
            'SELECT id,title,priority,status FROM issues
             WHERE project_id=$1 AND id NOT IN (SELECT issue_id FROM release_items WHERE release_id=$2)
             ORDER BY id',
            [(string)$projectId, (string)$selected]
        );
    }
}
$canManage = canManageRelease($role);
$value = static fn(string $form, string $key, string $default = ''): string
    => $errorForm === $form && isset($old[$key]) ? (string)$old[$key] : $default;

pageStart('Phiên bản phát hành');
if ($error !== '') {
    echo '<p class="error" role="alert">' . nl2br(h($error)) . '</p>';
}
?>
<?php if ($projects === []): ?>
    <section class="panel"><?= emptyState('Bạn chưa thuộc dự án nào.') ?></section>
<?php else: ?>
    <section class="panel">
        <details>
            <summary><b>Điều kiện phát hành (công bố công khai)</b></summary>
            <p class="muted">Một phiên bản chỉ được “Xác nhận sẵn sàng” và “Chốt phát hành” khi đạt TẤT CẢ điều kiện sau. Khi chốt, hệ thống kiểm tra lại trong cùng transaction.</p>
            <ol class="conditions-list">
                <?php foreach (ReleaseService::conditions() as $condition): ?>
                    <li><b><?= h($condition['title']) ?></b>: <?= h($condition['description']) ?></li>
                <?php endforeach; ?>
            </ol>
            <p class="muted">Quy trình: Dự kiến → (Xác nhận sẵn sàng) → Sẵn sàng → (Chốt phát hành) → Đã phát hành. Nếu công việc trong phiên bản “Sẵn sàng” bị sửa làm hỏng điều kiện, phiên bản tự hạ về “Dự kiến”.</p>
        </details>
    </section>

    <?php if ($selectedRelease !== null): ?>
        <?php $release = $selectedRelease; $releaseId = (int)$release['id']; $status = (string)$release['status']; ?>
        <section class="panel" aria-labelledby="release-title">
            <div class="section-heading">
                <h2 id="release-title">Phiên bản <?= h($release['version_name']) ?> <?= releaseStatusBadge($status) ?></h2>
                <a class="btn secondary" href="/releases.php?project_id=<?= $projectId ?>">← Tất cả phiên bản</a>
            </div>
            <?php if ((string)$release['description'] !== ''): ?>
                <p><?= nl2br(h($release['description'])) ?></p>
            <?php endif; ?>

            <?php if ($check !== null): ?>
                <?php $passedCount = count(array_filter($check['checks'], static fn(array $c): bool => $c['passed'])); ?>
                <h3>Kiểm tra điều kiện: <?= $passedCount ?>/<?= count($check['checks']) ?> đạt</h3>
                <ul class="checklist">
                    <?php foreach ($check['checks'] as $condition): ?>
                        <li class="<?= $condition['passed'] ? 'pass' : 'fail' ?>">
                            <span class="mark" aria-hidden="true"><?= $condition['passed'] ? '✓' : '✗' ?></span>
                            <div>
                                <b><?= h($condition['title']) ?></b>
                                <span class="sr-only"><?= $condition['passed'] ? 'Đạt' : 'Chưa đạt' ?></span>
                                <?php if ($condition['problems'] !== []): ?>
                                    <ul class="problems">
                                        <?php foreach ($condition['problems'] as $problem): ?>
                                            <li><?= linkIssueRefs($problem, $projectId) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="ok">Đây là snapshot lịch sử đã chốt; không đánh giá lại role, issue, test hoặc dependency hiện tại.</p>
            <?php endif; ?>

            <div class="filter-actions">
                <?php if ($status !== 'released'): ?>
                    <form method="post" class="inline-form">
                        <?= hiddenFields('check', $projectId, ['release_id' => $releaseId]) ?>
                        <button class="secondary" type="submit">Kiểm tra lại</button>
                    </form>
                <?php endif; ?>
                <?php if ($status === 'planned' && $canManage): ?>
                    <form method="post" class="inline-form">
                        <?= hiddenFields('ready', $projectId, ['release_id' => $releaseId]) ?>
                        <button type="submit">Xác nhận sẵn sàng</button>
                    </form>
                <?php endif; ?>
                <?php if ($status === 'ready' && $canManage): ?>
                    <button type="button" data-open-dialog="release-dialog">Chốt phát hành…</button>
                    <dialog id="release-dialog" class="confirm-dialog" aria-labelledby="release-dialog-title">
                        <form method="post">
                            <?= hiddenFields('release', $projectId, ['release_id' => $releaseId]) ?>
                            <h2 id="release-dialog-title">Chốt phát hành <?= h($release['version_name']) ?>?</h2>
                            <p>Hệ thống sẽ kiểm tra lại mọi điều kiện rồi lưu snapshot của <b><?= count($items) ?></b> công việc.</p>
                            <p><b>Sau khi chốt không thể sửa danh sách hay hoàn tác.</b> Ghi chú phát hành sẽ giữ nguyên dù công việc được sửa về sau.</p>
                            <div class="dialog-actions">
                                <button type="button" class="secondary" data-close-dialog>Hủy</button>
                                <button type="submit">Tôi xác nhận, chốt phát hành</button>
                            </div>
                        </form>
                    </dialog>
                    <noscript>
                        <form method="post" class="inline-form">
                            <?= hiddenFields('release', $projectId, ['release_id' => $releaseId]) ?>
                            <button type="submit">Chốt phát hành (không thể hoàn tác)</button>
                        </form>
                    </noscript>
                <?php endif; ?>
                <?php if ($status === 'released'): ?>
                    <a class="btn" href="/release-notes.php?id=<?= $releaseId ?>">Xem ghi chú phát hành</a>
                    <a class="btn secondary" href="/release-notes-export.php?id=<?= $releaseId ?>&amp;format=md">Tải Markdown</a>
                    <a class="btn secondary" href="/release-notes-export.php?id=<?= $releaseId ?>&amp;format=txt">Tải TXT</a>
                <?php endif; ?>
            </div>
            <?php if (!$canManage && $status !== 'released'): ?>
                <p class="muted">Chỉ quản lý phát hành hoặc quản trị được xác nhận sẵn sàng và chốt phát hành.</p>
            <?php endif; ?>

            <h3><?= $status === 'released' ? 'Danh sách đã chốt (snapshot)' : 'Công việc trong phiên bản' ?> (<?= count($items) ?>)</h3>
            <?php if ($items === []): ?>
                <?= emptyState('Phiên bản chưa có công việc nào.', $canManage && $status === 'planned' ? 'Thêm công việc bằng ô bên dưới.' : '') ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Công việc</th><th>Tiêu đề</th><th>Ưu tiên</th><th>Trạng thái</th><th>Kiểm thử</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php
                            // Đã chốt: hiển thị snapshot. Chưa chốt: hiển thị dữ liệu hiện tại.
                            $isReleased = $status === 'released';
                            $title = $isReleased ? $item['snapshot_title'] : ($item['live_title'] ?? $item['snapshot_title']);
                            $priority = $isReleased ? $item['snapshot_priority'] : ($item['live_priority'] ?? $item['snapshot_priority']);
                            $itemStatus = $isReleased ? $item['snapshot_status'] : ($item['live_status'] ?? $item['snapshot_status']);
                            ?>
                            <tr>
                                <td><a href="/issues.php?project_id=<?= $projectId ?>&amp;id=<?= (int)$item['issue_id'] ?>">#<?= (int)$item['issue_id'] ?></a></td>
                                <td><?= h($title) ?></td>
                                <td><?= priorityBadge($priority) ?></td>
                                <td><?= statusBadge($itemStatus) ?></td>
                                <td>
                                    <?php if ($isReleased): ?>
                                        <?= testResultBadge($item['snapshot_test_result']) ?>
                                        <?php if ($item['snapshot_test_version'] !== null): ?>
                                            <span class="muted">(v<?= (int)$item['snapshot_test_version'] ?>)</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="muted">xem checklist</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($status === 'planned' && $canManage): ?>
                                        <form method="post" class="inline-form">
                                            <?= hiddenFields('remove_item', $projectId, ['release_id' => $releaseId, 'issue_id' => (int)$item['issue_id']]) ?>
                                            <button class="danger" type="submit" aria-label="Gỡ công việc #<?= (int)$item['issue_id'] ?> khỏi phiên bản">Gỡ</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($status === 'planned' && $canManage): ?>
                <h3>Thêm công việc vào phiên bản</h3>
                <?php if ($candidates === []): ?>
                    <p class="muted">Mọi công việc của dự án đã có trong phiên bản.</p>
                <?php else: ?>
                    <form method="post" class="filter-bar">
                        <?= hiddenFields('add_item', $projectId, ['release_id' => $releaseId]) ?>
                        <label class="field">Công việc
                            <select name="issue_id">
                                <?php foreach ($candidates as $issue): ?>
                                    <option value="<?= (int)$issue['id'] ?>">
                                        #<?= (int)$issue['id'] ?> · <?= h($issue['title']) ?>
                                        (<?= h(priorityLabel($issue['priority'])) ?> / <?= h(statusLabel($issue['status'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="filter-actions"><button type="submit">Thêm vào phiên bản</button></div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Các phiên bản</h2>
        <?php if ($releases === []): ?>
            <?= emptyState('Chưa có phiên bản nào.', $canManage ? 'Lập phiên bản dự kiến đầu tiên ở bên dưới.' : 'Quản lý phát hành sẽ lập phiên bản.') ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Phiên bản</th><th>Trạng thái</th><th>Số công việc</th><th>Ngày chốt</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($releases as $row): ?>
                        <tr<?= (int)$row['id'] === $selected ? ' aria-current="true"' : '' ?>>
                            <td><a href="/releases.php?project_id=<?= $projectId ?>&amp;id=<?= (int)$row['id'] ?>"><b><?= h($row['version_name']) ?></b></a>
                                <?php if ((string)$row['description'] !== ''): ?><br><span class="muted"><?= h($row['description']) ?></span><?php endif; ?></td>
                            <td><?= releaseStatusBadge($row['status']) ?></td>
                            <td><?= (int)$row['item_count'] ?></td>
                            <td><?= $row['released_at'] === null ? '—' : h(date('d/m/Y', strtotime((string)$row['released_at']))) ?></td>
                            <td><a href="/release-notes.php?id=<?= (int)$row['id'] ?>">Ghi chú</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($canManage): ?>
        <section class="panel">
            <details <?= $errorForm === 'create' || $releases === [] ? 'open' : '' ?>>
                <summary><b>+ Lập phiên bản dự kiến</b></summary>
                <form method="post">
                    <?= hiddenFields('create', $projectId) ?>
                    <label class="field">Tên phiên bản
                        <input name="version_name" placeholder="1.2.0" required maxlength="80" value="<?= h($value('create', 'version_name')) ?>">
                    </label>
                    <label class="field">Mô tả
                        <textarea name="description" maxlength="20000"><?= h($value('create', 'description')) ?></textarea>
                    </label>
                    <button type="submit">Tạo phiên bản</button>
                </form>
            </details>
        </section>
    <?php endif; ?>
<?php endif; ?>
<script>
(() => {
    // Hộp xác nhận bằng <dialog>, không dùng alert/confirm của trình duyệt.
    document.querySelectorAll('[data-open-dialog]').forEach((button) => {
        const dialog = document.getElementById(button.dataset.openDialog);
        if (!dialog) return;
        button.addEventListener('click', () => {
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
        });
        dialog.querySelectorAll('[data-close-dialog]').forEach((close) => {
            close.addEventListener('click', () => dialog.close());
        });
    });
})();
</script>
<?php pageEnd(); ?>
