<?php

declare(strict_types=1);

require __DIR__ . '/../src/IssueService.php';
require __DIR__ . '/../src/History.php';

$user = requireLogin();
$userId = (int)$user['id'];
$connection = db();
[$projects, $projectId, $role] = selectProject($connection, $user);

$error = '';
$errorForm = '';
$old = [];
$conflict = null;
$selected = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

// ---- Xử lý POST (Post/Redirect/Get: thành công thì redirect kèm flash) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ((int)($_POST['project_id'] ?? 0) <= 0) {
            throw new ValidationException('Dự án không hợp lệ.');
        }
        $back = '/issues.php?project_id=' . $projectId;
        if ($action === 'create') {
            $id = IssueService::create($connection, $projectId, $userId, $role, $_POST);
            flash('ok', 'Đã tạo công việc #' . $id . '.');
            redirect($back . '&id=' . $id);
        }
        $id = postInt($_POST, 'id', 1, PHP_INT_MAX, 'ID công việc');
        if ($action === 'update') {
            $baseVersion = postInt($_POST, 'version', 1, PHP_INT_MAX, 'Phiên bản dữ liệu');
            $newVersion = IssueService::update($connection, $projectId, $userId, $role, $id, $baseVersion, $_POST);
            flash('ok', 'Đã lưu công việc #' . $id . ' (phiên bản dữ liệu ' . $newVersion . ').');
            redirect($back . '&id=' . $id);
        }
        if ($action === 'comment') {
            IssueService::addComment($connection, $projectId, $userId, $role, $id, $_POST);
            flash('ok', 'Đã thêm bình luận.');
            redirect($back . '&id=' . $id . '#comments');
        }
        if ($action === 'test') {
            $newVersion = IssueService::recordTest($connection, $projectId, $userId, $role, $id, $_POST);
            flash('ok', 'Đã lưu kết quả kiểm thử; công việc ở phiên bản dữ liệu ' . $newVersion . '.');
            redirect($back . '&id=' . $id);
        }
        throw new ValidationException('Thao tác không hợp lệ.');
    } catch (VersionConflictException $exception) {
        $error = $exception->getMessage();
        $conflict = ['mine' => $_POST, 'current' => $exception->current];
        $errorForm = $action;
        $old = $_POST;
        $selected = (int)($_POST['id'] ?? $selected);
    } catch (Throwable $exception) {
        $error = userMessage($exception);
        $errorForm = $action;
        $old = $_POST;
        $selected = (int)($_POST['id'] ?? $selected);
    }
}

// ---- Bộ lọc danh sách (GET để giữ được URL) ----
$filters = [
    'status' => in_array($_GET['status'] ?? '', ISSUE_STATUSES, true) ? (string)$_GET['status'] : '',
    'priority' => in_array($_GET['priority'] ?? '', ISSUE_PRIORITIES, true) ? (string)$_GET['priority'] : '',
    'type' => in_array($_GET['type'] ?? '', ISSUE_TYPES, true) ? (string)$_GET['type'] : '',
    'assignee' => preg_match('/^(none|\d+)$/', (string)($_GET['assignee'] ?? '')) === 1 ? (string)$_GET['assignee'] : '',
    'q' => trim((string)($_GET['q'] ?? '')),
    'mine' => (string)($_GET['mine'] ?? '') === '1',
];
$filterQuery = static function (array $changes = []) use ($filters, $projectId): string {
    $params = ['project_id' => $projectId] + array_merge($filters, $changes);
    $params['mine'] = $params['mine'] ? '1' : '';
    return '/issues.php?' . http_build_query(array_filter($params, static fn($v): bool => $v !== '' && $v !== false));
};

