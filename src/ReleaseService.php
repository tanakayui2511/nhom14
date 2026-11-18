<?php

declare(strict_types=1);

require_once __DIR__ . '/App.php';
require_once __DIR__ . '/DependencyService.php';
require_once __DIR__ . '/TestEvidence.php';

final class ReleaseService
{
    public static function conditions(): array
    {
        return [
            [
                'code' => 'items',
                'title' => 'Có công việc trong phiên bản',
                'description' => 'Phiên bản phải có ít nhất một công việc đã gắn vào danh sách.',
            ],
            [
                'code' => 'project_items',
                'title' => 'Công việc thuộc đúng dự án',
                'description' => 'Mọi công việc và bản ghi snapshot phải thuộc đúng dự án của phiên bản.',
            ],
            [
                'code' => 'item_status',
                'title' => 'Mọi công việc đã hoàn tất',
                'description' => 'Mọi công việc trong phiên bản phải ở trạng thái verified hoặc closed. '
                    . 'Item non-blocker còn open, in_progress hoặc retest cũng chặn phát hành.',
            ],
            [
                'code' => 'blockers',
                'title' => 'Blocker đã kiểm thử lại hợp lệ',
                'description' => 'Mỗi công việc là blocker (hoặc từng là blocker kể từ khi gắn vào phiên bản) '
                    . 'phải ở trạng thái verified/closed, có kết quả kiểm thử mới nhất là passed '
                    . 'và lần kiểm thử đó làm trên đúng phiên bản dữ liệu hiện tại '
                    . '(không có lần sửa nào sau khi test passed).',
            ],
            [
                'code' => 'dependencies',
                'title' => 'Công việc tiên quyết đã hoàn tất',
                'description' => 'Mọi công việc phụ thuộc phải ở trạng thái closed, hoặc verified '
                    . 'với kết quả kiểm thử mới nhất là passed trên đúng phiên bản dữ liệu hiện tại. '
                    . 'Công việc blocker phải có kết quả passed hợp lệ ngay cả khi đã closed. '
                    . 'Kết quả failed/blocked không được coi là đạt; kết quả passed lỗi thời '
                    . 'không được coi là verified.',
            ],
            [
                'code' => 'no_cycles',
                'title' => 'Không có chu trình phụ thuộc',
                'description' => 'Đồ thị phụ thuộc của dự án phải là đồ thị không có chu trình.',
            ],
            [
                'code' => 'snapshot',
                'title' => 'Dữ liệu snapshot hợp lệ',
                'description' => 'Tiêu đề, trạng thái và mức ưu tiên của công việc phải hợp lệ để chốt snapshot.',
            ],
            [
                'code' => 'role',
                'title' => 'Người thao tác có quyền phát hành',
                'description' => 'Chỉ release manager hoặc admin được chuyển ready và chốt phát hành.',
            ],
        ];
    }

    public static function create(
        PgSql\Connection $connection,
        int $projectId,
        int $userId,
        ?string $role,
        array $input
    ): int {
        $versionName = postString($input, 'version_name', 80, true, 'Tên phiên bản');
        $description = postString($input, 'description', 20000, false, 'Mô tả');
        return transaction($connection, function () use (
            $connection,
            $projectId,
            $userId,
            $versionName,
            $description
        ): int {
            projectLock($connection, $projectId);
            requireProjectRoleInTransaction(
                $connection,
                $projectId,
                $userId,
                ['release_manager'],
                'Chỉ release manager hoặc admin được tạo phiên bản.'
            );
            $duplicate = queryOne(
                $connection,
                'SELECT 1 FROM releases WHERE project_id=$1 AND version_name=$2',
                [(string)$projectId, $versionName]
            );
            if ($duplicate !== null) {
                throw new ConflictException('Tên phiên bản đã tồn tại trong dự án.');
            }
            $result = queryParams(
                $connection,
                'INSERT INTO releases(project_id,version_name,description,created_by)
                 VALUES($1,$2,$3,$4) RETURNING id',
                [(string)$projectId, $versionName, $description, (string)$userId]
            );
            $id = (int)pg_fetch_result($result, 0, 0);
            audit($connection, $projectId, $userId, 'release_created', 'release', $id, [
                'version_name' => $versionName,
            ]);
            return $id;
        });
    }

