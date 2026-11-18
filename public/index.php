<?php

declare(strict_types=1);

require __DIR__ . '/../src/ReleaseService.php';

$user = requireLogin();
$connection = db();
[$projects] = selectProject($connection, $user);

$summaries = [];
foreach ($projects as $project) {
    $projectId = (int)$project['id'];
    // Số công việc CHƯA hoàn tất theo từng mức ưu tiên.
    $openRows = queryAll(
        $connection,
        "SELECT priority,count(*) AS total
         FROM issues
         WHERE project_id=\$1 AND status NOT IN ('verified','closed')
         GROUP BY priority",
        [(string)$projectId]
    );
    $openByPriority = array_fill_keys(ISSUE_PRIORITIES, 0);
    foreach ($openRows as $row) {
        $openByPriority[(string)$row['priority']] = (int)$row['total'];
    }
    $totals = queryOne(
        $connection,
        "SELECT count(*) AS total,
                count(*) FILTER (WHERE status IN ('verified','closed')) AS done
         FROM issues WHERE project_id=\$1",
        [(string)$projectId]
    );
    $blockedIssues = [];
    foreach (DependencyService::blockedForProject($connection, $projectId) as $row) {
        $blockedIssues[(int)$row['id']] = true;
    }
    // Phiên bản sắp tới (chưa chốt) kèm kết quả kiểm tra điều kiện hiện tại.
    $upcoming = [];
    $releases = queryAll(
        $connection,
        "SELECT id,version_name,status FROM releases
         WHERE project_id=\$1 AND status IN ('planned','ready')
         ORDER BY CASE status WHEN 'ready' THEN 0 ELSE 1 END, id
         LIMIT 3",
        [(string)$projectId]
    );
    foreach ($releases as $release) {
        $check = ReleaseService::check($connection, (int)$release['id'], $projectId);
        $passed = count(array_filter($check['checks'], static fn(array $c): bool => $c['passed']));
        $upcoming[] = $release + [
            'passed' => $passed,
            'total' => count($check['checks']),
            'problems' => count($check['problems']),
            'items' => count($check['items']),
        ];
    }
    $lastReleased = queryOne(
        $connection,
        "SELECT id,version_name,released_at FROM releases
         WHERE project_id=\$1 AND status='released'
         ORDER BY released_at DESC NULLS LAST, id DESC LIMIT 1",
        [(string)$projectId]
    );
    $summaries[] = [
        'project' => $project,
        'open' => $openByPriority,
        'total' => (int)$totals['total'],
        'done' => (int)$totals['done'],
        'blocked' => count($blockedIssues),
        'upcoming' => $upcoming,
        'last_released' => $lastReleased,
    ];
}
$assigned = queryAll(
    $connection,
    "SELECT i.id,i.project_id,i.title,i.priority,i.status,p.name AS project_name
     FROM issues i
     JOIN projects p ON p.id=i.project_id
     JOIN project_members pm ON pm.project_id=i.project_id AND pm.user_id=\$1
     WHERE i.assignee_id=\$1 AND i.status NOT IN ('verified','closed')
     ORDER BY CASE i.priority WHEN 'blocker' THEN 0 WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END,i.id
     LIMIT 8",
    [(string)$user['id']]
);

pageStart('Tổng quan');
?>
<section class="panel">
    <p>Xin chào <b><?= h($user['full_name']) ?></b>. Bạn đang tham gia <?= count($projects) ?> dự án.</p>
</section>

<?php if ($summaries === []): ?>
    <section class="panel"><?= emptyState('Bạn chưa thuộc dự án nào.', 'Hãy liên hệ quản trị viên để được thêm vào dự án.') ?></section>
