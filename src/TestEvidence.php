<?php

declare(strict_types=1);

/**
 * Test result provenance.
 *
 * A test row is only trusted as release evidence when the append-only audit log
 * proves that the row was written by an authorised role at record time.  The
 * current project membership is deliberately NOT used: a member may have been
 * promoted, demoted or removed after the test was recorded.
 */
final class TestEvidence
{
    public const TRUSTED_ROLES = ['tester', 'release_manager', 'admin'];

    public const PROVENANCE_AUDITED = 'audited_role';
    public const PROVENANCE_UNVERIFIED = 'unverified';
    public const PROVENANCE_MISMATCH = 'mismatch';

    public static function decodeDetails(mixed $details): array
    {
        if (is_array($details)) {
            return $details;
        }
        if (!is_string($details) || $details === '') {
            return [];
        }
        $decoded = json_decode($details, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function recordedRole(mixed $details): ?string
    {
        $decoded = self::decodeDetails($details);
        $role = $decoded['tester_role'] ?? null;
        return is_string($role) && $role !== '' ? $role : null;
    }

    /**
     * @param array $test  Keys: tester_id, result, issue_version.
     * @param array|null $audit Keys: user_id, details. Null when no audit exists.
     */
    public static function provenance(array $test, ?array $audit): string
    {
        if ($audit === null) {
            return self::PROVENANCE_UNVERIFIED;
        }
        $details = self::decodeDetails($audit['details'] ?? null);
        $role = $details['tester_role'] ?? null;
        if (!is_string($role) || !in_array($role, self::TRUSTED_ROLES, true)) {
            return self::PROVENANCE_UNVERIFIED;
        }
        $auditUser = isset($audit['user_id']) ? (int)$audit['user_id'] : 0;
        $testerId = isset($test['tester_id']) ? (int)$test['tester_id'] : 0;
        if ($auditUser !== $testerId) {
            return self::PROVENANCE_MISMATCH;
        }
        if ((string)($details['result'] ?? '') !== (string)($test['result'] ?? '')) {
            return self::PROVENANCE_MISMATCH;
        }
        if ((int)($details['issue_version'] ?? -1) !== (int)($test['issue_version'] ?? -2)) {
            return self::PROVENANCE_MISMATCH;
        }
        return self::PROVENANCE_AUDITED;
    }

    public static function isTrusted(array $test, ?array $audit): bool
    {
        return self::provenance($test, $audit) === self::PROVENANCE_AUDITED;
    }
}
