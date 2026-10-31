<?php

declare(strict_types=1);

require_once __DIR__ . '/App.php';

final class DependencyService
{
    public static function findPath(array $adjacency, int $start, int $target): ?array
    {
        $stack = [[$start, [$start]]];
        $seen = [];
        while ($stack !== []) {
            [$node, $path] = array_pop($stack);
            if ($node === $target) {
                return $path;
            }
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            foreach ($adjacency[$node] ?? [] as $next) {
                if (!isset($seen[$next])) {
                    $stack[] = [$next, [...$path, $next]];
                }
            }
        }
        return null;
    }

    public static function findCycle(array $adjacency): ?array
    {
        $state = [];
        $stack = [];
        $visit = function (int $node) use (&$visit, &$adjacency, &$state, &$stack): ?array {
            $state[$node] = 1;
            $stack[] = $node;
            foreach ($adjacency[$node] ?? [] as $next) {
                $nextState = $state[$next] ?? 0;
                if ($nextState === 0) {
                    $cycle = $visit($next);
                    if ($cycle !== null) {
                        return $cycle;
                    }
                } elseif ($nextState === 1) {
                    $index = array_search($next, $stack, true);
                    $cycle = array_slice($stack, $index === false ? 0 : (int)$index);
                    $cycle[] = $next;
                    return $cycle;
                }
            }
            array_pop($stack);
            $state[$node] = 2;
            return null;
        };
        foreach (array_keys($adjacency) as $node) {
            if (($state[$node] ?? 0) === 0) {
                $cycle = $visit((int)$node);
                if ($cycle !== null) {
                    return $cycle;
                }
            }
        }
        return null;
    }

    public static function adjacency(PgSql\Connection $connection, int $projectId): array
    {
        $rows = queryAll(
            $connection,
            'SELECT d.issue_id, d.depends_on_id
             FROM dependencies d
             JOIN issues a ON a.id=d.issue_id AND a.project_id=$1
             JOIN issues b ON b.id=d.depends_on_id AND b.project_id=$1
             WHERE d.issue_project_id=$1 AND d.depends_on_project_id=$1
             ORDER BY d.issue_id, d.depends_on_id',
            [(string)$projectId]
        );
        $adjacency = [];
        foreach ($rows as $row) {
            $adjacency[(int)$row['issue_id']][] = (int)$row['depends_on_id'];
        }
        return $adjacency;
    }

    public static function path(PgSql\Connection $connection, int $projectId, int $start, int $target): ?array
    {
        return self::findPath(self::adjacency($connection, $projectId), $start, $target);
    }

    public static function projectCycle(PgSql\Connection $connection, int $projectId): ?array
    {
        return self::findCycle(self::adjacency($connection, $projectId));
    }

    public static function add(
        PgSql\Connection $connection,
        int $projectId,
        int $issueId,
        int $dependsOnId,
        ?int $userId = null
    ): void {
        if ($projectId <= 0 || $issueId <= 0 || $dependsOnId <= 0) {
            throw new ValidationException('ID công việc hoặc dự án không hợp lệ.');
        }
        if ($issueId === $dependsOnId) {
            throw new ValidationException('Một công việc không thể phụ thuộc chính nó.');
        }
        transaction($connection, function () use ($connection, $projectId, $issueId, $dependsOnId, $userId): void {
            projectLock($connection, $projectId);
            if ($userId === null) {
                throw new ForbiddenException(
                    'Bạn không có quyền thay đổi phụ thuộc trong dự án này. '
                    . 'Vui lòng đăng nhập lại.'
                );
            }
            requireProjectRoleInTransaction(
                $connection,
                $projectId,
                $userId,
                ['developer', 'release_manager'],
                'Bạn không có quyền thay đổi phụ thuộc trong dự án này.'
            );
            $rows = queryAll(
                $connection,
                'SELECT id, project_id FROM issues WHERE project_id=$1 AND id IN ($2,$3)',
                [(string)$projectId, (string)$issueId, (string)$dependsOnId]
            );
            if (count($rows) !== 2) {
                throw new NotFoundException('Hai công việc phải tồn tại và thuộc cùng dự án.');
            }
            $duplicate = queryOne(
                $connection,
                'SELECT 1 FROM dependencies WHERE issue_id=$1 AND depends_on_id=$2',
                [(string)$issueId, (string)$dependsOnId]
            );
            if ($duplicate !== null) {
                throw new ConflictException('Quan hệ phụ thuộc đã tồn tại.');
            }
            $path = self::path($connection, $projectId, $dependsOnId, $issueId);
            if ($path !== null) {
                $path[] = $dependsOnId;
                $uniqueIds = array_values(array_unique($path));
                $placeholders = [];
                $params = [];
                foreach ($uniqueIds as $index => $id) {
                    $placeholders[] = '$' . ($index + 1);
                    $params[] = (string)$id;
                }
                $titles = [];
                $rows = queryAll(
                    $connection,
                    'SELECT id,title FROM issues WHERE id IN (' . implode(',', $placeholders) . ')',
                    $params
                );
                foreach ($rows as $row) {
                    $titles[(int)$row['id']] = (string)$row['title'];
                }
                $label = implode(' -> ', array_map(
                    static fn(int $id): string => $titles[$id] ?? ('#' . $id),
                    $path
                ));
                $ids = implode(' -> ', array_map(static fn(int $id): string => '#' . $id, $path));
                throw new ConflictException(
                    'Từ chối quan hệ vòng: ' . $label . ' (' . $ids . ')'
                );
            }
            $result = queryParams(
                $connection,
                'INSERT INTO dependencies(issue_id,depends_on_id,issue_project_id,depends_on_project_id)
                 VALUES($1,$2,$3,$3)',
                [(string)$issueId, (string)$dependsOnId, (string)$projectId]
            );
            if (pg_affected_rows($result) !== 1) {
                throw new ConflictException('Không thể lưu quan hệ phụ thuộc.');
            }
            self::invalidateReadyReleases($connection, $projectId, [$issueId, $dependsOnId]);
            if ($userId !== null) {
                audit($connection, $projectId, $userId, 'dependency_added', 'issue', $issueId, [
                    'depends_on' => $dependsOnId,
                ]);
            }
        });
    }

