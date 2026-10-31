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
$items = queryAll(
    $connection,
    'SELECT * FROM release_items WHERE release_id=$1 ORDER BY id',
    [(string)$id]
);
$format = strtolower((string)($_GET['format'] ?? 'html'));

if (in_array($format, ['markdown', 'md', 'txt'], true)) {
    $markdown = $format !== 'txt';
    $content = ReleaseNotes::markdown($connection, $id);
    if (!$markdown) {
        $content = preg_replace('/^#{1,3} /m', '', $content);
    }
    $safeVersion = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$release['version_name']);
    $filename = 'release-' . ($safeVersion === '' ? 'notes' : $safeVersion) . ($markdown ? '.md' : '.txt');
    header('Content-Type: ' . ($markdown ? 'text/markdown' : 'text/plain') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo $content;
    exit;
}

pageStart('Ghi chú phát hành');
?>
<section class="panel">
    <p><b>Dự án:</b> <?= h($release['project_name']) ?></p>
    <p><b>Phiên bản:</b> <?= h($release['version_name']) ?> · <span class="pill"><?= h($release['status']) ?></span></p>
    <p><?= nl2br(h($release['description'])) ?></p>
    <?php if ($release['status'] !== 'released'): ?>
        <p class="muted">Đây là bản nháp; ghi chú chỉ bất biến sau khi chốt phát hành.</p>
    <?php endif; ?>
    <p>
        <a class="btn" href="/release-notes.php?id=<?= (int)$release['id'] ?>&format=markdown">Tải Markdown</a>
        <a class="btn secondary" href="/release-notes.php?id=<?= (int)$release['id'] ?>&format=txt">Tải TXT</a>
        <a class="btn secondary" href="/releases.php?project_id=<?= (int)$release['project_id'] ?>&id=<?= (int)$release['id'] ?>">Quay lại</a>
    </p>
    <h2>Danh sách thay đổi đã chốt</h2>
    <?php if ($items === []): ?>
        <p class="muted">Chưa có công việc trong phiên bản.</p>
    <?php endif; ?>
    <?php foreach ($items as $item): ?>
        <article>
            <h3>#<?= (int)$item['issue_id'] ?> — <?= h($item['snapshot_title']) ?></h3>
            <p><?= nl2br(h($item['snapshot_description'])) ?></p>
            <p class="muted">
                Trạng thái khi chốt: <?= h($item['snapshot_status']) ?> ·
                Ưu tiên: <?= h($item['snapshot_priority']) ?> ·
                Phụ trách: <?= h($item['snapshot_assignee']) ?> ·
                Kiểm thử tại thời điểm chốt: <?= h($item['snapshot_test_result']) ?>
                <?php if ($item['snapshot_test_version'] !== null): ?>
                    (phiên bản <?= (int)$item['snapshot_test_version'] ?>)
                <?php endif; ?>
            </p>
            <?php if ((string)$item['snapshot_commit_url'] !== ''): ?>
                <p><b>Commit / PR:</b> <?= h($item['snapshot_commit_url']) ?></p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    <p><button type="button" onclick="window.print()">In / Lưu PDF</button></p>
</section>
<?php pageEnd(); ?>
