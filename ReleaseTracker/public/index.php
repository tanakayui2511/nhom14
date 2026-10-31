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

$activeIssuesTotal = array_sum(array_map(static fn(array $summary): int => (int)$summary['issues']['active'], $summaries));
$criticalIssuesTotal = array_sum(array_map(static fn(array $summary): int => (int)$summary['issues']['critical'], $summaries));
$issuesTotal = array_sum(array_map(static fn(array $summary): int => (int)$summary['issues']['total'], $summaries));
$completedIssuesTotal = max(0, $issuesTotal - $activeIssuesTotal);
$completionRate = $issuesTotal > 0 ? (int)round(($completedIssuesTotal / $issuesTotal) * 100) : 0;
$plannedReleasesTotal = array_sum(array_map(static fn(array $summary): int => (int)$summary['releases']['planned'], $summaries));
$readyReleasesTotal = array_sum(array_map(static fn(array $summary): int => (int)$summary['releases']['ready'], $summaries));
$releasedTotal = array_sum(array_map(static fn(array $summary): int => (int)$summary['releases']['released'], $summaries));
$releaseChartMax = max(1, $plannedReleasesTotal, $readyReleasesTotal, $releasedTotal);

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
    <section class="kpi-grid" aria-label="Chỉ số không gian làm việc">
        <article class="kpi-card kpi-teal"><span class="kpi-label">DỰ ÁN ĐANG HOẠT ĐỘNG</span><strong data-count="<?= count($projects) ?>"><?= count($projects) ?></strong><span class="kpi-note">Trong workspace của bạn</span></article>
        <article class="kpi-card kpi-blue"><span class="kpi-label">CÔNG VIỆC ĐANG XỬ LÝ</span><strong data-count="<?= $activeIssuesTotal ?>"><?= $activeIssuesTotal ?></strong><span class="kpi-note">Trên tất cả dự án</span></article>
        <article class="kpi-card kpi-coral"><span class="kpi-label">ƯU TIÊN CAO</span><strong data-count="<?= $criticalIssuesTotal ?>"><?= $criticalIssuesTotal ?></strong><span class="kpi-note">Blocker và critical</span></article>
        <article class="kpi-card kpi-violet"><span class="kpi-label">GIAO CHO BẠN</span><strong data-count="<?= count($assigned) ?>"><?= count($assigned) ?></strong><span class="kpi-note">Cần bạn theo dõi</span></article>
    </section>
    <section class="dashboard-visuals" aria-label="Biểu đồ tổng quan">
        <article class="panel chart-panel">
            <div class="section-heading"><div><span class="eyebrow">TIẾN ĐỘ ISSUE</span><h2>Tỷ lệ hoàn thành</h2></div></div>
            <div class="donut-layout">
                <div class="donut-wrap">
                    <svg class="donut" viewBox="0 0 120 120" role="img" aria-label="<?= $completionRate ?> phần trăm công việc đã hoàn thành">
                        <defs><linearGradient id="dashboardDonutGradient"><stop class="donut-stop-a"/><stop class="donut-stop-b" offset=".55"/><stop class="donut-stop-c" offset="1"/></linearGradient></defs>
                        <circle class="track" cx="60" cy="60" r="48"/>
                        <circle class="value" cx="60" cy="60" r="48" pathLength="100" stroke-dasharray="<?= $completionRate ?> 100"/>
                    </svg>
                    <span class="donut-center"><strong><?= $completionRate ?>%</strong><small class="muted">hoàn thành</small></span>
                </div>
                <div class="chart-legend">
                    <span><i style="--legend-color: var(--color-violet)"></i><?= $completedIssuesTotal ?> đã đóng</span>
                    <span><i style="--legend-color: var(--color-coral)"></i><?= $activeIssuesTotal ?> đang xử lý</span>
                    <span><i style="--legend-color: var(--color-sun)"></i><?= $criticalIssuesTotal ?> cần ưu tiên</span>
                </div>
            </div>
        </article>
        <article class="panel chart-panel">
            <div class="section-heading"><div><span class="eyebrow">NHỊP PHÁT HÀNH</span><h2>Trạng thái phiên bản</h2></div><a href="/releases.php">Xem chi tiết</a></div>
            <div class="bar-chart" role="img" aria-label="Biểu đồ số phiên bản theo trạng thái">
                <div class="bar-item"><strong><?= $plannedReleasesTotal ?></strong><i style="height: <?= max(8, (int)round($plannedReleasesTotal / $releaseChartMax * 120)) ?>px; --bar-gradient: var(--gradient-dependencies)"></i><span>Dự kiến</span></div>
                <div class="bar-item"><strong><?= $readyReleasesTotal ?></strong><i style="height: <?= max(8, (int)round($readyReleasesTotal / $releaseChartMax * 120)) ?>px; --bar-gradient: var(--gradient-projects)"></i><span>Sẵn sàng</span></div>
                <div class="bar-item"><strong><?= $releasedTotal ?></strong><i style="height: <?= max(8, (int)round($releasedTotal / $releaseChartMax * 120)) ?>px; --bar-gradient: var(--gradient-releases)"></i><span>Đã phát hành</span></div>
            </div>
        </article>
    </section>
    <div class="section-heading"><div><span class="eyebrow">DANH MỤC CỦA BẠN</span><h2>Dự án</h2></div><a href="/projects.php">Xem dự án</a></div>
    <section class="grid project-grid">
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
