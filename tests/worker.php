<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/IssueService.php';
require_once __DIR__ . '/../src/ProjectService.php';
require_once __DIR__ . '/../src/ReleaseService.php';

try {
    $mode = (string)getenv('RT_WORKER_MODE');
    $input = json_decode((string)getenv('RT_WORKER_INPUT'), true);
    if (!is_array($input)) {
        throw new RuntimeException('WORKER_INPUT_INVALID');
    }
    $connection = db();
    if ($mode === 'issue_update') {
        $result = IssueService::update(
            $connection,
            (int)$input['project_id'],
            (int)$input['user_id'],
            isset($input['role']) ? (string)$input['role'] : null,
            (int)$input['issue_id'],
            (int)$input['base_version'],
            (array)$input['fields']
        );
        echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
        exit(0);
    }
    if ($mode === 'release') {
        ReleaseService::release(
            $connection,
            (int)$input['release_id'],
            (int)$input['project_id'],
            (int)$input['user_id'],
            isset($input['role']) ? (string)$input['role'] : null
        );
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit(0);
    }
    if ($mode === 'member_set') {
        ProjectService::setMemberRole(
            $connection,
            (int)$input['project_id'],
            (int)$input['user_id'],
            (string)$input['username'],
            (string)$input['member_role']
        );
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit(0);
    }
    if ($mode === 'member_remove') {
        ProjectService::removeMember(
            $connection,
            (int)$input['project_id'],
            (int)$input['user_id'],
            (int)$input['member_id']
        );
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit(0);
    }
    throw new RuntimeException('WORKER_MODE_INVALID');
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'error' => get_class($exception) . ': ' . $exception->getMessage(),
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
}
