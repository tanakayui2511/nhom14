<?php

declare(strict_types=1);

// Tải ghi chú phát hành (Markdown hoặc TXT) dựng từ snapshot đã chốt.
// Ví dụ: /release-notes-export.php?id=3&format=md

require __DIR__ . '/../src/ReleaseNotes.php';

$user = requireLogin();
$id = (int)($_GET['id'] ?? 0);
$format = strtolower((string)($_GET['format'] ?? 'md'));

try {
    $connection = db();
    $file = ReleaseNotes::export($connection, $id, (int)$user['id'], $format);
} catch (ForbiddenException $exception) {
    http_response_code(403);
    exit(h($exception->getMessage()));
} catch (NotFoundException $exception) {
    http_response_code(404);
    exit(h($exception->getMessage()));
} catch (Throwable $exception) {
    http_response_code($exception instanceof AppException ? 409 : 500);
    exit(h(userMessage($exception)));
}

header('Content-Type: ' . $file['mime']);
header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
echo $file['content'];