<?php else: ?>
    <section class="grid project-grid">
        <?php foreach ($summaries as $summary): ?>
            <?php
            $project = $summary['project'];
            $projectId = (int)$project['id'];
            $openTotal = array_sum($summary['open']);
            $donePercent = $summary['total'] > 0 ? (int)round($summary['done'] * 100 / $summary['total']) : 0;
            ?>
            <article class="panel project-card">
                <div class="project-card-head">
                    <h2><?= h($project['name']) ?></h2>
                    <?= roleBadge($project['role']) ?>
                </div>
                <?php if ((string)$project['description'] !== ''): ?>
                    <p class="muted"><?= h($project['description']) ?></p>
                <?php endif; ?>

                <h3>Công việc đang mở theo ưu tiên (<?= $openTotal ?>)</h3>
                <ul class="stat-list">
                    <?php foreach ($summary['open'] as $priority => $count): ?>
                        <li>
                            <a href="/issues.php?project_id=<?= $projectId ?>&amp;priority=<?= h($priority) ?>">
                                <strong><?= $count ?></strong><?= priorityBadge($priority) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <li>
                        <a href="/dependencies.php?project_id=<?= $projectId ?>&amp;blocked=1">
                            <strong><?= (int)$summary['blocked'] ?></strong><span class="pill status-blocked">Đang bị chặn</span>
                        </a>
                    </li>
                </ul>
                <div class="stat-block">
                    <div class="stat-label"><span>Tiến độ hoàn tất</span><span><?= $summary['done'] ?>/<?= $summary['total'] ?> · <?= $donePercent ?>%</span></div>
                    <div class="stat-bar" role="img" aria-label="<?= h('Hoàn tất ' . $summary['done'] . '/' . $summary['total'] . ' công việc') ?>">
                        <span class="tone-emerald" style="width: <?= $donePercent ?>%"></span>
                    </div>
                </div>

                <h3>Phiên bản sắp tới</h3>
                <?php if ($summary['upcoming'] === []): ?>
                    <p class="muted">Chưa có phiên bản dự kiến.</p>
                <?php else: ?>
                    <ul class="item-list">
                        <?php foreach ($summary['upcoming'] as $release): ?>
                            <li>
                                <a href="/releases.php?project_id=<?= $projectId ?>&amp;id=<?= (int)$release['id'] ?>"><b><?= h($release['version_name']) ?></b></a>
                                <?= releaseStatusBadge($release['status']) ?>
                                <?php if ($release['problems'] === 0): ?>
                                    <span class="pill test-passed">✓ Đủ điều kiện (<?= $release['passed'] ?>/<?= $release['total'] ?>)</span>
                                <?php else: ?>
                                    <span class="pill test-failed">✗ <?= $release['passed'] ?>/<?= $release['total'] ?> điều kiện · <?= $release['problems'] ?> vấn đề</span>
                                <?php endif; ?>
                                <span class="muted"><?= $release['items'] ?> công việc</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($summary['last_released'] !== null): ?>
                    <p class="muted">Phát hành gần nhất:
                        <a href="/release-notes.php?id=<?= (int)$summary['last_released']['id'] ?>"><?= h($summary['last_released']['version_name']) ?></a>
                        <?php if ($summary['last_released']['released_at'] !== null): ?>
                            (<?= h(date('d/m/Y', strtotime((string)$summary['last_released']['released_at']))) ?>)
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <div class="card-actions">
                    <a class="btn secondary" href="/issues.php?project_id=<?= $projectId ?>">Công việc</a>
                    <a class="btn secondary" href="/releases.php?project_id=<?= $projectId ?>">Phiên bản</a>
                    <a class="btn secondary" href="/dependencies.php?project_id=<?= $projectId ?>">Phụ thuộc</a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="panel">
        <h2>Việc được giao cho tôi</h2>
        <?php if ($assigned === []): ?>
            <p class="muted">Không có công việc đang mở được giao cho bạn.</p>
        <?php else: ?>
            <ul class="item-list">
                <?php foreach ($assigned as $issue): ?>
                    <li>
                        <a href="/issues.php?project_id=<?= (int)$issue['project_id'] ?>&amp;id=<?= (int)$issue['id'] ?>">
                            #<?= (int)$issue['id'] ?> <?= h($issue['title']) ?>
                        </a>
                        <?= priorityBadge($issue['priority']) ?>
                        <?= statusBadge($issue['status']) ?>
                        <span class="muted"><?= h($issue['project_name']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php pageEnd(); ?>
