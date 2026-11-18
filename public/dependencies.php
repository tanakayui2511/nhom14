<?php

declare(strict_types=1);

require __DIR__ . '/../src/DependencyService.php';

$user = requireLogin();
$connection = db();
[$projects, $projectId, $role] = selectProject($connection, $user);
$error = '';
$cycleError = null;
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    try {
        if ((int)($_POST['project_id'] ?? 0) <= 0) {
            throw new ValidationException('Dự án không hợp lệ.');
        }
        if (!hasProjectRole($role, ['developer', 'release_manager'])) {
            throw new ForbiddenException('Bạn không có quyền thay đổi quan hệ phụ thuộc.');
        }
        $action = (string)($_POST['action'] ?? 'add');
        $issueId = postInt($_POST, 'issue_id', 1, PHP_INT_MAX, 'Công việc');
        $dependsOnId = postInt($_POST, 'depends_on_id', 1, PHP_INT_MAX, 'Công việc tiên quyết');
        if ($action === 'remove') {
            DependencyService::remove($connection, $projectId, $issueId, $dependsOnId, (int)$user['id']);
            flash('ok', 'Đã gỡ quan hệ: #' . $issueId . ' không còn phụ thuộc #' . $dependsOnId . '.');
        } else {
            DependencyService::add($connection, $projectId, $issueId, $dependsOnId, (int)$user['id']);
            flash('ok', 'Đã thêm quan hệ: #' . $issueId . ' phụ thuộc #' . $dependsOnId . '.');
        }
        redirect('/dependencies.php?project_id=' . $projectId);
    } catch (CycleException $exception) {
        $error = 'Từ chối: thêm quan hệ này sẽ tạo vòng phụ thuộc '
            . DependencyService::formatChain($exception->cycle) . '.';
        $cycleError = $exception;
        $old = $_POST;
    } catch (Throwable $exception) {
        $error = userMessage($exception);
        $old = $_POST;
    }
}

$issues = $projectId > 0
    ? queryAll(
        $connection,
        "SELECT i.id,i.title,i.description,i.issue_type,i.priority,i.status,
                COALESCE(u.full_name,'') AS assignee
         FROM issues i
         LEFT JOIN users u ON u.id=i.assignee_id
         WHERE i.project_id=$1 ORDER BY i.id",
        [(string)$projectId]
    )
    : [];
