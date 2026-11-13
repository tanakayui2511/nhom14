<?php

declare(strict_types=1);

require __DIR__ . '/../src/App.php';

$user = requireLogin();
$connection = db();
$projects = queryAll(
    $connection,
    'SELECT p.id,p.name,p.description,pm.role
     FROM projects p
     JOIN project_members pm ON pm.project_id=p.id
     WHERE pm.user_id=$1
     ORDER BY p.id',
    [(string)$user['id']]
);
$summaries = [];
foreach ($projects as $project) {
    $projectId = (int)$project['id'];
    $issueStats = queryOne(
        $connection,
        "SELECT count(*) AS total,
                count(*) FILTER (WHERE status IN ('open','in_progress','resolved','retest')) AS active,
                count(*) FILTER (WHERE status IN ('open','retest')) AS attention,
                count(*) FILTER (WHERE priority IN ('blocker','critical')) AS critical
         FROM issues WHERE project_id=\$1",
        [(string)$projectId]
    );
    $releaseStats = queryOne(
        $connection,
        "SELECT count(*) FILTER (WHERE status='planned') AS planned,
                count(*) FILTER (WHERE status='ready') AS ready,
                count(*) FILTER (WHERE status='released') AS released
         FROM releases WHERE project_id=\$1",
        [(string)$projectId]
    );
    $blockedCount = queryOne(
        $connection,
        "SELECT count(*) AS total
         FROM dependencies d
         JOIN issues i ON i.id=d.issue_id
         JOIN issues dep ON dep.id=d.depends_on_id
         WHERE i.project_id=\$1 AND dep.status NOT IN ('verified','closed')",
        [(string)$projectId]
    );
    $summaries[] = [
        'project' => $project,
        'issues' => $issueStats,
        'releases' => $releaseStats,
        'blocked' => (int)$blockedCount['total'],
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
$recentReleases = queryAll(
    $connection,
    'SELECT r.id,r.version_name,r.status,r.released_at,p.name AS project_name
     FROM releases r
     JOIN projects p ON p.id=r.project_id
     JOIN project_members pm ON pm.project_id=r.project_id AND pm.user_id=$1
     ORDER BY r.id DESC
     LIMIT 6',
    [(string)$user['id']]
);

pageStart('Tổng quan');
?>
<section class="panel">
    <p>Xin chào <b><?= h($user['full_name']) ?></b>. Bạn đang tham gia <?= count($projects) ?> dự án.</p>
</section>

<?php if ($summaries === []): ?>
    <section class="panel">
        <p>Bạn chưa thuộc dự án nào. Hãy liên hệ admin để được thêm vào dự án.</p>
    </section>
<?php else: ?>
    <section class="grid project-grid">
        <?php foreach ($summaries as $summary): ?>
            <?php
            $project = $summary['project'];
            // Presentation only: derived counts and bar widths for the card.
            $total = (int)$summary['issues']['total'];
            $active = (int)$summary['issues']['active'];
            $done = max(0, $total - $active);
            $donePercent = $total > 0 ? (int)round($done * 100 / $total) : 0;
            $planned = (int)$summary['releases']['planned'];
            $ready = (int)$summary['releases']['ready'];
            $released = (int)$summary['releases']['released'];
            $releaseTotal = $planned + $ready + $released;
            $releaseWidth = static fn(int $count): int => $releaseTotal > 0 ? (int)round($count * 100 / $releaseTotal) : 0;
            ?>
            <article class="panel project-card">
                <div class="project-card-head">
                    <h2><?= h($project['name']) ?></h2>
                    <span class="pill tone-violet">vai trò: <?= h($project['role']) ?></span>
                </div>
                <p class="muted"><?= h($project['description']) ?></p>
                <div class="summary-grid" aria-label="Thống kê công việc">
                    <div class="summary-card tone-indigo"><strong><?= $total ?></strong><span>Công việc</span></div>
                    <div class="summary-card tone-sky"><strong><?= $active ?></strong><span>Đang mở</span></div>
                    <div class="summary-card tone-emerald"><strong><?= $done ?></strong><span>Hoàn tất</span></div>
                    <div class="summary-card tone-amber"><strong><?= (int)$summary['issues']['critical'] ?></strong><span>Nghiêm trọng</span></div>
                    <div class="summary-card tone-rose"><strong><?= (int)$summary['blocked'] ?></strong><span>Bị chặn</span></div>
                </div>
                <div class="stat-row">
                <div class="stat-block">
                    <div class="stat-label"><span>Tiến độ hoàn tất</span><span><?= $donePercent ?>%</span></div>
                    <div class="stat-bar" role="img" aria-label="<?= h('Hoàn tất ' . $done . '/' . $total . ' công việc') ?>">
                        <span class="tone-emerald" style="width: <?= $donePercent ?>%"></span>
                    </div>
                </div>
                <div class="stat-block">
                    <div class="stat-label"><span>Phiên bản</span><span class="muted"><?= $releaseTotal ?> phiên bản</span></div>
                    <div class="stat-bar" role="img" aria-label="<?= h($planned . ' dự kiến, ' . $ready . ' sẵn sàng, ' . $released . ' đã phát hành') ?>">
                        <span class="tone-sky" style="width: <?= $releaseWidth($planned) ?>%"></span>
                        <span class="tone-amber" style="width: <?= $releaseWidth($ready) ?>%"></span>
                        <span class="tone-emerald" style="width: <?= $releaseWidth($released) ?>%"></span>
                    </div>
                    <div class="stat-legend">
                        <span class="pill release-planned"><?= $planned ?> dự kiến</span>
                        <span class="pill release-ready"><?= $ready ?> sẵn sàng</span>
                        <span class="pill release-released"><?= $released ?> đã phát hành</span>
                    </div>
                </div>
                </div>
                <div class="card-actions">
                    <a class="btn secondary" href="/issues.php?project_id=<?= (int)$project['id'] ?>">Công việc</a>
                    <a class="btn secondary" href="/releases.php?project_id=<?= (int)$project['id'] ?>">Phiên bản</a>
                    <a class="btn secondary" href="/dependencies.php?project_id=<?= (int)$project['id'] ?>">Phụ thuộc</a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <div class="grid">
        <section class="panel">
            <h2>Việc được giao cho tôi</h2>
            <?php if ($assigned === []): ?>
                <p class="muted">Không có công việc đang mở được giao cho bạn.</p>
            <?php else: ?>
                <ul class="item-list">
                    <?php foreach ($assigned as $issue): ?>
                        <li>
                            <a href="/issues.php?project_id=<?= (int)$issue['project_id'] ?>&id=<?= (int)$issue['id'] ?>">
                                #<?= (int)$issue['id'] ?> <?= h($issue['title']) ?>
                            </a>
                            <span class="pill priority-<?= h($issue['priority']) ?>"><?= h($issue['priority']) ?></span>
                            <span class="pill status-<?= h($issue['status']) ?>"><?= h($issue['status']) ?></span>
                            <span class="muted"><?= h($issue['project_name']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Phiên bản gần đây</h2>
            <?php if ($recentReleases === []): ?>
                <p class="muted">Chưa có phiên bản.</p>
            <?php else: ?>
                <ul class="item-list">
                    <?php foreach ($recentReleases as $release): ?>
                        <li>
                            <a href="/release-notes.php?id=<?= (int)$release['id'] ?>">
                                <?= h($release['version_name']) ?>
                            </a>
                            <span class="pill release-<?= h($release['status']) ?>"><?= h($release['status']) ?></span>
                            <span class="muted"><?= h($release['project_name']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
<?php pageEnd(); ?>
