<?php

declare(strict_types=1);

require_once __DIR__ . '/App.php';

final class ProjectService
{
    public static function create(
        PgSql\Connection $connection,
        int $userId,
        array $input
    ): int {
        $name = postString($input, 'name', 160, true, 'Tên dự án');
        $description = postString($input, 'description', 20000, false, 'Mô tả');
        return transaction($connection, function () use (
            $connection,
            $userId,
            $name,
            $description
        ): int {
            requireAnyProjectAdminInTransaction(
                $connection,
                $userId,
                'Chỉ admin mới được tạo dự án.'
            );
            $duplicate = queryOne(
                $connection,
                'SELECT 1 FROM projects WHERE name=$1',
                [$name]
            );
            if ($duplicate !== null) {
                throw new ConflictException('Tên dự án đã tồn tại.');
            }
            $result = queryParams(
                $connection,
                'INSERT INTO projects(name,description) VALUES($1,$2) RETURNING id',
                [$name, $description]
            );
            $projectId = (int)pg_fetch_result($result, 0, 0);
            queryParams(
                $connection,
                "INSERT INTO project_members(project_id,user_id,role) VALUES(\$1,\$2,'admin')",
                [(string)$projectId, (string)$userId]
            );
            audit($connection, $projectId, $userId, 'project_created', 'project', $projectId, [
                'name' => $name,
            ]);
            return $projectId;
        });
    }

    public static function update(
        PgSql\Connection $connection,
        int $projectId,
        int $userId,
        array $input
    ): void {
        $name = postString($input, 'name', 160, true, 'Tên dự án');
        $description = postString($input, 'description', 20000, false, 'Mô tả');
        transaction($connection, function () use (
            $connection,
            $projectId,
            $userId,
            $name,
            $description
        ): void {
            projectLock($connection, $projectId);
            requireProjectRoleInTransaction(
                $connection,
                $projectId,
                $userId,
                ['admin'],
                'Chỉ admin mới được cập nhật dự án.'
            );
            $duplicate = queryOne(
                $connection,
                'SELECT 1 FROM projects WHERE name=$1 AND id<>$2',
                [$name, (string)$projectId]
            );
            if ($duplicate !== null) {
                throw new ConflictException('Tên dự án đã tồn tại.');
            }
            queryParams(
                $connection,
                'UPDATE projects SET name=$1,description=$2 WHERE id=$3',
                [$name, $description, (string)$projectId]
            );
            audit($connection, $projectId, $userId, 'project_updated', 'project', $projectId, [
                'name' => $name,
            ]);
        });
    }

    public static function setMemberRole(
        PgSql\Connection $connection,
        int $projectId,
        int $userId,
        string $username,
        string $role
    ): void {
        transaction($connection, function () use (
            $connection,
            $projectId,
            $userId,
            $username,
            $role
        ): void {
            projectLock($connection, $projectId);
            requireProjectRoleInTransaction(
                $connection,
                $projectId,
                $userId,
                ['admin'],
                'Chỉ admin mới được thay đổi thành viên dự án.'
            );
            $member = queryOne(
                $connection,
                'SELECT id FROM users WHERE username=$1',
                [$username]
            );
            if ($member === null) {
                throw new NotFoundException('Không tìm thấy người dùng.');
            }
            $memberId = (int)$member['id'];
            $current = queryOne(
                $connection,
                'SELECT role FROM project_members WHERE project_id=$1 AND user_id=$2',
                [(string)$projectId, (string)$memberId]
            );
            if ($current !== null && (string)$current['role'] === 'admin' && $role !== 'admin') {
                $adminCount = queryOne(
                    $connection,
                    "SELECT count(*) AS total FROM project_members WHERE project_id=\$1 AND role='admin'",
                    [(string)$projectId]
                );
                if ((int)$adminCount['total'] <= 1) {
                    throw new ConflictException('Dự án phải còn ít nhất một admin.');
                }
            }
            queryParams(
                $connection,
                'INSERT INTO project_members(project_id,user_id,role)
                 VALUES($1,$2,$3)
                 ON CONFLICT(project_id,user_id) DO UPDATE SET role=EXCLUDED.role',
                [(string)$projectId, (string)$memberId, $role]
            );
            audit($connection, $projectId, $userId, 'member_set', 'user', $memberId, [
                'username' => $username,
                'role' => $role,
            ]);
        });
    }

    public static function removeMember(
        PgSql\Connection $connection,
        int $projectId,
        int $userId,
        int $memberId
    ): void {
        transaction($connection, function () use (
            $connection,
            $projectId,
            $userId,
            $memberId
        ): void {
            projectLock($connection, $projectId);
            requireProjectRoleInTransaction(
                $connection,
                $projectId,
                $userId,
                ['admin'],
                'Chỉ admin mới được thay đổi thành viên dự án.'
            );
            $current = queryOne(
                $connection,
                'SELECT role FROM project_members WHERE project_id=$1 AND user_id=$2',
                [(string)$projectId, (string)$memberId]
            );
            if ($current === null) {
                throw new NotFoundException('Người dùng không thuộc dự án.');
            }
            if ((string)$current['role'] === 'admin') {
                $adminCount = queryOne(
                    $connection,
                    "SELECT count(*) AS total FROM project_members WHERE project_id=\$1 AND role='admin'",
                    [(string)$projectId]
                );
                if ((int)$adminCount['total'] <= 1) {
                    throw new ConflictException('Dự án phải còn ít nhất một admin.');
                }
            }
            queryParams(
                $connection,
                'DELETE FROM project_members WHERE project_id=$1 AND user_id=$2',
                [(string)$projectId, (string)$memberId]
            );
            audit($connection, $projectId, $userId, 'member_removed', 'user', $memberId);
        });
    }
}