$dependencies = $projectId > 0 ? DependencyService::listForProject($connection, $projectId) : [];
$blocked = $projectId > 0 ? DependencyService::blockedForProject($connection, $projectId) : [];
$cycle = $projectId > 0 ? DependencyService::projectCycle($connection, $projectId) : null;
$canChange = hasProjectRole($role, ['developer', 'release_manager']);
$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$priorityFilter = trim((string)($_GET['priority'] ?? ''));
$blockedFilter = (string)($_GET['blocked'] ?? '') === '1';
$completeFilter = (string)($_GET['complete'] ?? '') === '1';
$blockedIds = [];
foreach ($blocked as $blockedRow) {
    $blockedIds[(int)$blockedRow['id']] = true;
}
$visibleIssues = array_values(array_filter($issues, static function (array $issue) use (
    $search,
    $statusFilter,
    $priorityFilter,
    $blockedFilter,
    $completeFilter,
    $blockedIds
): bool {
    $id = (int)$issue['id'];
    $matchesSearch = $search === ''
        || str_contains((string)$issue['title'], $search)
        || str_contains((string)$id, $search);
    $matchesStatus = $statusFilter === '' || (string)$issue['status'] === $statusFilter;
    $matchesPriority = $priorityFilter === '' || (string)$issue['priority'] === $priorityFilter;
    $matchesBlocked = !$blockedFilter || isset($blockedIds[$id]);
    $matchesComplete = !$completeFilter || in_array((string)$issue['status'], ['verified', 'closed'], true);
    return $matchesSearch && $matchesStatus && $matchesPriority && $matchesBlocked && $matchesComplete;
}));
$visibleIds = [];
foreach ($visibleIssues as $issue) {
    $visibleIds[(int)$issue['id']] = true;
}
$visibleDependencies = array_values(array_filter($dependencies, static function (array $dependency) use ($visibleIds): bool {
    return isset($visibleIds[(int)$dependency['issue_id']])
        && isset($visibleIds[(int)$dependency['depends_on_id']]);
}));
$graphNodes = array_map(static function (array $issue) use ($blockedIds, $projectId): array {
    $id = (int)$issue['id'];
    return [
        'id' => $id,
        'title' => (string)$issue['title'],
        'description' => (string)$issue['description'],
        'issue_type' => (string)$issue['issue_type'],
        'priority' => (string)$issue['priority'],
        'status' => (string)$issue['status'],
        'assignee' => (string)$issue['assignee'],
        'is_blocked' => isset($blockedIds[$id]),
        'url' => '/issues.php?project_id=' . (int)$projectId . '&id=' . $id,
    ];
}, $visibleIssues);
// Edges on the detected cycle are drawn dashed in the graph (presentation only).
$cycleEdges = [];
if ($cycle !== null) {
    for ($i = 0, $n = count($cycle) - 1; $i < $n; $i++) {
        $cycleEdges[(int)$cycle[$i] . '>' . (int)$cycle[$i + 1]] = true;
    }
}
$graphEdges = array_map(static function (array $dependency) use ($cycleEdges): array {
    $source = (int)$dependency['issue_id'];
    $target = (int)$dependency['depends_on_id'];
    return [
        'source' => $source,
        'target' => $target,
        'in_cycle' => isset($cycleEdges[$source . '>' . $target]),
    ];
}, $visibleDependencies);
$blockedCount = count(array_filter($visibleIssues, static fn(array $issue): bool => isset($blockedIds[(int)$issue['id']])));
$completeCount = count(array_filter($visibleIssues, static fn(array $issue): bool => in_array((string)$issue['status'], ['verified', 'closed'], true)));
$statusOptions = ISSUE_STATUSES;
$priorityOptions = ISSUE_PRIORITIES;

pageStart('Quan hệ phụ thuộc');
if ($error !== '') {
    echo '<p class="error" role="alert">' . nl2br(h($error)) . '</p>';
}
if ($cycleError !== null) {
    // Tô đậm từng mắt xích của chuỗi gây vòng.
    echo '<section class="panel conflict-panel" aria-label="Chuỗi gây vòng"><b>Chuỗi gây vòng:</b><ol class="cycle-chain">';
    foreach ($cycleError->cycle as $id) {
        $title = $cycleError->titles[$id] ?? '';
        echo '<li><mark>#' . (int)$id . ($title !== '' ? ' ' . h($title) : '') . '</mark></li>';
    }
    echo '</ol><p class="muted">Mắt xích cuối là quan hệ bạn vừa định thêm, quay về công việc đầu chuỗi. '
        . 'Hãy gỡ một quan hệ trong chuỗi hoặc chọn quan hệ khác.</p></section>';
}
?>
<?php if ($projects === []): ?>
    <section class="panel"><p>Bạn chưa thuộc dự án nào.</p></section>