$members = [];
$issues = [];
if ($projectId > 0) {
    $members = queryAll(
        $connection,
        'SELECT u.id,u.full_name,pm.role
         FROM users u
         JOIN project_members pm ON pm.user_id=u.id
         WHERE pm.project_id=$1
         ORDER BY u.full_name',
        [(string)$projectId]
    );
    $where = ['i.project_id=$1'];
    $params = [(string)$projectId];
    foreach (['status' => 'i.status', 'priority' => 'i.priority', 'type' => 'i.issue_type'] as $key => $column) {
        if ($filters[$key] !== '') {
            $params[] = $filters[$key];
            $where[] = $column . '=$' . count($params);
        }
    }
    if ($filters['assignee'] === 'none') {
        $where[] = 'i.assignee_id IS NULL';
    } elseif ($filters['assignee'] !== '') {
        $params[] = $filters['assignee'];
        $where[] = 'i.assignee_id=$' . count($params);
    }
    if ($filters['mine']) {
        $params[] = (string)$userId;
        $where[] = 'i.assignee_id=$' . count($params);
    }
    if ($filters['q'] !== '') {
        $params[] = $filters['q'];
        $n = count($params);
        $where[] = "(position(lower(\$$n) in lower(i.title))>0 OR ('#' || i.id::text)=\$$n OR i.id::text=\$$n)";
    }
    $issues = queryAll(
        $connection,
        "SELECT i.*,a.full_name AS assignee
         FROM issues i
         LEFT JOIN users a ON a.id=i.assignee_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY CASE i.priority
             WHEN 'blocker' THEN 1 WHEN 'critical' THEN 2 WHEN 'high' THEN 3
             WHEN 'medium' THEN 4 ELSE 5 END, i.id",
        $params
    );
}

// ---- Chi tiết công việc ----
$detail = null;
$comments = [];
$testResults = [];
$history = [];
$releasedIn = [];
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
        $history = IssueService::history($connection, $projectId, $selected);
        $releasedIn = IssueService::releasedVersions($connection, $projectId, $selected);
    }
}

// Giá trị hiển thị lại cho form: lấy dữ liệu người dùng vừa nhập nếu form đó lỗi.
$value = static function (string $form, string $key, mixed $default) use ($errorForm, $old): string {
    return $errorForm === $form && array_key_exists($key, $old) ? (string)$old[$key] : (string)$default;
};
$stepsTemplate = "Bước tái hiện:\n1. \n2. \n\nKết quả mong đợi:\n\nKết quả thực tế:\n";

pageStart('Lỗi và công việc');
if ($error !== '') {
    echo '<p class="error" role="alert">' . nl2br(h($error)) . '</p>';
}
?>
<?php if ($projects === []): ?>
    <section class="panel"><?= emptyState('Bạn chưa thuộc dự án nào.', 'Hãy liên hệ quản trị viên để được thêm vào dự án.') ?></section>
