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
    <section class="grid">
        <?php foreach ($summaries as $summary): ?>
            <?php $project = $summary['project']; ?>
            <article class="panel">
                <h2><?= h($project['name']) ?></h2>
                <p class="muted"><?= h($project['description']) ?> · vai trò: <?= h($project['role']) ?></p>
                <p>
                    <span class="pill"><?= (int)$summary['issues']['total'] ?> công việc</span>
                    <span class="pill warn"><?= (int)$summary['issues']['active'] ?> đang mở</span>
                    <span class="pill bad"><?= (int)$summary['issues']['critical'] ?> nghiêm trọng</span>
                </p>
                <p>
                    <span class="pill"><?= (int)$summary['releases']['planned'] ?> dự kiến</span>
                    <span class="pill warn"><?= (int)$summary['releases']['ready'] ?> sẵn sàng</span>
                    <span class="pill ok"><?= (int)$summary['releases']['released'] ?> đã phát hành</span>
                    <span class="pill <?= $summary['blocked'] > 0 ? 'bad' : '' ?>"><?= $summary['blocked'] ?> bị chặn</span>
                </p>
                <p>
                    <a class="btn secondary" href="/issues.php?project_id=<?= (int)$project['id'] ?>">Công việc</a>
                    <a class="btn secondary" href="/releases.php?project_id=<?= (int)$project['id'] ?>">Phiên bản</a>
                    <a class="btn secondary" href="/dependencies.php?project_id=<?= (int)$project['id'] ?>">Phụ thuộc</a>
                </p>
            </article>
        <?php endforeach; ?>
    </section>

    <div class="grid">
        <section class="panel">
            <h2>Việc được giao cho tôi</h2>
            <?php if ($assigned === []): ?>
                <p class="muted">Không có công việc đang mở được giao cho bạn.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($assigned as $issue): ?>
                        <li>
                            <a href="/issues.php?project_id=<?= (int)$issue['project_id'] ?>&id=<?= (int)$issue['id'] ?>">
                                #<?= (int)$issue['id'] ?> <?= h($issue['title']) ?>
                            </a>
                            <span class="pill"><?= h($issue['priority']) ?></span>
                            <span class="muted"><?= h($issue['project_name']) ?> · <?= h($issue['status']) ?></span>
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
                <ul>
                    <?php foreach ($recentReleases as $release): ?>
                        <li>
                            <a href="/release-notes.php?id=<?= (int)$release['id'] ?>">
                                <?= h($release['version_name']) ?>
                            </a>
                            <span class="pill"><?= h($release['status']) ?></span>
                            <span class="muted"><?= h($release['project_name']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
<?php pageEnd(); ?>
