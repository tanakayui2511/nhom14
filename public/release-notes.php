<?php

declare(strict_types=1);

require __DIR__ . '/../src/ReleaseNotes.php';

$user = requireLogin();
$connection = db();
$id = (int)($_GET['id'] ?? 0);
$release = queryOne(
    $connection,
    'SELECT r.*,p.name AS project_name
     FROM releases r JOIN projects p ON p.id=r.project_id
     WHERE r.id=$1',
    [(string)$id]
);
if ($release === null) {
    http_response_code(404);
    exit('Không tìm thấy phiên bản.');
}
requireProjectAccess($connection, (int)$release['project_id'], $user);
$_GET['project_id'] = (string)$release['project_id'];
selectProject($connection, $user);
$displayProjectName = ReleaseNotes::displayProjectName($release);
$items = queryAll(
    $connection,
    'SELECT * FROM release_items WHERE release_id=$1 ORDER BY id',
    [(string)$id]
);
$format = strtolower((string)($_GET['format'] ?? 'html'));

// Giữ tương thích đường dẫn cũ ?format=markdown|md|txt (kể cả bản nháp).
if (in_array($format, ['markdown', 'md', 'txt'], true)) {
    $markdown = $format !== 'txt';
    $content = $markdown
        ? ReleaseNotes::markdown($connection, $id)
        : ReleaseNotes::text($connection, $id);
    $safeVersion = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$release['version_name']);
    $filename = 'release-' . ($safeVersion === '' ? 'notes' : $safeVersion) . ($markdown ? '.md' : '.txt');
    header('Content-Type: ' . ($markdown ? 'text/markdown' : 'text/plain') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo $content;
    exit;
}

$isReleased = (string)$release['status'] === 'released';
pageStart('Ghi chú phát hành');
?>
<section class="panel">
    <div class="section-heading">
        <h2><?= h($release['version_name']) ?> — <?= h($displayProjectName) ?> <?= releaseStatusBadge($release['status']) ?></h2>
        <a class="btn secondary" href="/releases.php?project_id=<?= (int)$release['project_id'] ?>&amp;id=<?= (int)$release['id'] ?>">← Quay lại phiên bản</a>
    </div>
    <?php if ($release['released_at'] !== null): ?>
        <p class="muted">Chốt lúc <?= h(date('d/m/Y H:i', strtotime((string)$release['released_at']))) ?></p>
    <?php endif; ?>
    <?php if ((string)$release['description'] !== ''): ?>
        <p><?= nl2br(h($release['description'])) ?></p>
    <?php endif; ?>
    <?php if (!$isReleased): ?>
        <p class="warn">Đây là bản nháp; ghi chú chỉ bất biến sau khi chốt phát hành.</p>
    <?php else: ?>
        <div class="filter-actions">
            <a class="btn" href="/release-notes-export.php?id=<?= (int)$release['id'] ?>&amp;format=md">Tải Markdown (.md)</a>
            <a class="btn secondary" href="/release-notes-export.php?id=<?= (int)$release['id'] ?>&amp;format=txt">Tải văn bản (.txt)</a>
            <button type="button" class="secondary" data-print>In / Lưu PDF</button>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Danh sách thay đổi <?= $isReleased ? 'đã chốt' : '(nháp)' ?></h2>
    <?php if ($items === []): ?>
        <?= emptyState('Chưa có công việc trong phiên bản.') ?>
    <?php endif; ?>
    <?php foreach (ReleaseNotes::groupItems($items) as $type => $rows): ?>
        <h3><?= h(ReleaseNotes::GROUPS[$type]) ?> (<?= count($rows) ?>)</h3>
        <ul class="item-list">
            <?php foreach ($rows as $item): ?>
                <li>
                    <b>#<?= (int)$item['issue_id'] ?> — <?= h($item['snapshot_title']) ?></b>
                    <?= priorityBadge($item['snapshot_priority']) ?>
                    <?= statusBadge($item['snapshot_status']) ?>
                    <br>
                    <span class="muted">
                        Phụ trách: <?= h((string)$item['snapshot_assignee'] === '' ? 'Chưa phân công' : $item['snapshot_assignee']) ?> ·
                        Kiểm thử khi chốt: <?= h(ReleaseNotes::testLabel($item)) ?>
                    </span>
                    <?php if ((string)$item['snapshot_description'] !== ''): ?>
                        <br><?= nl2br(h($item['snapshot_description'])) ?>
                    <?php endif; ?>
                    <?php if ((string)$item['snapshot_commit_url'] !== ''): ?>
                        <br>Commit / PR: <a href="<?= h($item['snapshot_commit_url']) ?>" rel="noopener noreferrer"><?= h($item['snapshot_commit_url']) ?></a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</section>
<script>
document.querySelectorAll('[data-print]').forEach((button) => button.addEventListener('click', () => window.print()));
</script>
<?php pageEnd(); ?>