    public static function remove(
        PgSql\Connection $connection,
        int $projectId,
        int $issueId,
        int $dependsOnId,
        ?int $userId = null
    ): void {
        transaction($connection, function () use ($connection, $projectId, $issueId, $dependsOnId, $userId): void {
            projectLock($connection, $projectId);
            if ($userId === null) {
                throw new ForbiddenException(
                    'Bạn không có quyền thay đổi phụ thuộc trong dự án này. '
                    . 'Vui lòng đăng nhập lại.'
                );
            }
            requireProjectRoleInTransaction(
                $connection,
                $projectId,
                $userId,
                ['developer', 'release_manager'],
                'Bạn không có quyền thay đổi phụ thuộc trong dự án này.'
            );
            $result = queryParams(
                $connection,
                'DELETE FROM dependencies
                 WHERE issue_id=$1 AND depends_on_id=$2 AND issue_project_id=$3 AND depends_on_project_id=$3',
                [(string)$issueId, (string)$dependsOnId, (string)$projectId]
            );
            if (pg_affected_rows($result) !== 1) {
                throw new NotFoundException('Không tìm thấy quan hệ phụ thuộc trong dự án.');
            }
            self::invalidateReadyReleases($connection, $projectId, [$issueId, $dependsOnId]);
            if ($userId !== null) {
                audit($connection, $projectId, $userId, 'dependency_removed', 'issue', $issueId, [
                    'depends_on' => $dependsOnId,
                ]);
            }
        });
    }

    public static function listForProject(PgSql\Connection $connection, int $projectId): array
    {
        return queryAll(
            $connection,
            'SELECT d.issue_id,d.depends_on_id,
                    a.title AS issue_title,b.title AS dependency_title,b.status AS dependency_status
             FROM dependencies d
             JOIN issues a ON a.id=d.issue_id AND a.project_id=$1
             JOIN issues b ON b.id=d.depends_on_id AND b.project_id=$1
             WHERE d.issue_project_id=$1 AND d.depends_on_project_id=$1
             ORDER BY d.issue_id, d.depends_on_id',
            [(string)$projectId]
        );
    }

    public static function blockedForProject(PgSql\Connection $connection, int $projectId): array
    {
        return queryAll(
            $connection,
            "SELECT a.id,a.title,b.id AS blocked_by_id,b.title AS blocked_by,b.status AS dependency_status
             FROM dependencies d
             JOIN issues a ON a.id=d.issue_id AND a.project_id=$1
             JOIN issues b ON b.id=d.depends_on_id AND b.project_id=$1
             WHERE d.issue_project_id=$1 AND d.depends_on_project_id=$1
               AND b.status NOT IN ('verified','closed')
             ORDER BY a.id, b.id",
            [(string)$projectId]
        );
    }

    public static function invalidateReadyReleases(
        PgSql\Connection $connection,
        int $projectId,
        array $issueIds
    ): void {
        $ids = array_values(array_unique(array_filter(array_map('intval', $issueIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }
        $placeholders = [];
        $params = [(string)$projectId];
        foreach ($ids as $index => $id) {
            $placeholders[] = '$' . ($index + 2);
            $params[] = (string)$id;
        }
        queryParams(
            $connection,
            "UPDATE releases
             SET status='planned', checked_at=NULL
             WHERE project_id=$1 AND status='ready'
               AND id IN (
                   SELECT ri.release_id FROM release_items ri
                   WHERE ri.project_id=$1
                     AND (
                         ri.issue_id IN (" . implode(',', $placeholders) . ")
                         OR EXISTS (
                             SELECT 1 FROM dependencies d
                             WHERE d.issue_project_id=$1
                               AND d.depends_on_project_id=$1
                               AND d.issue_id=ri.issue_id
                               AND d.depends_on_id IN (" . implode(',', $placeholders) . ')
                         )
                     )
               )',
            $params
        );
    }
}