<?php else: ?>
    <section class="panel">
        <p class="muted">A phụ thuộc B nghĩa là B cần hoàn thành trước khi A có thể tiếp tục.</p>
        <?php if ($cycle !== null): ?>
            <p class="error">Phát hiện chu trình:
                <?= h(DependencyService::formatChain($cycle)) ?>
            </p>
        <?php else: ?>
            <p class="ok">Đồ thị phụ thuộc hiện tại không có chu trình.</p>
        <?php endif; ?>
    </section>

    <section class="panel dependency-graph-panel" aria-labelledby="dependency-graph-title">
        <div class="section-heading">
            <div>
                <h2 id="dependency-graph-title">Đồ thị phụ thuộc</h2>
                <p class="muted">Mũi tên đi từ công việc phụ thuộc đến công việc tiên quyết.</p>
            </div>
            <a class="btn secondary" href="/issues.php?project_id=<?= $projectId ?>">Danh sách công việc</a>
        </div>

        <div class="summary-grid" aria-label="Tóm tắt graph">
            <div class="summary-card tone-indigo"><strong><?= count($visibleIssues) ?></strong><span>Công việc trong đồ thị</span></div>
            <div class="summary-card tone-violet"><strong><?= count($visibleDependencies) ?></strong><span>Quan hệ</span></div>
            <div class="summary-card tone-rose"><strong><?= $blockedCount ?></strong><span>Đang bị chặn</span></div>
            <div class="summary-card tone-emerald"><strong><?= $completeCount ?></strong><span>Đã hoàn thành</span></div>
            <div class="summary-card tone-sky"><strong><?= count($visibleIssues) - $completeCount ?></strong><span>Chưa hoàn thành</span></div>
        </div>

        <form class="graph-filters" method="get" role="search">
            <input type="hidden" name="project_id" value="<?= $projectId ?>">
            <label class="field">Tìm công việc
                <input name="q" value="<?= h($search) ?>" placeholder="#123 hoặc tiêu đề">
            </label>
            <label class="field">Trạng thái
                <select name="status">
                    <option value="">Tất cả</option>
                    <?php foreach ($statusOptions as $status): ?>
                        <option value="<?= h($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= h(statusLabel($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">Ưu tiên
                <select name="priority">
                    <option value="">Tất cả</option>
                    <?php foreach ($priorityOptions as $priority): ?>
                        <option value="<?= h($priority) ?>" <?= $priorityFilter === $priority ? 'selected' : '' ?>><?= h(priorityLabel($priority)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="checkbox-field"><input type="checkbox" name="blocked" value="1" <?= $blockedFilter ? 'checked' : '' ?>> Chỉ đang bị chặn</label>
            <label class="checkbox-field"><input type="checkbox" name="complete" value="1" <?= $completeFilter ? 'checked' : '' ?>> Chỉ đã hoàn thành</label>
            <button type="submit">Lọc</button>
            <a class="btn secondary" href="/dependencies.php?project_id=<?= $projectId ?>">Xóa lọc</a>
        </form>

        <?php if ($visibleIssues === []): ?>
            <div class="empty-state">
                <svg class="empty-illus" viewBox="0 0 96 72" aria-hidden="true" focusable="false">
                    <ellipse class="ei-shadow" cx="48" cy="64" rx="34" ry="6"/>
                    <path class="ei-box" d="M18 30h60v28a4 4 0 0 1-4 4H22a4 4 0 0 1-4-4z"/>
                    <path class="ei-flap" d="M18 30 30 16h36l12 14z"/>
                    <path class="ei-line" d="M36 44h24M40 51h16"/>
                    <circle class="ei-spark" cx="80" cy="12" r="3"/>
                    <circle class="ei-spark" cx="14" cy="18" r="2"/>
                </svg>
                <p class="empty-title">Không có công việc phù hợp với bộ lọc hiện tại.</p>
                <p class="muted">Thử bỏ bớt điều kiện lọc hoặc bấm “Xóa lọc”.</p>
            </div>
        <?php else: ?>
            <div class="graph-toolbar" aria-label="Công cụ graph">
                <button type="button" class="secondary" data-graph-zoom="in" title="Phóng to">+</button>
                <button type="button" class="secondary" data-graph-zoom="out" title="Thu nhỏ">−</button>
                <button type="button" class="secondary" data-graph-zoom="fit" title="Vừa khung">Vừa khung</button>
                <button type="button" class="secondary" data-graph-zoom="reset" title="Đặt lại">Đặt lại</button>
                <span class="muted" id="graph-selection-status" aria-live="polite">Chọn một node để xem chi tiết.</span>
            </div>
            <div class="graph-layout">
                <div class="graph-viewport" data-graph-viewport tabindex="0" aria-label="Đồ thị dependency có thể phóng to và thu nhỏ">
                    <svg class="dependency-graph" data-graph-svg role="img" aria-labelledby="dependency-graph-title dependency-graph-description">
                        <desc id="dependency-graph-description">Các node là issue. Mũi tên đi từ issue phụ thuộc đến issue tiên quyết.</desc>
                        <defs>
                            <marker id="dependency-arrow" markerWidth="10" markerHeight="10" refX="9" refY="3" orient="auto" markerUnits="strokeWidth">
                                <path class="graph-arrow" d="M0,0 L0,6 L9,3 z"></path>
                            </marker>
                            <marker id="dependency-arrow-active" markerWidth="10" markerHeight="10" refX="9" refY="3" orient="auto" markerUnits="strokeWidth">
                                <path class="graph-arrow-active" d="M0,0 L0,6 L9,3 z"></path>
                            </marker>
                            <marker id="dependency-arrow-cycle" markerWidth="10" markerHeight="10" refX="9" refY="3" orient="auto" markerUnits="strokeWidth">
                                <path class="graph-arrow-cycle" d="M0,0 L0,6 L9,3 z"></path>
                            </marker>
                        </defs>
                        <g data-graph-layer></g>
                    </svg>
                </div>
                <aside class="graph-detail" data-graph-detail aria-live="polite">
                    <h3>Chi tiết issue</h3>
                    <p class="muted">Chọn một node để xem title, trạng thái và các quan hệ trực tiếp.</p>
                </aside>
            </div>
            <div class="graph-legend" aria-label="Chú giải trạng thái">
                <span><i class="status-dot status-open"></i> <?= h(statusLabel('open')) ?></span>
                <span><i class="status-dot status-in_progress"></i> <?= h(statusLabel('in_progress')) ?></span>
                <span><i class="status-dot status-resolved"></i> <?= h(statusLabel('resolved')) ?></span>
                <span><i class="status-dot status-retest"></i> <?= h(statusLabel('retest')) ?></span>
                <span><i class="status-dot status-verified"></i> <?= h(statusLabel('verified')) ?></span>
                <span><i class="status-dot status-closed"></i> <?= h(statusLabel('closed')) ?></span>
                <span><i class="status-dot status-blocked"></i> Đang bị chặn</span>
                <span><i class="graph-legend-cycle"></i> cạnh thuộc chu trình</span>
            </div>
            <details class="graph-fallback">
                <summary>Bảng quan hệ (dạng văn bản)</summary>
                <?php if ($visibleDependencies === []): ?>
                    <p class="muted">Chưa có quan hệ phụ thuộc trong phạm vi lọc.</p>
                <?php else: ?>
                    <div class="table-wrap"><table>
                        <thead><tr><th>Công việc</th><th>Quan hệ</th><th>Phải xong trước</th></tr></thead>
                        <tbody>
                        <?php foreach ($visibleDependencies as $dependency): ?>
                            <tr>
                                <td><a href="/issues.php?project_id=<?= $projectId ?>&amp;id=<?= (int)$dependency['issue_id'] ?>">#<?= (int)$dependency['issue_id'] ?> <?= h($dependency['issue_title']) ?></a></td>
                                <td>phụ thuộc vào</td>
                                <td><a href="/issues.php?project_id=<?= $projectId ?>&amp;id=<?= (int)$dependency['depends_on_id'] ?>">#<?= (int)$dependency['depends_on_id'] ?> <?= h($dependency['dependency_title']) ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </details>
        <?php endif; ?>
    </section>

    <?php if ($canChange && $issues !== []): ?>
        <section class="panel">
            <h2>Thêm quan hệ phụ thuộc</h2>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <div class="grid">
                    <label class="field">Công việc A
                        <select name="issue_id" required>
                            <?php foreach ($issues as $issue): ?>
                                <option value="<?= (int)$issue['id'] ?>" <?= (string)($old['issue_id'] ?? '') === (string)$issue['id'] ? 'selected' : '' ?>>#<?= (int)$issue['id'] ?> · <?= h($issue['title']) ?> (<?= h(statusLabel($issue['status'])) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">phụ thuộc công việc B (B phải xong trước)
                        <select name="depends_on_id" required>
                            <?php foreach ($issues as $issue): ?>
                                <option value="<?= (int)$issue['id'] ?>" <?= (string)($old['depends_on_id'] ?? '') === (string)$issue['id'] ? 'selected' : '' ?>>#<?= (int)$issue['id'] ?> · <?= h($issue['title']) ?> (<?= h(statusLabel($issue['status'])) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <p class="muted">Trước khi lưu, hệ thống duyệt đồ thị (DFS) để kiểm tra vòng; quan hệ tạo vòng bị từ chối và chỉ rõ chuỗi gây vòng.</p>
                <button type="submit">Kiểm tra và thêm</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Đang chặn tiến độ</h2>
        <?php
        $blockedGroups = [];
        foreach ($blocked as $row) {
            $blockedGroups[(int)$row['id']]['title'] = (string)$row['title'];
            $blockedGroups[(int)$row['id']]['by'][] = $row;
        }
        ?>
        <?php if ($blockedGroups === []): ?>
            <p class="ok">Không có công việc nào đang bị chặn theo trạng thái hiện tại.</p>
        <?php else: ?>
            <p class="muted"><?= count($blockedGroups) ?> công việc đang chờ công việc tiên quyết.</p>
            <ul class="blocked-groups">
                <?php foreach ($blockedGroups as $blockedId => $group): ?>
                    <li>
                        <a href="/issues.php?project_id=<?= $projectId ?>&amp;id=<?= $blockedId ?>"><b>#<?= $blockedId ?> <?= h($group['title']) ?></b></a>
                        đang chờ:
                        <ul>
                            <?php foreach ($group['by'] as $row): ?>
                                <li><a href="/issues.php?project_id=<?= $projectId ?>&amp;id=<?= (int)$row['blocked_by_id'] ?>">#<?= (int)$row['blocked_by_id'] ?> <?= h($row['blocked_by']) ?></a>
                                    <?= statusBadge($row['dependency_status']) ?></li>
                            <?php endforeach; ?>
                        </ul>
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
            <ul>
                <?php foreach ($dependencies as $dependency): ?>
                    <li>
                        #<?= (int)$dependency['issue_id'] ?> <?= h($dependency['issue_title']) ?>
                        phụ thuộc #<?= (int)$dependency['depends_on_id'] ?> <?= h($dependency['dependency_title']) ?>
                        <?= statusBadge($dependency['dependency_status']) ?>
                        <?php if ($canChange): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                                <input type="hidden" name="issue_id" value="<?= (int)$dependency['issue_id'] ?>">
                                <input type="hidden" name="depends_on_id" value="<?= (int)$dependency['depends_on_id'] ?>">
                                <button class="danger" type="submit" aria-label="Gỡ quan hệ #<?= (int)$dependency['issue_id'] ?> phụ thuộc #<?= (int)$dependency['depends_on_id'] ?>">Gỡ</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php if ($visibleIssues !== []): ?>
<script>
(() => {
    const nodes = <?= json_encode($graphNodes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const labels = <?= json_encode(['status' => STATUS_LABELS + ['blocked' => 'Đang bị chặn'], 'priority' => PRIORITY_LABELS, 'type' => ISSUE_TYPE_LABELS], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    const lbl = (kind, value) => (labels[kind] && labels[kind][value]) || value;
    const edges = <?= json_encode($graphEdges, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const svg = document.querySelector('[data-graph-svg]');
    const layer = document.querySelector('[data-graph-layer]');
    const detail = document.querySelector('[data-graph-detail]');
    const selectionStatus = document.querySelector('#graph-selection-status');
    const viewport = document.querySelector('[data-graph-viewport]');
    if (!svg || !layer || !detail) return;

    const nodeMap = new Map(nodes.map((node) => [node.id, node]));
    const positions = new Map();
    const cardWidth = 190;
    const cardHeight = 86;
    const gapX = 42;
    const gapY = 52;
    const columns = Math.max(1, Math.min(4, Math.ceil(Math.sqrt(nodes.length))));
    const rows = Math.ceil(nodes.length / columns);
    const width = Math.max(760, columns * (cardWidth + gapX) + 80);
    const height = Math.max(260, rows * (cardHeight + gapY) + 80);
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.setAttribute('width', width);
    svg.setAttribute('height', height);

    const esc = (value) => String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));
    nodes.forEach((node, index) => {
        const col = index % columns;
        const row = Math.floor(index / columns);
        positions.set(node.id, {
            x: 40 + col * (cardWidth + gapX),
            y: 30 + row * (cardHeight + gapY),
        });
    });

    const draw = (selectedId = null) => {
        const visibleEdges = edges.filter((edge) => positions.has(edge.source) && positions.has(edge.target));
        const edgeMarkup = visibleEdges.map((edge) => {
            const source = positions.get(edge.source);
            const target = positions.get(edge.target);
            const highlighted = selectedId !== null && (edge.source === selectedId || edge.target === selectedId);
            const startX = source.x + cardWidth / 2;
            const startY = source.y + cardHeight;
            const endX = target.x + cardWidth / 2;
            const endY = target.y;
            const midY = (startY + endY) / 2;
            return `<path class="graph-edge${highlighted ? ' is-highlighted' : ''}${edge.in_cycle ? ' is-cycle' : ''}" d="M ${startX} ${startY} C ${startX} ${midY}, ${endX} ${midY}, ${endX} ${endY}" data-source="${edge.source}" data-target="${edge.target}" />`;
        }).join('');
        const nodeMarkup = nodes.map((node) => {
            const point = positions.get(node.id);
            const selected = node.id === selectedId;
            const statusClass = node.is_blocked ? 'blocked' : node.status;
            return `<g class="graph-node status-${esc(statusClass)}${selected ? ' is-selected' : ''}" tabindex="0" role="button" aria-label="Issue #${node.id}: ${esc(node.title)}" data-node-id="${node.id}" transform="translate(${point.x} ${point.y})">
                <rect width="${cardWidth}" height="${cardHeight}" rx="10"></rect>
                <circle class="graph-node-dot" cx="${cardWidth - 16}" cy="16" r="5"></circle>
                <text class="graph-node-id" x="12" y="20">#${node.id} · ${esc(lbl('priority', node.priority))}</text>
                <text class="graph-node-title" x="12" y="42">${esc(node.title).slice(0, 28)}</text>
                <text class="graph-node-status" x="12" y="64">${esc(lbl('status', node.is_blocked ? 'blocked' : node.status))}</text>
            </g>`;
        }).join('');
        layer.innerHTML = edgeMarkup + nodeMarkup;
        layer.querySelectorAll('[data-node-id]').forEach((element) => {
            const id = Number(element.dataset.nodeId);
            const select = () => selectNode(id);
            element.addEventListener('click', select);
            element.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    select();
                }
            });
            // Hover/focus highlights the node and its direct edges; others fade.
            element.addEventListener('mouseenter', () => emphasize(id));
            element.addEventListener('focus', () => emphasize(id));
            element.addEventListener('mouseleave', () => emphasize(selectedNodeId));
            element.addEventListener('blur', () => emphasize(selectedNodeId));
        });
        emphasize(selectedId);
    };

    let selectedNodeId = null;
    const emphasize = (id) => {
        const related = new Set();
        if (id !== null) {
            related.add(id);
            edges.forEach((edge) => {
                if (edge.source === id) related.add(edge.target);
                if (edge.target === id) related.add(edge.source);
            });
        }
        layer.classList.toggle('graph-layer-focus', id !== null);
        layer.querySelectorAll('[data-node-id]').forEach((element) => {
            element.classList.toggle('is-related', related.has(Number(element.dataset.nodeId)));
        });
        layer.querySelectorAll('.graph-edge').forEach((element) => {
            const touches = Number(element.dataset.source) === id || Number(element.dataset.target) === id;
            element.classList.toggle('is-related', id !== null && touches);
        });
    };

    const selectNode = (id) => {
        const node = nodeMap.get(id);
        if (!node) return;
        const dependencies = edges.filter((edge) => edge.source === id).map((edge) => nodeMap.get(edge.target)).filter(Boolean);
        const dependents = edges.filter((edge) => edge.target === id).map((edge) => nodeMap.get(edge.source)).filter(Boolean);
        const list = (items) => items.length
            ? `<ul>${items.map((item) => `<li><a href="${esc(item.url)}">#${item.id} ${esc(item.title)}</a></li>`).join('')}</ul>`
            : '<p class="muted">Không có.</p>';
        const chain = (currentId, seen = new Set()) => {
            if (seen.has(currentId)) return '<li class="cycle-warning">Phát hiện vòng</li>';
            const nextSeen = new Set(seen);
            nextSeen.add(currentId);
            const next = edges.filter((edge) => edge.source === currentId).map((edge) => nodeMap.get(edge.target)).filter(Boolean);
            if (!next.length) return '';
            return `<ul>${next.map((item) => `<li><a href="${esc(item.url)}">#${item.id} ${esc(item.title)}</a>${chain(item.id, nextSeen)}</li>`).join('')}</ul>`;
        };
        detail.innerHTML = `<h3>#${node.id} ${esc(node.title)}</h3>
            <p><span class="pill status-${esc(node.is_blocked ? 'blocked' : node.status)}">${esc(lbl('status', node.is_blocked ? 'blocked' : node.status))}</span> · ${esc(lbl('priority', node.priority))} · ${esc(lbl('type', node.issue_type))}</p>
            <p>${esc(node.description || 'Chưa có mô tả.')}</p>
            <p><strong>Phụ trách:</strong> ${esc(node.assignee || 'Chưa phân công')}</p>
            <h4>Tiên quyết trực tiếp</h4>${list(dependencies)}
            <h4>Chuỗi tiên quyết</h4>${chain(id) || '<p class="muted">Không có công việc tiên quyết trong đồ thị hiện tại.</p>'}
            <h4>Công việc phụ thuộc vào issue này</h4>${list(dependents)}
            <a class="btn secondary" href="${esc(node.url)}">Mở chi tiết công việc</a>`;
        selectionStatus.textContent = `Đang chọn công việc #${node.id}`;
        selectedNodeId = id;
        draw(id);
    };

    let scale = 1;
    let panX = 0;
    let panY = 0;
    const applyTransform = () => { layer.setAttribute('transform', `translate(${panX} ${panY}) scale(${scale})`); };
    document.querySelectorAll('[data-graph-zoom]').forEach((button) => button.addEventListener('click', () => {
        const action = button.dataset.graphZoom;
        if (action === 'in') scale = Math.min(1.8, scale + 0.15);
        if (action === 'out') scale = Math.max(0.65, scale - 0.15);
        if (action === 'reset') { scale = 1; panX = 0; panY = 0; }
        if (action === 'fit') { scale = Math.min(1, 900 / width); panX = 0; panY = 0; }
        applyTransform();
    }));
    if (viewport) {
        let drag = null;
        viewport.addEventListener('pointerdown', (event) => {
            if (event.target.closest('[data-node-id]')) return;
            drag = { x: event.clientX, y: event.clientY, panX, panY };
            viewport.setPointerCapture(event.pointerId);
            viewport.style.cursor = 'grabbing';
        });
        viewport.addEventListener('pointermove', (event) => {
            if (!drag) return;
            panX = drag.panX + event.clientX - drag.x;
            panY = drag.panY + event.clientY - drag.y;
            applyTransform();
        });
        const stopDrag = () => { drag = null; viewport.style.cursor = 'grab'; };
        viewport.addEventListener('pointerup', stopDrag);
        viewport.addEventListener('pointercancel', stopDrag);
    }
    draw();
})();
</script>
<?php endif; ?>
<?php pageEnd(); ?>