<?php else: ?>

    <?php if ($detail !== null): ?>
        <?php
        $reporterId = $detail['reporter_id'] === null ? null : (int)$detail['reporter_id'];
        $assigneeId = $detail['assignee_id'] === null ? null : (int)$detail['assignee_id'];
        $canEdit = canEditIssue($role, $userId, $reporterId, $assigneeId);
        $priorityLocked = (string)$detail['priority'] === 'blocker' && !hasProjectRole($role, ['tester', 'release_manager']);
        $issueId = (int)$detail['id'];
        ?>
        <section class="panel" aria-labelledby="detail-title">
            <div class="section-heading">
                <h2 id="detail-title">#<?= $issueId ?> · <?= h($detail['title']) ?></h2>
                <a class="btn secondary" href="<?= h($filterQuery()) ?>">← Về danh sách</a>
            </div>
            <p class="issue-meta">
                <?= issueTypeBadge($detail['issue_type']) ?>
                <span>Trạng thái: <?= statusBadge($detail['status']) ?></span>
                <span>Ưu tiên: <?= priorityBadge($detail['priority']) ?></span>
                <span>Phụ trách: <b><?= h($detail['assignee'] ?? 'Chưa phân công') ?></b></span>
                <span>Người báo: <?= h($detail['reporter'] ?? 'Không xác định') ?></span>
                <span class="muted">Phiên bản dữ liệu: <?= (int)$detail['version'] ?></span>
            </p>
            <?php if ((string)$detail['description'] !== ''): ?>
                <p><?= nl2br(h($detail['description'])) ?></p>
            <?php endif; ?>
            <?php if ((string)$detail['reproduction_steps'] !== ''): ?>
                <h3>Bước tái hiện</h3>
                <p><?= nl2br(h($detail['reproduction_steps'])) ?></p>
            <?php endif; ?>
            <?php if ((string)$detail['commit_url'] !== ''): ?>
                <p>Commit / PR: <a href="<?= h($detail['commit_url']) ?>" rel="noopener noreferrer"><?= h($detail['commit_url']) ?></a></p>
            <?php endif; ?>

            <?php if ($conflict !== null && $errorForm === 'update'): ?>
                <?php
                $mine = $conflict['mine'];
                $current = $conflict['current'];
                $memberName = static function (string $id) use ($members): string {
                    foreach ($members as $member) {
                        if ((string)$member['id'] === $id) {
                            return (string)$member['full_name'];
                        }
                    }
                    return $id === '' ? 'Chưa phân công' : '#' . $id;
                };
                $rows = [
                    'Tiêu đề' => [(string)($mine['title'] ?? ''), $current['title']],
                    'Mô tả' => [(string)($mine['description'] ?? ''), $current['description']],
                    'Bước tái hiện' => [(string)($mine['steps'] ?? ''), $current['steps']],
                    'Ưu tiên' => [priorityLabel((string)($mine['priority'] ?? '')), priorityLabel($current['priority'])],
                    'Trạng thái' => [statusLabel((string)($mine['status'] ?? '')), statusLabel($current['status'])],
                    'Người phụ trách' => [$memberName((string)($mine['assignee_id'] ?? '')), $current['assignee'] ?? 'Chưa phân công'],
                    'Commit / PR' => [(string)($mine['commit_url'] ?? ''), $current['commit_url']],
                ];
                ?>
                <section class="panel conflict-panel" aria-labelledby="conflict-title">
                    <h3 id="conflict-title">Xung đột chỉnh sửa — chưa có gì bị ghi đè</h3>
                    <p>Người khác đã lưu công việc này sau khi bạn mở form. Dòng tô màu là chỗ hai bản khác nhau.</p>
                    <div class="table-wrap">
                        <table class="compare-table">
                            <thead><tr><th>Trường</th><th>Bản bạn vừa nhập</th><th>Bản hiện tại trong CSDL (v<?= (int)$current['version'] ?>)</th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $label => [$mineValue, $dbValue]): ?>
                                <tr class="<?= $mineValue !== $dbValue ? 'is-diff' : '' ?>">
                                    <td><?= h($label) ?></td>
                                    <td><?= h($mineValue) ?></td>
                                    <td><?= h($dbValue) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="filter-actions">
                        <a class="btn secondary" href="/issues.php?project_id=<?= $projectId ?>&amp;id=<?= $issueId ?>">Tải bản mới nhất</a>
                        <form method="post" class="inline-form">
                            <?= hiddenFields('update', $projectId, [
                                'id' => $issueId,
                                'version' => (int)$current['version'],
                                'overwrite' => '1',
                                'title' => (string)($mine['title'] ?? ''),
                                'description' => (string)($mine['description'] ?? ''),
                                'steps' => (string)($mine['steps'] ?? ''),
                                'priority' => (string)($mine['priority'] ?? ''),
                                'status' => (string)($mine['status'] ?? ''),
                                'assignee_id' => (string)($mine['assignee_id'] ?? ''),
                                'commit_url' => (string)($mine['commit_url'] ?? ''),
                            ]) ?>
                            <button type="submit" class="danger">Ghi đè có chủ đích</button>
                        </form>
                    </div>
                    <p class="muted">“Ghi đè có chủ đích” gửi lại bản của bạn trên phiên bản v<?= (int)$current['version'] ?> và vẫn ghi vào lịch sử. Nếu trong lúc đó có người lưu tiếp, hệ thống sẽ báo xung đột lần nữa.</p>
                </section>
            <?php endif; ?>

            <?php if ($canEdit): ?>
                <details class="edit-block" <?= $errorForm === 'update' && $conflict === null ? 'open' : '' ?>>
                    <summary><b>Sửa công việc</b></summary>
                    <form method="post">
                        <?= hiddenFields('update', $projectId, ['id' => $issueId, 'version' => (int)$detail['version']]) ?>
                        <label class="field">Tiêu đề
                            <input name="title" value="<?= h($value('update', 'title', $detail['title'])) ?>" required maxlength="200">
                        </label>
                        <label class="field">Mô tả
                            <textarea name="description" maxlength="20000"><?= h($value('update', 'description', $detail['description'])) ?></textarea>
                        </label>
                        <label class="field">Bước tái hiện
                            <textarea name="steps" maxlength="20000" <?= (string)$detail['issue_type'] === 'bug' ? 'required' : '' ?>><?= h($value('update', 'steps', $detail['reproduction_steps'])) ?></textarea>
                        </label>
                        <div class="grid">
                            <label class="field">Ưu tiên
                                <?php if ($priorityLocked): ?>
                                    <input type="hidden" name="priority" value="blocker">
                                    <select disabled aria-describedby="priority-lock"><option>Blocker</option></select>
                                    <small id="priority-lock" class="muted">Chỉ kiểm thử viên, quản lý phát hành hoặc quản trị được đổi ưu tiên của công việc Blocker.</small>
                                <?php else: ?>
                                    <select name="priority"><?= enumOptions(ISSUE_PRIORITIES, 'priorityLabel', $value('update', 'priority', $detail['priority'])) ?></select>
                                <?php endif; ?>
                            </label>
                            <label class="field">Trạng thái
                                <select name="status" aria-describedby="status-hint">
                                    <?= enumOptions(
                                        array_values(array_filter(
                                            ISSUE_STATUSES,
                                            static fn(string $s): bool => $s !== 'verified' || (string)$detail['status'] === 'verified'
                                        )),
                                        'statusLabel',
                                        $value('update', 'status', $detail['status'])
                                    ) ?>
                                </select>
                                <small id="status-hint" class="muted">“Đã xác nhận” chỉ đạt được khi kiểm thử viên ghi kết quả Đạt.</small>
                            </label>
                            <label class="field">Người phụ trách
                                <select name="assignee_id">
                                    <option value="">Chưa phân công</option>
                                    <?php $currentAssignee = $value('update', 'assignee_id', (string)$detail['assignee_id']); ?>
                                    <?php foreach ($members as $member): ?>
                                        <option value="<?= (int)$member['id'] ?>" <?= $currentAssignee === (string)$member['id'] ? 'selected' : '' ?>>
                                            <?= h($member['full_name']) ?> (<?= h(roleLabel($member['role'])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <label class="field">Link commit / pull request
                            <input name="commit_url" type="url" value="<?= h($value('update', 'commit_url', $detail['commit_url'])) ?>" maxlength="500" placeholder="https://...">
                        </label>
                        <button type="submit">Lưu (kiểm soát phiên bản)</button>
                    </form>
                </details>
            <?php endif; ?>

            <div class="grid">
                <?php if (canTestIssue($role) && (string)$detail['status'] !== 'closed'): ?>
                    <form method="post" class="panel" aria-labelledby="test-form-title">
                        <h3 id="test-form-title">Ghi kết quả kiểm thử</h3>
                        <?= hiddenFields('test', $projectId, ['id' => $issueId, 'version' => (int)$detail['version']]) ?>
                        <label class="field">Kết quả
                            <select name="result"><?= enumOptions(TEST_RESULTS, 'testResultLabel', $value('test', 'result', 'passed')) ?></select>
                        </label>
                        <p class="muted">Đạt → “Đã xác nhận”. Không đạt / Bị chặn → trả về “Đang xử lý” cho người sửa.</p>
                        <label class="field">Ghi chú
                            <textarea name="notes" maxlength="5000"><?= h($value('test', 'notes', '')) ?></textarea>
                        </label>
                        <button type="submit">Lưu kết quả</button>
                    </form>
                <?php endif; ?>
                <form method="post" class="panel" id="comments" aria-labelledby="comment-form-title">
                    <h3 id="comment-form-title">Bình luận</h3>
                    <?= hiddenFields('comment', $projectId, ['id' => $issueId]) ?>
                    <label class="field">Nội dung
                        <textarea name="body" required maxlength="2000"><?= h($value('comment', 'body', '')) ?></textarea>
                    </label>
                    <button type="submit">Gửi bình luận</button>
                </form>
            </div>

            <h3>Bình luận (<?= count($comments) ?>)</h3>
            <?php if ($comments === []): ?>
                <p class="muted">Chưa có bình luận.</p>
            <?php else: ?>
                <ul class="item-list">
                    <?php foreach ($comments as $comment): ?>
                        <li><b><?= h($comment['full_name']) ?></b>
                            <span class="muted"><?= h(date('d/m/Y H:i', strtotime((string)$comment['created_at']))) ?></span><br>
                            <?= nl2br(h($comment['body'])) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h3>Kết quả kiểm thử</h3>
            <?php if ($testResults === []): ?>
                <p class="muted">Chưa có kết quả kiểm thử.</p>
            <?php else: ?>
                <ul class="item-list">
                    <?php foreach ($testResults as $test): ?>
                        <li>
                            <?= testResultBadge($test['result']) ?>
                            <b><?= h($test['tester_name']) ?></b>
                            <span class="muted"><?= h(date('d/m/Y H:i', strtotime((string)$test['tested_at']))) ?> · phiên bản dữ liệu <?= (int)$test['issue_version'] ?></span>
                            <?php if ($test['is_current'] !== 't'): ?>
                                <span class="pill warn">đã lỗi thời</span>
                            <?php endif; ?>
                            <?php if ((string)$test['notes'] !== ''): ?>
                                <br><?= nl2br(h($test['notes'])) ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h3>Đã nằm trong các phiên bản đã chốt</h3>
            <?php if ($releasedIn === []): ?>
                <p class="muted">Công việc chưa thuộc phiên bản nào đã chốt phát hành.</p>
            <?php else: ?>
                <ul class="item-list">
                    <?php foreach ($releasedIn as $rel): ?>
                        <li><a href="/release-notes.php?id=<?= (int)$rel['id'] ?>"><?= h($rel['version_name']) ?></a>
                            · khi chốt: <?= statusBadge($rel['snapshot_status']) ?>
                            <?= testResultBadge($rel['snapshot_test_result']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h3>Lịch sử thay đổi</h3>
            <?php
            $historyRows = [];
            foreach ($history as $row) {
                $described = History::describe($row);
                if ($described !== null) {
                    $historyRows[] = $described + ['row' => $row];
                }
            }
            ?>
            <?php if ($historyRows === []): ?>
                <p class="muted">Chưa có thay đổi nào được ghi nhận.</p>
            <?php else: ?>
                <ol class="history-list">
                    <?php foreach ($historyRows as $entry): ?>
                        <li>
                            <b><?= h($entry['row']['user_name']) ?></b> <?= h($entry['text']) ?>
                            <span class="muted"><?= h(date('d/m/Y H:i', strtotime((string)$entry['row']['created_at']))) ?></span>
                            <?php if ($entry['changes'] !== []): ?>
                                <ul>
                                    <?php foreach ($entry['changes'] as $change): ?>
                                        <li><?= h($change['field']) ?>: <del><?= h($change['old']) ?></del> → <ins><?= h($change['new']) ?></ins></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (canCreateIssue($role)): ?>
        <section class="panel">
            <details <?= $errorForm === 'create' || $issues === [] ? 'open' : '' ?>>
                <summary><b>+ Báo lỗi hoặc tạo công việc mới</b></summary>
                <form method="post" id="create-form">
                    <?= hiddenFields('create', $projectId) ?>
                    <label class="field">Tiêu đề
                        <input name="title" required maxlength="200" value="<?= h($value('create', 'title', '')) ?>">
                    </label>
                    <div class="grid">
                        <label class="field">Loại
                            <select name="issue_type" id="create-type"><?= enumOptions(ISSUE_TYPES, 'issueTypeLabel', $value('create', 'issue_type', 'bug')) ?></select>
                        </label>
                        <label class="field">Ưu tiên
                            <select name="priority"><?= enumOptions(ISSUE_PRIORITIES, 'priorityLabel', $value('create', 'priority', 'medium')) ?></select>
                        </label>
                        <label class="field">Phân công
                            <select name="assignee_id">
                                <option value="">Chưa phân công</option>
                                <?php $createAssignee = $value('create', 'assignee_id', ''); ?>
                                <?php foreach ($members as $member): ?>
                                    <option value="<?= (int)$member['id'] ?>" <?= $createAssignee === (string)$member['id'] ? 'selected' : '' ?>>
                                        <?= h($member['full_name']) ?> (<?= h(roleLabel($member['role'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <label class="field">Mô tả
                        <textarea name="description" rows="2" maxlength="20000"><?= h($value('create', 'description', '')) ?></textarea>
                    </label>
                    <label class="field" for="create-steps">Bước tái hiện <span class="muted" id="steps-required">(bắt buộc với Lỗi)</span></label>
                    <textarea id="create-steps" name="steps" rows="7" maxlength="20000"
                              aria-describedby="steps-template" placeholder="<?= h($stepsTemplate) ?>"><?= h($value('create', 'steps', '')) ?></textarea>
                    <p class="muted template-hint" id="steps-template">Gợi ý mẫu: Bước tái hiện / Kết quả mong đợi / Kết quả thực tế.
                        <button type="button" class="secondary" data-insert-template>Chèn mẫu</button></p>
                    <label class="field">Link commit / pull request (không bắt buộc)
                        <input name="commit_url" type="url" maxlength="500" placeholder="https://..." value="<?= h($value('create', 'commit_url', '')) ?>">
                    </label>
                    <button type="submit">Tạo mới</button>
                </form>
            </details>
        </section>
    <?php endif; ?>

    <section class="panel" aria-labelledby="list-title">
        <h2 id="list-title">Danh sách công việc (<?= count($issues) ?>)</h2>
        <form method="get" class="filter-bar" role="search" aria-label="Lọc công việc">
            <input type="hidden" name="project_id" value="<?= $projectId ?>">
            <label class="field">Tìm theo tiêu đề hoặc #id
                <input name="q" value="<?= h($filters['q']) ?>" placeholder="ví dụ: đăng nhập, #12">
            </label>
            <label class="field">Trạng thái
                <select name="status"><?= enumOptions(ISSUE_STATUSES, 'statusLabel', $filters['status'], true) ?></select>
            </label>
            <label class="field">Ưu tiên
                <select name="priority"><?= enumOptions(ISSUE_PRIORITIES, 'priorityLabel', $filters['priority'], true) ?></select>
            </label>
            <label class="field">Loại
                <select name="type"><?= enumOptions(ISSUE_TYPES, 'issueTypeLabel', $filters['type'], true) ?></select>
            </label>
            <label class="field">Người phụ trách
                <select name="assignee">
                    <option value="">Tất cả</option>
                    <option value="none" <?= $filters['assignee'] === 'none' ? 'selected' : '' ?>>Chưa phân công</option>
                    <?php foreach ($members as $member): ?>
                        <option value="<?= (int)$member['id'] ?>" <?= $filters['assignee'] === (string)$member['id'] ? 'selected' : '' ?>><?= h($member['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($filters['mine']): ?>
                <input type="hidden" name="mine" value="1">
            <?php endif; ?>
            <div class="filter-actions">
                <button type="submit">Lọc</button>
                <a class="btn secondary toggle-mine" aria-pressed="<?= $filters['mine'] ? 'true' : 'false' ?>"
                   href="<?= h($filterQuery(['mine' => !$filters['mine']])) ?>">Việc của tôi</a>
                <a class="btn secondary" href="/issues.php?project_id=<?= $projectId ?>">Xóa lọc</a>
            </div>
        </form>
        <?php if ($issues === []): ?>
            <?= emptyState(
                'Không có công việc nào phù hợp.',
                $filters === ['status' => '', 'priority' => '', 'type' => '', 'assignee' => '', 'q' => '', 'mine' => false]
                    ? 'Dự án chưa có công việc. Hãy tạo công việc đầu tiên ở trên.'
                    : 'Thử bỏ bớt điều kiện lọc hoặc bấm “Xóa lọc”.'
            ) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>ID</th><th>Tiêu đề</th><th>Loại</th><th>Ưu tiên</th><th>Trạng thái</th><th>Phụ trách</th></tr></thead>
                    <tbody>
                    <?php foreach ($issues as $issue): ?>
                        <tr<?= (int)$issue['id'] === $selected ? ' aria-current="true"' : '' ?>>
                            <td>#<?= (int)$issue['id'] ?></td>
                            <td><a href="<?= h($filterQuery() . '&id=' . (int)$issue['id']) ?>"><?= h($issue['title']) ?></a></td>
                            <td><?= issueTypeBadge($issue['issue_type']) ?></td>
                            <td><?= priorityBadge($issue['priority']) ?></td>
                            <td><?= statusBadge($issue['status']) ?></td>
                            <td><?= h($issue['assignee'] ?? 'Chưa phân công') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<script>
(() => {
    // Bước tái hiện bắt buộc khi loại là "Lỗi"; nút chèn mẫu gợi ý.
    const type = document.getElementById('create-type');
    const steps = document.getElementById('create-steps');
    const note = document.getElementById('steps-required');
    if (!type || !steps) return;
    const sync = () => {
        const isBug = type.value === 'bug';
        steps.required = isBug;
        if (note) note.hidden = !isBug;
    };
    type.addEventListener('change', sync);
    sync();
    const insert = document.querySelector('[data-insert-template]');
    if (insert) {
        insert.addEventListener('click', () => {
            if (steps.value.trim() === '') steps.value = steps.placeholder;
            steps.focus();
        });
    }
})();
</script>
<?php pageEnd(); ?>
