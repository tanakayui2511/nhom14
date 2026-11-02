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
$graphEdges = array_map(static function (array $dependency): array {
    return [
        'source' => (int)$dependency['issue_id'],
        'target' => (int)$dependency['depends_on_id'],
    ];
}, $visibleDependencies);
$blockedCount = count(array_filter($visibleIssues, static fn(array $issue): bool => isset($blockedIds[(int)$issue['id']])));
$completeCount = count(array_filter($visibleIssues, static fn(array $issue): bool => in_array((string)$issue['status'], ['verified', 'closed'], true)));
$statusOptions = issueStatuses();
$priorityOptions = issuePriorities();

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

    <section class="panel dependency-graph-panel" aria-labelledby="dependency-graph-title">
        <div class="section-heading">
            <div>
                <h2 id="dependency-graph-title">Dependency Graph</h2>
                <p class="muted">Mũi tên đi từ công việc phụ thuộc đến công việc tiên quyết.</p>
            </div>
            <a class="btn secondary" href="/projects.php?id=<?= $projectId ?>">Quay lại project</a>
        </div>

        <div class="summary-grid" aria-label="Tóm tắt graph">
            <div class="summary-card"><strong><?= count($visibleIssues) ?></strong><span>Issue trong graph</span></div>
            <div class="summary-card"><strong><?= count($visibleDependencies) ?></strong><span>Dependency</span></div>
            <div class="summary-card"><strong><?= $blockedCount ?></strong><span>Đang bị chặn</span></div>
            <div class="summary-card"><strong><?= $completeCount ?></strong><span>Đã hoàn thành</span></div>
            <div class="summary-card"><strong><?= count($visibleIssues) - $completeCount ?></strong><span>Chưa hoàn thành</span></div>
        </div>

        <form class="graph-filters" method="get" role="search">
            <input type="hidden" name="project_id" value="<?= $projectId ?>">
            <label class="field">Tìm issue
                <input name="q" value="<?= h($search) ?>" placeholder="#123 hoặc tiêu đề">
            </label>
            <label class="field">Trạng thái
                <select name="status">
                    <option value="">Tất cả</option>
                    <?php foreach ($statusOptions as $status): ?>
                        <option value="<?= h($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">Priority
                <select name="priority">
                    <option value="">Tất cả</option>
                    <?php foreach ($priorityOptions as $priority): ?>
                        <option value="<?= h($priority) ?>" <?= $priorityFilter === $priority ? 'selected' : '' ?>><?= h($priority) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="checkbox-field"><input type="checkbox" name="blocked" value="1" <?= $blockedFilter ? 'checked' : '' ?>> Chỉ đang bị chặn</label>
            <label class="checkbox-field"><input type="checkbox" name="complete" value="1" <?= $completeFilter ? 'checked' : '' ?>> Chỉ đã hoàn thành</label>
            <button type="submit">Lọc graph</button>
            <a class="btn secondary" href="/dependencies.php?project_id=<?= $projectId ?>">Xóa lọc</a>
        </form>

        <?php if ($visibleIssues === []): ?>
            <div class="empty-state"><p>Không có issue phù hợp với bộ lọc hiện tại.</p></div>
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
                                <path d="M0,0 L0,6 L9,3 z" fill="currentColor"></path>
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
                <span><i class="status-dot status-open"></i> open</span>
                <span><i class="status-dot status-in_progress"></i> in_progress</span>
                <span><i class="status-dot status-retest"></i> retest</span>
                <span><i class="status-dot status-verified"></i> verified</span>
                <span><i class="status-dot status-closed"></i> closed</span>
                <span><i class="status-dot status-blocked"></i> blocked</span>
            </div>
            <details class="graph-fallback">
                <summary>Bảng dependency dự phòng</summary>
                <?php if ($visibleDependencies === []): ?>
                    <p class="muted">Project chưa có dependency trong phạm vi lọc.</p>
                <?php else: ?>
                    <div class="table-wrap"><table>
                        <thead><tr><th>Issue</th><th>Quan hệ</th><th>Tiên quyết</th></tr></thead>
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
            <ul>
                <?php foreach ($blocked as $row): ?>
                    <li>#<?= (int)$row['id'] ?> <?= h($row['title']) ?>
                        đang chờ #<?= (int)$row['blocked_by_id'] ?> <?= h($row['blocked_by']) ?>
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
            <ul>
                <?php foreach ($dependencies as $dependency): ?>
                    <li>
                        #<?= (int)$dependency['issue_id'] ?> <?= h($dependency['issue_title']) ?>
                        phụ thuộc #<?= (int)$dependency['depends_on_id'] ?> <?= h($dependency['dependency_title']) ?>
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
<?php if ($visibleIssues !== []): ?>
<script>
(() => {
    const nodes = <?= json_encode($graphNodes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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
            return `<path class="graph-edge${highlighted ? ' is-highlighted' : ''}" d="M ${startX} ${startY} C ${startX} ${midY}, ${endX} ${midY}, ${endX} ${endY}" data-source="${edge.source}" data-target="${edge.target}" />`;
        }).join('');
        const nodeMarkup = nodes.map((node) => {
            const point = positions.get(node.id);
            const selected = node.id === selectedId;
            const statusClass = node.is_blocked ? 'blocked' : node.status;
            return `<g class="graph-node status-${esc(statusClass)}${selected ? ' is-selected' : ''}" tabindex="0" role="button" aria-label="Issue #${node.id}: ${esc(node.title)}" data-node-id="${node.id}" transform="translate(${point.x} ${point.y})">
                <rect width="${cardWidth}" height="${cardHeight}" rx="8"></rect>
                <text class="graph-node-id" x="12" y="20">#${node.id} · ${esc(node.priority)}</text>
                <text class="graph-node-title" x="12" y="42">${esc(node.title).slice(0, 28)}</text>
                <text class="graph-node-status" x="12" y="64">${esc(node.is_blocked ? 'blocked' : node.status)}</text>
            </g>`;
        }).join('');
        layer.innerHTML = edgeMarkup + nodeMarkup;
        layer.querySelectorAll('[data-node-id]').forEach((element) => {
            const select = () => selectNode(Number(element.dataset.nodeId));
            element.addEventListener('click', select);
            element.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    select();
                }
            });
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
            if (seen.has(currentId)) return '<li class="cycle-warning">Cycle detected</li>';
            const nextSeen = new Set(seen);
            nextSeen.add(currentId);
            const next = edges.filter((edge) => edge.source === currentId).map((edge) => nodeMap.get(edge.target)).filter(Boolean);
            if (!next.length) return '';
            return `<ul>${next.map((item) => `<li><a href="${esc(item.url)}">#${item.id} ${esc(item.title)}</a>${chain(item.id, nextSeen)}</li>`).join('')}</ul>`;
        };
        detail.innerHTML = `<h3>#${node.id} ${esc(node.title)}</h3>
            <p><span class="pill status-${esc(node.is_blocked ? 'blocked' : node.status)}">${esc(node.is_blocked ? 'blocked' : node.status)}</span> · ${esc(node.priority)} · ${esc(node.issue_type)}</p>
            <p>${esc(node.description || 'Chưa có mô tả.')}</p>
            <p><strong>Assignee:</strong> ${esc(node.assignee || 'Chưa phân công')}</p>
            <h4>Tiên quyết trực tiếp</h4>${list(dependencies)}
            <h4>Chuỗi blocker</h4>${chain(id) || '<p class="muted">Không có blocker trong graph hiện tại.</p>'}
            <h4>Công việc phụ thuộc vào issue này</h4>${list(dependents)}
            <a class="btn secondary" href="${esc(node.url)}">Mở chi tiết issue</a>`;
        selectionStatus.textContent = `Đang chọn issue #${node.id}`;
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