    public static function check(
        PgSql\Connection $connection,
        int $releaseId,
        int $projectId,
        ?string $role = null
    ): array {
        $release = queryOne(
            $connection,
            'SELECT * FROM releases WHERE id=$1 AND project_id=$2',
            [(string)$releaseId, (string)$projectId]
        );
        if ($release === null) {
            throw new NotFoundException('Không tìm thấy phiên bản trong dự án.');
        }
        $items = queryAll(
            $connection,
            'SELECT ri.*,i.title AS issue_title,i.status AS issue_status,i.priority AS issue_priority,
                    i.version AS issue_version,i.project_id AS issue_project_id,
                    i.description AS issue_description,i.commit_url AS issue_commit_url,
                    i.issue_type AS issue_type,
                    COALESCE(u.full_name, \'\') AS issue_assignee,
                    t.result AS latest_test_result,t.issue_version AS latest_test_version,
                    t.tested_at AS latest_test_tested_at,t.id AS latest_test_id,
                    t.tester_id AS latest_test_tester_id,
                    t.audit_user_id AS latest_test_audit_user_id,
                    t.audit_details AS latest_test_audit_details,
                    -- "Từng là blocker kể từ khi gắn vào phiên bản": lúc gắn đã là
                    -- blocker (snapshot_priority ghi lúc addItem), hoặc có lần đổi
                    -- ưu tiên từ/thành blocker SAU bản ghi audit release_item_added.
                    (
                        ri.snapshot_priority=\'blocker\'
                        OR EXISTS (
                            SELECT 1 FROM audit_log pa
                            WHERE pa.project_id=ri.project_id
                              AND pa.action=\'priority_changed\'
                              AND pa.entity_type=\'issue\'
                              AND pa.entity_id=ri.issue_id
                              AND (pa.details->>\'old\'=\'blocker\' OR pa.details->>\'new\'=\'blocker\')
                              AND pa.id > COALESCE((
                                  SELECT max(ad.id) FROM audit_log ad
                                  WHERE ad.project_id=ri.project_id
                                    AND ad.action=\'release_item_added\'
                                    AND ad.entity_type=\'release\'
                                    AND ad.entity_id=ri.release_id
                                    AND ad.details->>\'issue_id\'=ri.issue_id::text
                              ), 0)
                        )
                    ) AS was_blocker
             FROM release_items ri
             LEFT JOIN issues i ON i.id=ri.issue_id
             LEFT JOIN users u ON u.id=i.assignee_id
             LEFT JOIN LATERAL (
                 SELECT tr.result,tr.issue_version,tr.tested_at,tr.id,tr.tester_id,
                        al.user_id AS audit_user_id,al.details AS audit_details
                 FROM test_results tr
                 LEFT JOIN LATERAL (
                     SELECT a.user_id,a.details
                     FROM audit_log a
                     WHERE a.action=\'test_result_recorded\'
                       AND a.entity_type=\'test_result\'
                       AND a.entity_id=tr.id
                       AND a.project_id=tr.project_id
                     ORDER BY a.id DESC
                     LIMIT 1
                 ) al ON true
                 WHERE tr.issue_id=ri.issue_id
                 ORDER BY tr.id DESC
                 LIMIT 1
             ) t ON true
             WHERE ri.release_id=$1
             ORDER BY ri.id',
            [(string)$releaseId]
        );
        // Start with every release item and traverse dependency edges until the
        // complete prerequisite closure is reached. UNION (rather than UNION
        // ALL) also keeps this read safe if legacy data contains a cycle.
        $dependencies = queryAll(
            $connection,
            'WITH RECURSIVE dependency_closure(release_item_id, dependency_id) AS (
                 SELECT ri.issue_id,d.depends_on_id
                 FROM release_items ri
                 JOIN dependencies d ON d.issue_id=ri.issue_id
                 WHERE ri.release_id=$1
                   AND ri.project_id=$2
                   AND d.issue_project_id=$2
                   AND d.depends_on_project_id=$2
                 UNION
                 SELECT dc.release_item_id,d.depends_on_id
                 FROM dependency_closure dc
                 JOIN dependencies d ON d.issue_id=dc.dependency_id
                 WHERE d.issue_project_id=$2 AND d.depends_on_project_id=$2
             )
             SELECT dc.release_item_id AS issue_id,b.id,b.title,b.status,b.priority,b.version AS issue_version,
                    t.result AS latest_test_result,t.issue_version AS latest_test_version,
                    t.tester_id AS latest_test_tester_id,
                    t.audit_user_id AS latest_test_audit_user_id,
                    t.audit_details AS latest_test_audit_details
             FROM dependency_closure dc
             JOIN issues b ON b.id=dc.dependency_id AND b.project_id=$2
             LEFT JOIN LATERAL (
                 SELECT tr.result,tr.issue_version,tr.tester_id,
                        al.user_id AS audit_user_id,al.details AS audit_details
                 FROM test_results tr
                 LEFT JOIN LATERAL (
                     SELECT a.user_id,a.details
                     FROM audit_log a
                     WHERE a.action=\'test_result_recorded\'
                       AND a.entity_type=\'test_result\'
                       AND a.entity_id=tr.id
                       AND a.project_id=tr.project_id
                     ORDER BY a.id DESC
                     LIMIT 1
                 ) al ON true
                 WHERE tr.issue_id=b.id
                 ORDER BY tr.id DESC
                 LIMIT 1
             ) t ON true
             ORDER BY dc.release_item_id,b.id',
            [(string)$releaseId, (string)$projectId]
        );
        $depsByIssue = [];
        foreach ($dependencies as $dependency) {
            $depsByIssue[(int)$dependency['issue_id']][] = $dependency;
        }

        $problems = [
            'items' => [],
            'project_items' => [],
            'item_status' => [],
            'blockers' => [],
            'dependencies' => [],
            'no_cycles' => [],
            'snapshot' => [],
            'role' => [],
        ];
        // Điều kiện 1 (items): phiên bản phải có ít nhất một công việc.
        if ($items === []) {
            $problems['items'][] = 'Phiên bản chưa có công việc nào.';
        }
        foreach ($items as $item) {
            $issueId = (int)$item['issue_id'];
            // Điều kiện 2 (project_items): item và issue nguồn cùng dự án với phiên bản.
            if ((int)$item['project_id'] !== $projectId
                || (int)($item['issue_project_id'] ?? 0) !== $projectId) {
                $problems['project_items'][] = 'Công việc #' . $issueId . ' không thuộc đúng dự án.';
            }
            $title = (string)($item['issue_title'] ?? '');
            $status = (string)($item['issue_status'] ?? '');
            $priority = (string)($item['issue_priority'] ?? '');
            $version = (int)($item['issue_version'] ?? 0);
            $latestTrusted = self::latestTestTrusted(
                $item['latest_test_tester_id'] ?? null,
                $item['latest_test_result'] ?? null,
                $item['latest_test_version'] ?? null,
                $item['latest_test_audit_user_id'] ?? null,
                $item['latest_test_audit_details'] ?? null
            );
            // Điều kiện 7 (snapshot): dữ liệu đủ hợp lệ để chụp snapshot.
            if ($title === '' || !in_array($status, ISSUE_STATUSES, true)
                || !in_array($priority, ISSUE_PRIORITIES, true) || $version < 1) {
                $problems['snapshot'][] = 'Công việc #' . $issueId . ' có dữ liệu snapshot không hợp lệ.';
            }
            // Điều kiện 3 (item_status): mọi công việc phải verified hoặc closed.
            if (!in_array($status, ['verified', 'closed'], true)) {
                $problems['item_status'][] = 'Công việc #' . $issueId
                    . ' đang ở trạng thái ' . ($status === '' ? 'không hợp lệ' : $status)
                    . ', chưa verified/closed.';
            }
            // Điều kiện 4 (blockers): công việc là blocker nếu ưu tiên hiện tại là
            // blocker HOẶC từng là blocker kể từ khi gắn vào phiên bản (chống
            // việc hạ ưu tiên xuống low để lách kiểm tra).
            $wasBlocker = in_array((string)($item['was_blocker'] ?? 'f'), ['t', '1', 'true'], true);
            if ($priority === 'blocker' || $wasBlocker) {
                $blockerProblem = self::blockerTestProblem(
                    $item['latest_test_result'],
                    $item['latest_test_version'],
                    $version,
                    $latestTrusted,
                    $status
                );
                if ($blockerProblem !== null) {
                    $problems['blockers'][] = 'Blocker #' . $issueId
                        . ($priority === 'blocker' ? '' : ' (từng là blocker kể từ khi gắn vào phiên bản)')
                        . ' ' . $blockerProblem . '.';
                }
            }
            // Điều kiện 5 (dependencies): toàn bộ công việc tiên quyết (bắc cầu) đã hoàn tất.
            foreach ($depsByIssue[$issueId] ?? [] as $dependency) {
                $dependencyStatus = (string)$dependency['status'];
                $dependencyPriority = (string)$dependency['priority'];
                $dependencyVersion = (int)$dependency['issue_version'];
                $latestResult = $dependency['latest_test_result'];
                $latestVersion = $dependency['latest_test_version'];
                $latestTrusted = self::latestTestTrusted(
                    $dependency['latest_test_tester_id'] ?? null,
                    $latestResult,
                    $latestVersion,
                    $dependency['latest_test_audit_user_id'] ?? null,
                    $dependency['latest_test_audit_details'] ?? null
                );
                $reason = '';
                if (!in_array($dependencyStatus, ['verified', 'closed'], true)) {
                    $reason = 'trạng thái ' . $dependencyStatus;
                } elseif ($dependencyPriority === 'blocker') {
                    $blockerProblem = self::blockerTestProblem(
                        $latestResult,
                        $latestVersion,
                        $dependencyVersion,
                        $latestTrusted,
                        $dependencyStatus
                    );
                    if ($blockerProblem !== null) {
                        $reason = 'blocker #' . (int)$dependency['id'] . ' ' . $blockerProblem;
                    }
                } elseif ($dependencyStatus === 'verified') {
                    if ($latestResult === null) {
                        $reason = 'chưa có kết quả kiểm thử';
                    } elseif ((string)$latestResult !== 'passed') {
                        $reason = 'kết quả kiểm thử mới nhất là ' . (string)$latestResult;
                    } elseif ($latestVersion === null || (int)$latestVersion !== $dependencyVersion) {
                        $reason = 'kết quả passed đã lỗi thời so với phiên bản hiện tại';
                    } elseif (!$latestTrusted) {
                        $reason = 'kết quả passed thiếu bằng chứng nguồn gốc hợp lệ';
                    }
                } elseif ($latestResult !== null && (string)$latestResult !== 'passed') {
                    $reason = 'đã closed nhưng kết quả kiểm thử mới nhất là ' . (string)$latestResult;
                }
                if ($reason !== '') {
                    $problems['dependencies'][] = 'Công việc #' . $issueId
                        . ' bị chặn bởi #' . (int)$dependency['id']
                        . ' ' . (string)$dependency['title']
                        . ' (' . $reason . ').';
                }
            }
        }
        // Điều kiện 6 (no_cycles): đồ thị phụ thuộc của dự án không có chu trình.
        $cycle = DependencyService::projectCycle($connection, $projectId);
        if ($cycle !== null) {
            $problems['no_cycles'][] = 'Phát hiện chu trình phụ thuộc: '
                . implode(' -> ', array_map(static fn(int $id): string => '#' . $id, $cycle));
        }
        // Điều kiện 8 (role): người thao tác là release manager hoặc admin.
        if ($role !== null && !canManageRelease($role)) {
            $problems['role'][] = 'Chỉ release manager hoặc admin được chuyển ready và chốt phát hành.';
        }

        $checks = [];
        $allProblems = [];
        foreach (self::conditions() as $condition) {
            $code = $condition['code'];
            $conditionProblems = $problems[$code] ?? [];
            $checks[$code] = $condition + [
                'passed' => $conditionProblems === [],
                'problems' => $conditionProblems,
            ];
            foreach ($conditionProblems as $problem) {
                $allProblems[] = $problem;
            }
        }
        return [
            'release' => $release,
            'items' => $items,
            'checks' => $checks,
            'problems' => array_values(array_unique($allProblems)),
        ];
    }

    public static function markReady(
        PgSql\Connection $connection,
        int $releaseId,
        int $projectId,
        int $userId,
        ?string $role
    ): void {
        transaction($connection, function () use ($connection, $releaseId, $projectId, $userId): void {
            projectLock($connection, $projectId);
            $currentRole = requireProjectRoleInTransaction(
                $connection,
                $projectId,
                $userId,
                ['release_manager'],
                'Chỉ release manager hoặc admin được chuyển phiên bản sang ready.'
            );
            $release = self::releaseForUpdate($connection, $releaseId, $projectId);
            if ((string)$release['status'] === 'released') {
                throw new ConflictException('Phiên bản đã được chốt, không thể chuyển lại ready.');
            }
            $check = self::check($connection, $releaseId, $projectId, $currentRole);
            if ($check['problems'] !== []) {
                throw new ValidationException(
                    "Chưa đủ điều kiện để chuyển ready:\n- " . implode("\n- ", $check['problems'])
                );
            }
            if ((string)$release['status'] === 'planned') {
                authorizeReleaseTransition($connection);
                $updated = queryParams(
                    $connection,
                    "UPDATE releases SET status='ready',checked_at=now()
                     WHERE id=$1 AND project_id=$2 AND status='planned'",
                    [(string)$releaseId, (string)$projectId]
                );
                if (pg_affected_rows($updated) !== 1) {
                    throw new ConflictException('Không thể chuyển phiên bản sang ready.');
                }
                audit($connection, $projectId, $userId, 'release_ready', 'release', $releaseId, [
                    'item_count' => count($check['items']),
                ]);
            }
        });
    }

    /**
     * Chốt phát hành (ready → released) trong MỘT transaction:
     *  1. khóa dự án và SELECT ... FOR UPDATE dòng releases, nên hai người bấm
     *     "Chốt" cùng lúc sẽ xếp hàng; người sau thấy status đã là released;
     *  2. chạy lại check() trên dữ liệu đang bị khóa;
     *  3. ghi snapshot từng release_items (kiểm tra số dòng bị UPDATE);
     *  4. UPDATE releases ... WHERE status='ready'; 0 dòng => ném lỗi, toàn bộ
     *     transaction ROLLBACK, không có snapshot dở dang.
     */
    public static function release(
        PgSql\Connection $connection,
        int $releaseId,
        int $projectId,
        int $userId,
        ?string $role
    ): void {
        transaction($connection, function () use ($connection, $releaseId, $projectId, $userId): void {
            projectLock($connection, $projectId);
            $currentRole = requireProjectRoleInTransaction(
                $connection,
                $projectId,
                $userId,
                ['release_manager'],
                'Chỉ release manager hoặc admin được chốt phiên bản.'
            );
            $release = self::releaseForUpdate($connection, $releaseId, $projectId);
            if ((string)$release['status'] === 'released') {
                throw new ConflictException('Phiên bản đã được chốt bởi một yêu cầu khác.');
            }
            if ((string)$release['status'] !== 'ready') {
                throw new ConflictException('Phiên bản phải ở trạng thái ready trước khi chốt phát hành.');
            }
            $check = self::check($connection, $releaseId, $projectId, $currentRole);
            if ($check['problems'] !== []) {
                throw new ValidationException(
                    "Không thể chốt phát hành:\n- " . implode("\n- ", $check['problems'])
                );
            }
            // Snapshot only the test result that is actually recognised as release
            // evidence. A test row without trusted provenance is stored as
            // "unverified" so release notes never present it as a verified pass.
            foreach ($check['items'] as $item) {
                $trusted = self::latestTestTrusted(
                    $item['latest_test_tester_id'] ?? null,
                    $item['latest_test_result'] ?? null,
                    $item['latest_test_version'] ?? null,
                    $item['latest_test_audit_user_id'] ?? null,
                    $item['latest_test_audit_details'] ?? null
                );
                $snapshotResult = $item['latest_test_result'] === null
                    ? 'not_tested'
                    : ($trusted ? (string)$item['latest_test_result'] : 'unverified');
                $snapshot = queryParams(
                    $connection,
                    'UPDATE release_items
                     SET snapshot_title=$1,
                         snapshot_status=$2,
                         snapshot_priority=$3,
                         snapshot_assignee=$4,
                         snapshot_description=$5,
                         snapshot_commit_url=$6,
                         snapshot_test_result=$7,
                         snapshot_test_version=$8,
                         snapshot_test_tested_at=$9,
                         snapshot_issue_type=$13
                     WHERE release_id=$10 AND project_id=$11 AND issue_id=$12',
                    [
                        (string)($item['issue_title'] ?? ''),
                        (string)($item['issue_status'] ?? ''),
                        (string)($item['issue_priority'] ?? ''),
                        (string)($item['issue_assignee'] ?? ''),
                        (string)($item['issue_description'] ?? ''),
                        (string)($item['issue_commit_url'] ?? ''),
                        $snapshotResult,
                        $item['latest_test_version'] === null ? null : (string)$item['latest_test_version'],
                        $item['latest_test_tested_at'] === null ? null : (string)$item['latest_test_tested_at'],
                        (string)$releaseId,
                        (string)$projectId,
                        (string)$item['issue_id'],
                        $item['issue_type'] ?? null,
                    ]
                );
                if (pg_affected_rows($snapshot) !== 1) {
                    throw new ConflictException('Danh sách công việc đã thay đổi trong lúc chốt. Vui lòng thử lại.');
                }
            }
            $projectSnapshot = queryParams(
                $connection,
                'UPDATE releases r
                 SET project_name_snapshot=p.name
                 FROM projects p
                 WHERE r.id=$1 AND r.project_id=$2 AND p.id=r.project_id AND r.status=\'ready\'',
                [(string)$releaseId, (string)$projectId]
            );
            if (pg_affected_rows($projectSnapshot) !== 1) {
                throw new ConflictException('Không thể lưu snapshot tên dự án khi chốt phát hành.');
            }
            authorizeReleaseTransition($connection);
            $updated = queryParams(
                $connection,
                "UPDATE releases
                 SET status='released',checked_at=COALESCE(checked_at,now()),released_at=now()
                 WHERE id=$1 AND project_id=$2 AND status='ready'",
                [(string)$releaseId, (string)$projectId]
            );
            if (pg_affected_rows($updated) !== 1) {
                throw new ConflictException('Phiên bản đã được chốt bởi một yêu cầu khác.');
            }
            audit($connection, $projectId, $userId, 'release_snapshotted', 'release', $releaseId, [
                'item_count' => count($check['items']),
            ]);
        });
    }

    public static function snapshot(
        PgSql\Connection $connection,
        int $releaseId,
        int $projectId,
        int $userId,
        ?string $role = null
    ): void {
        self::release($connection, $releaseId, $projectId, $userId, $role);
    }

    public static function addItem(
        PgSql\Connection $connection,
        int $releaseId,
        int $projectId,
        int $issueId,
        int $userId,
        ?string $role
    ): void {
        transaction($connection, function () use (
            $connection,
            $releaseId,
            $projectId,
            $issueId,
            $userId
        ): void {
            projectLock($connection, $projectId);
            requireProjectRoleInTransaction(
                $connection,
                $projectId,
                $userId,
                ['release_manager'],
                'Chỉ release manager hoặc admin được thay đổi danh sách phát hành.'
            );
            $release = self::releaseForUpdate($connection, $releaseId, $projectId);
            if ((string)$release['status'] !== 'planned') {
                throw new ConflictException('Chỉ được thêm công việc vào phiên bản đang ở trạng thái planned.');
            }
            $issue = queryOne(
                $connection,
                'SELECT i.*,COALESCE(u.full_name,\'\') AS assignee,
                        t.result AS latest_test_result,t.issue_version AS latest_test_version,
                        t.tested_at AS latest_test_tested_at
                 FROM issues i
                 LEFT JOIN users u ON u.id=i.assignee_id
                 LEFT JOIN LATERAL (
                     SELECT tr.result,tr.issue_version,tr.tested_at
                     FROM test_results tr
                     WHERE tr.issue_id=i.id
                     ORDER BY tr.id DESC
                     LIMIT 1
                 ) t ON true
                 WHERE i.id=$1 AND i.project_id=$2',
                [(string)$issueId, (string)$projectId]
            );
            if ($issue === null) {
                throw new NotFoundException('Công việc không thuộc dự án của phiên bản.');
            }
            $duplicate = queryOne(
                $connection,
                'SELECT 1 FROM release_items WHERE release_id=$1 AND issue_id=$2',
                [(string)$releaseId, (string)$issueId]
            );
            if ($duplicate !== null) {
                throw new ConflictException('Công việc đã có trong phiên bản.');
            }
            $inserted = queryParams(
                $connection,
                'INSERT INTO release_items(
                    release_id,issue_id,project_id,snapshot_title,snapshot_status,snapshot_priority,
                    snapshot_assignee,snapshot_description,snapshot_commit_url,snapshot_test_result,
                    snapshot_test_version,snapshot_test_tested_at,snapshot_issue_type
                 ) VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9,COALESCE($10,\'not_tested\'),$11,$12,$13)',
                [
                    (string)$releaseId,
                    (string)$issueId,
                    (string)$projectId,
                    (string)$issue['title'],
                    (string)$issue['status'],
                    (string)$issue['priority'],
                    (string)$issue['assignee'],
                    (string)$issue['description'],
                    (string)$issue['commit_url'],
                    $issue['latest_test_result'],
                    $issue['latest_test_version'],
                    $issue['latest_test_tested_at'],
                    (string)$issue['issue_type'],
                ]
            );
            if (pg_affected_rows($inserted) !== 1) {
                throw new ConflictException('Không thể thêm công việc vào phiên bản.');
            }
            audit($connection, $projectId, $userId, 'release_item_added', 'release', $releaseId, [
                'issue_id' => $issueId,
            ]);
        });
    }

    public static function removeItem(
        PgSql\Connection $connection,
        int $releaseId,
        int $projectId,
        int $issueId,
        int $userId,
        ?string $role
    ): void {
        transaction($connection, function () use (
            $connection,
            $releaseId,
            $projectId,
            $issueId,
            $userId
        ): void {
            projectLock($connection, $projectId);
            requireProjectRoleInTransaction(
                $connection,
                $projectId,
                $userId,
                ['release_manager'],
                'Chỉ release manager hoặc admin được thay đổi danh sách phát hành.'
            );
            $release = self::releaseForUpdate($connection, $releaseId, $projectId);
            if ((string)$release['status'] !== 'planned') {
                throw new ConflictException('Chỉ được gỡ công việc khỏi phiên bản đang ở trạng thái planned.');
            }
            $deleted = queryParams(
                $connection,
                'DELETE FROM release_items
                 WHERE release_id=$1 AND project_id=$2 AND issue_id=$3',
                [(string)$releaseId, (string)$projectId, (string)$issueId]
            );
            if (pg_affected_rows($deleted) !== 1) {
                throw new NotFoundException('Không tìm thấy công việc trong phiên bản.');
            }
            audit($connection, $projectId, $userId, 'release_item_removed', 'release', $releaseId, [
                'issue_id' => $issueId,
            ]);
        });
    }

    /**
     * Blocker chỉ đạt khi đồng thời:
     *  1. trạng thái hiện tại là verified hoặc closed (bị mở lại => trượt);
     *  2. kết quả kiểm thử MỚI NHẤT là passed;
     *  3. lần test đó làm trên đúng version hiện tại, tức là không có lần cập
     *     nhật nội dung/trạng thái nào sau lần test passed (mỗi lần sửa đều
     *     tăng version);
     *  4. lần test có bằng chứng nguồn gốc hợp lệ trong audit_log.
     */
    private static function blockerTestProblem(
        mixed $latestResult,
        mixed $latestVersion,
        int $version,
        bool $latestTrusted,
        string $status = 'verified'
    ): ?string {
        $reasons = [];
        if (!in_array($status, ['verified', 'closed'], true)) {
            $reasons[] = 'đang ở trạng thái ' . $status . ' (' . statusLabel($status) . '), chưa verified/closed';
        }
        if ($latestResult === null) {
            $reasons[] = 'chưa có kết quả kiểm thử lại';
        } elseif ((string)$latestResult !== 'passed') {
            $reasons[] = 'có kết quả kiểm thử mới nhất là ' . (string)$latestResult . ', chưa đạt';
        } elseif ($latestVersion === null || (int)$latestVersion !== $version) {
            $reasons[] = 'có kết quả passed nhưng đã lỗi thời so với phiên bản dữ liệu hiện tại '
                . '(công việc đã bị sửa hoặc mở lại sau lần kiểm thử)';
        } elseif (!$latestTrusted) {
            $reasons[] = 'có kết quả passed nhưng thiếu bằng chứng nguồn gốc hợp lệ';
        }
        return $reasons === [] ? null : implode('; ', $reasons);
    }

    private static function latestTestTrusted(
        mixed $testerId,
        mixed $result,
        mixed $issueVersion,
        mixed $auditUserId,
        mixed $auditDetails
    ): bool {
        if ($result === null) {
            return false;
        }
        $audit = $auditUserId === null && $auditDetails === null
            ? null
            : ['user_id' => $auditUserId, 'details' => $auditDetails];
        return TestEvidence::isTrusted([
            'tester_id' => $testerId,
            'result' => $result,
            'issue_version' => $issueVersion,
        ], $audit);
    }

    private static function releaseForUpdate(
        PgSql\Connection $connection,
        int $releaseId,
        int $projectId
    ): array {
        $release = queryOne(
            $connection,
            'SELECT * FROM releases WHERE id=$1 AND project_id=$2 FOR UPDATE',
            [(string)$releaseId, (string)$projectId]
        );
        if ($release === null) {
            throw new NotFoundException('Không tìm thấy phiên bản trong dự án.');
        }
        return $release;
    }
}
