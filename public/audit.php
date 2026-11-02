<?php

declare(strict_types=1);

require __DIR__ . '/../src/App.php';

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
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId === 0 && $projects !== []) {
    $projectId = (int)$projects[0]['id'];
}
$role = null;
if ($projectId > 0) {
    requireProjectAccess($connection, $projectId, $user);
    $role = projectRole($connection, $projectId, $user);
    if (!canViewAudit($role)) {
        http_response_code(403);
        exit('Chỉ release manager hoặc admin được xem lịch sử kiểm toán.');
    }
}
$actionFilter = trim((string)($_GET['action'] ?? ''));
$entityFilter = trim((string)($_GET['entity_type'] ?? ''));
$params = [(string)$projectId];
$where = 'WHERE a.project_id=$1';
if ($actionFilter !== '') {
    $params[] = $actionFilter;
    $where .= ' AND a.action=$' . count($params);
}
if ($entityFilter !== '') {
    $params[] = $entityFilter;
    $where .= ' AND a.entity_type=$' . count($params);
}
$rows = $projectId > 0
    ? queryAll(
        $connection,
        'SELECT a.*,u.full_name AS user_name,u.username
         FROM audit_log a
         LEFT JOIN users u ON u.id=a.user_id
         ' . $where . '
         ORDER BY a.id DESC
         LIMIT 200',
        $params
    )
    : [];

pageStart('Lịch sử kiểm toán');
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
                            <?= h($project['name']) ?> (<?= h($project['role']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">Hành động<input name="action" value="<?= h($actionFilter) ?>" placeholder="release_created"></label>
            <label class="field">Loại đối tượng
                <select name="entity_type">
                    <option value="">Tất cả</option>
                    <?php foreach (['project', 'issue', 'comment', 'test_result', 'dependency', 'release', 'user'] as $entityType): ?>
                        <option value="<?= h($entityType) ?>" <?= $entityFilter === $entityType ? 'selected' : '' ?>><?= h($entityType) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button>Lọc</button>
        </form>
        <p class="muted">Hiển thị tối đa 200 bản ghi mới nhất. Ai làm gì, lúc nào, trên đối tượng nào.</p>
    </section>

    <section class="panel">
        <?php if ($rows === []): ?>
            <p class="muted">Không có bản ghi phù hợp.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Thời điểm</th><th>Người dùng</th><th>Hành động</th><th>Đối tượng</th><th>Chi tiết</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= h(date('d/m/Y H:i:s', strtotime((string)$row['created_at']))) ?></td>
                            <td><?= h($row['full_name'] ?? $row['username'] ?? 'Hệ thống') ?></td>
                            <td><code><?= h($row['action']) ?></code></td>
                            <td><?= h($row['entity_type']) ?> #<?= h($row['entity_id']) ?></td>
                            <td>
                                <?php
                                $details = json_decode((string)$row['details'], true);
                                if (is_array($details) && $details !== []) {
                                    echo '<pre>' . h((string)json_encode(
                                        $details,
                                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                    )) . '</pre>';
                                } else {
                                    echo '<span class="muted">—</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php pageEnd(); ?>
