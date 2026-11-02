<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/TestEvidence.php';

function legacyReportUsage(): void
{
    echo "Cách dùng: php tools/legacy-report.php [--json] [--schema=NAME] [--project=ID]\n";
    echo "  --json           Xuất JSON thay vì văn bản.\n";
    echo "  --schema=NAME    Chỉ đọc schema chỉ định (dùng cho schema test).\n";
    echo "  --project=ID     Chỉ đọc dữ liệu của một dự án.\n";
    echo "Công cụ chỉ chạy SELECT trong transaction READ ONLY; không sửa dữ liệu.\n";
}

$options = getopt('', ['json', 'schema:', 'project:', 'help']);
if ($options === false) {
    fwrite(STDERR, "LEGACY_REPORT_FAILED: tham số không hợp lệ.\n");
    exit(2);
}
if (isset($options['help'])) {
    legacyReportUsage();
    exit(0);
}

$schema = isset($options['schema']) ? (string)$options['schema'] : null;
if ($schema !== null) {
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema) !== 1) {
        fwrite(STDERR, "LEGACY_REPORT_FAILED: tên schema không hợp lệ.\n");
        exit(2);
    }
    putenv('RT_DB_SCHEMA=' . $schema);
}

$projectId = null;
if (isset($options['project'])) {
    $rawProject = (string)$options['project'];
    if (!ctype_digit($rawProject) || (int)$rawProject <= 0) {
        fwrite(STDERR, "LEGACY_REPORT_FAILED: project ID không hợp lệ.\n");
        exit(2);
    }
    $projectId = (int)$rawProject;
}
$jsonOutput = isset($options['json']);
$connection = null;

try {
    $connection = db();
    execSql($connection, 'BEGIN TRANSACTION READ ONLY');
    $readOnlyRow = queryOne($connection, 'SHOW transaction_read_only');
    if (($readOnlyRow['transaction_read_only'] ?? '') !== 'on') {
        throw new RuntimeException('Không thiết lập được transaction READ ONLY.');
    }
    $meta = queryOne(
        $connection,
        'SELECT current_database() AS database_name, current_schema() AS schema_name'
    );
    $projectParam = $projectId === null ? null : (string)$projectId;

    $testRows = queryAll(
        $connection,
        "WITH latest AS (
            SELECT DISTINCT ON (t.issue_id) t.id, t.issue_id
            FROM test_results t
            ORDER BY t.issue_id, t.id DESC
        )
        SELECT t.id AS test_id,t.issue_id,t.project_id,
               i.title AS issue_title,i.status AS issue_status,i.priority AS issue_priority,
               i.version AS issue_current_version,
               t.issue_version AS test_issue_version,t.result,t.tested_at,
               t.tester_id,u.username AS tester_username,u.full_name AS tester_name,
               pm.role AS tester_role_current,
               a.user_id AS audit_user_id,a.details AS audit_details,
               (l.id = t.id) AS is_latest
        FROM test_results t
        JOIN issues i ON i.id=t.issue_id
        LEFT JOIN users u ON u.id=t.tester_id
        LEFT JOIN project_members pm ON pm.project_id=t.project_id AND pm.user_id=t.tester_id
        LEFT JOIN LATERAL (
            SELECT al.user_id,al.details
            FROM audit_log al
            WHERE al.action='test_result_recorded'
              AND al.entity_type='test_result'
              AND al.entity_id=t.id
              AND al.project_id=t.project_id
            ORDER BY al.id DESC
            LIMIT 1
        ) a ON true
        JOIN latest l ON l.issue_id=t.issue_id
        WHERE (\$1::bigint IS NULL OR t.project_id=\$1)
        ORDER BY t.id",
        [$projectParam]
    );

    $issueRows = queryAll(
        $connection,
        "SELECT i.id AS issue_id,i.project_id,i.title,i.status,i.priority,i.version,
                lt.id AS latest_test_id,lt.result AS latest_result,
                lt.issue_version AS latest_test_version,lt.tested_at AS latest_tested_at,
                lt.tester_id AS latest_tester_id,
                lt.audit_user_id AS latest_audit_user_id,
                lt.audit_details AS latest_audit_details
         FROM issues i
         LEFT JOIN LATERAL (
             SELECT t.id,t.result,t.issue_version,t.tested_at,t.tester_id,
                    a.user_id AS audit_user_id,a.details AS audit_details
             FROM test_results t
             LEFT JOIN LATERAL (
                 SELECT al.user_id,al.details
                 FROM audit_log al
                 WHERE al.action='test_result_recorded'
                   AND al.entity_type='test_result'
                   AND al.entity_id=t.id
                   AND al.project_id=t.project_id
                 ORDER BY al.id DESC
                 LIMIT 1
             ) a ON true
             WHERE t.issue_id=i.id
             ORDER BY t.id DESC
             LIMIT 1
         ) lt ON true
         WHERE (i.status='verified' OR i.priority='blocker'
                OR lt.result IN ('failed','blocked'))
           AND (\$1::bigint IS NULL OR i.project_id=\$1)
         ORDER BY i.id",
        [$projectParam]
    );

    $tests = [];
    $summary = [
        'tests' => 0,
        'legacy_tests' => 0,
        'trusted_tests' => 0,
        'untrusted_tests' => 0,
        'valid_current_pass' => 0,
        'untrusted_current_pass' => 0,
        'latest_failed' => 0,
        'latest_blocked' => 0,
        'superseded_tests' => 0,
    ];
    foreach ($testRows as $row) {
        $testVersion = (int)$row['test_issue_version'];
        $currentVersion = (int)$row['issue_current_version'];
        $result = (string)$row['result'];
        $isLatest = in_array((string)$row['is_latest'], ['1', 't', 'true'], true);
        $audit = $row['audit_user_id'] === null && $row['audit_details'] === null
            ? null
            : ['user_id' => $row['audit_user_id'], 'details' => $row['audit_details']];
        $provenance = TestEvidence::provenance([
            'tester_id' => $row['tester_id'],
            'result' => $result,
            'issue_version' => $testVersion,
        ], $audit);
        $trusted = $provenance === TestEvidence::PROVENANCE_AUDITED;
        $recordedRole = TestEvidence::recordedRole($audit['details'] ?? null);
        $versionState = $testVersion === 0
            ? 'legacy_unknown'
            : ($testVersion === $currentVersion ? 'current' : 'stale');
        if (!$isLatest) {
            $validity = 'superseded';
        } elseif ($versionState === 'legacy_unknown') {
            $validity = $result === 'passed' ? 'legacy_unknown_pass' : 'legacy_unknown_non_pass';
        } elseif ($versionState === 'stale') {
            $validity = 'stale_version';
        } elseif ($result !== 'passed') {
            $validity = 'current_non_pass';
        } elseif (!$trusted) {
            $validity = 'untrusted_current_pass';
        } else {
            $validity = 'valid_current_pass';
        }

        $testWarnings = [];
        if ($versionState === 'legacy_unknown') {
            $testWarnings[] = 'LEGACY_TEST_VERSION_UNKNOWN';
        }
        if (!$isLatest) {
            $testWarnings[] = 'SUPERSEDED_BY_NEWER_TEST';
        }
        if ($result === 'failed') {
            $testWarnings[] = 'TEST_FAILED';
        } elseif ($result === 'blocked') {
            $testWarnings[] = 'TEST_BLOCKED';
        }
        if ($versionState === 'current' && $result === 'passed' && !$trusted) {
            $testWarnings[] = 'TEST_PROVENANCE_UNVERIFIED';
        }

        $tests[] = [
            'test_id' => (int)$row['test_id'],
            'issue_id' => (int)$row['issue_id'],
            'project_id' => (int)$row['project_id'],
            'issue_title' => (string)$row['issue_title'],
            'issue_status' => (string)$row['issue_status'],
            'issue_priority' => (string)$row['issue_priority'],
            'issue_current_version' => $currentVersion,
            'test_issue_version' => $testVersion,
            'version_state' => $versionState,
            'result' => $result,
            'tested_at' => (string)$row['tested_at'],
            'tester_id' => $row['tester_id'] === null ? null : (int)$row['tester_id'],
            'tester_username' => $row['tester_username'] === null ? null : (string)$row['tester_username'],
            'tester_name' => $row['tester_name'] === null ? null : (string)$row['tester_name'],
            'tester_role_current' => $row['tester_role_current'] === null
                ? null
                : (string)$row['tester_role_current'],
            'recorded_role' => $recordedRole,
            'provenance' => $provenance,
            'trusted' => $trusted,
            'is_latest' => $isLatest,
            'validity' => $validity,
            'warnings' => $testWarnings,
        ];

        $summary['tests']++;
        if ($versionState === 'legacy_unknown') {
            $summary['legacy_tests']++;
        }
        if ($trusted) {
            $summary['trusted_tests']++;
        } else {
            $summary['untrusted_tests']++;
        }
        if ($validity === 'valid_current_pass') {
            $summary['valid_current_pass']++;
        }
        if ($validity === 'untrusted_current_pass') {
            $summary['untrusted_current_pass']++;
        }
        if ($validity === 'superseded') {
            $summary['superseded_tests']++;
        }
        if ($isLatest && $result === 'failed') {
            $summary['latest_failed']++;
        }
        if ($isLatest && $result === 'blocked') {
            $summary['latest_blocked']++;
        }
    }

    $issues = [];
    $warnings = [];
    foreach ($issueRows as $row) {
        $currentVersion = (int)$row['version'];
        $latestTestId = $row['latest_test_id'] === null ? null : (int)$row['latest_test_id'];
        $latestResult = $row['latest_result'] === null ? null : (string)$row['latest_result'];
        $latestTestVersion = $row['latest_test_version'] === null
            ? null
            : (int)$row['latest_test_version'];
        $latestAudit = $row['latest_audit_user_id'] === null && $row['latest_audit_details'] === null
            ? null
            : ['user_id' => $row['latest_audit_user_id'], 'details' => $row['latest_audit_details']];
        $latestProvenance = $latestTestId === null
            ? null
            : TestEvidence::provenance([
                'tester_id' => $row['latest_tester_id'],
                'result' => $latestResult,
                'issue_version' => $latestTestVersion,
            ], $latestAudit);
        $latestTrusted = $latestProvenance === TestEvidence::PROVENANCE_AUDITED;
        $hasValidPass = $latestTestId !== null
            && $latestResult === 'passed'
            && $latestTestVersion !== null
            && $latestTestVersion > 0
            && $latestTestVersion === $currentVersion
            && $latestTrusted;
        $latestVersionState = $latestTestVersion === null
            ? 'no_test'
            : ($latestTestVersion === 0
                ? 'legacy_unknown'
                : ($latestTestVersion === $currentVersion ? 'current' : 'stale'));

        if ($latestTestId === null) {
            $evidenceState = 'no_test';
        } elseif ($latestResult === 'failed') {
            $evidenceState = 'latest_failed';
        } elseif ($latestResult === 'blocked') {
            $evidenceState = 'latest_blocked';
        } elseif ($latestVersionState === 'legacy_unknown') {
            $evidenceState = 'legacy_unknown_version';
        } elseif ($latestVersionState === 'stale') {
            $evidenceState = 'stale_version';
        } elseif ($latestResult === 'passed' && !$latestTrusted) {
            $evidenceState = 'untrusted_provenance';
        } elseif ($latestResult === 'passed') {
            $evidenceState = 'valid_current_pass';
        } else {
            $evidenceState = 'current_non_pass';
        }

        $issueWarnings = [];
        if ((string)$row['status'] === 'verified' && !$hasValidPass) {
            $issueWarnings[] = 'VERIFIED_WITHOUT_VALID_TEST';
        }
        if ((string)$row['priority'] === 'blocker' && !$hasValidPass) {
            $issueWarnings[] = 'BLOCKER_WITHOUT_VALID_TEST';
        }
        if ($latestResult === 'failed') {
            $issueWarnings[] = 'LATEST_TEST_FAILED';
        } elseif ($latestResult === 'blocked') {
            $issueWarnings[] = 'LATEST_TEST_BLOCKED';
        }
        if ($latestVersionState === 'legacy_unknown') {
            $issueWarnings[] = 'LEGACY_TEST_VERSION_UNKNOWN';
        } elseif ($latestVersionState === 'stale') {
            $issueWarnings[] = 'STALE_TEST_VERSION';
        }
        if ($latestVersionState === 'current' && $latestResult === 'passed' && !$latestTrusted) {
            $issueWarnings[] = 'TEST_PROVENANCE_UNVERIFIED';
        }
        if ($latestTestId === null) {
            $issueWarnings[] = 'NO_TEST_RESULT';
        }

        $issues[] = [
            'issue_id' => (int)$row['issue_id'],
            'project_id' => (int)$row['project_id'],
            'title' => (string)$row['title'],
            'status' => (string)$row['status'],
            'priority' => (string)$row['priority'],
            'current_version' => $currentVersion,
            'latest_test_id' => $latestTestId,
            'latest_result' => $latestResult,
            'latest_test_version' => $latestTestVersion,
            'latest_tested_at' => $row['latest_tested_at'] === null
                ? null
                : (string)$row['latest_tested_at'],
            'latest_test_version_state' => $latestVersionState,
            'latest_provenance' => $latestProvenance,
            'latest_provenance_trusted' => $latestTrusted,
            'has_valid_current_pass' => $hasValidPass,
            'evidence_state' => $evidenceState,
            'warnings' => $issueWarnings,
        ];
        foreach ($issueWarnings as $code) {
            $warnings[] = [
                'issue_id' => (int)$row['issue_id'],
                'project_id' => (int)$row['project_id'],
                'code' => $code,
            ];
        }
    }

    $summary['verified_without_valid_test'] = count(array_filter(
        $issues,
        static fn(array $issue): bool => in_array('VERIFIED_WITHOUT_VALID_TEST', $issue['warnings'], true)
    ));
    $summary['blocker_without_valid_test'] = count(array_filter(
        $issues,
        static fn(array $issue): bool => in_array('BLOCKER_WITHOUT_VALID_TEST', $issue['warnings'], true)
    ));

    execSql($connection, 'COMMIT');

    $report = [
        'generated_at' => gmdate('c'),
        'database' => (string)($meta['database_name'] ?? ''),
        'schema' => (string)($meta['schema_name'] ?? ''),
        'read_only' => true,
        'filters' => ['project_id' => $projectId],
        'summary' => $summary,
        'tests' => $tests,
        'issues' => $issues,
        'warnings' => $warnings,
    ];

    if ($jsonOutput) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        exit(0);
    }

    echo 'LEGACY_REPORT read_only=yes database=' . $report['database']
        . ' schema=' . $report['schema'] . ' generated_at=' . $report['generated_at'] . "\n";
    echo 'SUMMARY tests=' . $summary['tests']
        . ' legacy_tests=' . $summary['legacy_tests']
        . ' trusted_tests=' . $summary['trusted_tests']
        . ' untrusted_tests=' . $summary['untrusted_tests']
        . ' valid_current_pass=' . $summary['valid_current_pass']
        . ' untrusted_current_pass=' . $summary['untrusted_current_pass']
        . ' latest_failed=' . $summary['latest_failed']
        . ' latest_blocked=' . $summary['latest_blocked']
        . ' superseded_tests=' . $summary['superseded_tests']
        . ' verified_without_valid_test=' . $summary['verified_without_valid_test']
        . ' blocker_without_valid_test=' . $summary['blocker_without_valid_test'] . "\n";
    foreach ($tests as $test) {
        echo 'TEST id=' . $test['test_id']
            . ' issue=' . $test['issue_id']
            . ' project=' . $test['project_id']
            . ' current_version=' . $test['issue_current_version']
            . ' test_version=' . $test['test_issue_version']
            . ' result=' . $test['result']
            . ' tested_at=' . $test['tested_at']
            . ' tester=' . ($test['tester_username'] ?? '-')
            . ' role_current=' . ($test['tester_role_current'] ?? '-')
            . ' recorded_role=' . ($test['recorded_role'] ?? '-')
            . ' provenance=' . $test['provenance']
            . ' latest=' . ($test['is_latest'] ? 'yes' : 'no')
            . ' validity=' . $test['validity']
            . ' issue_status=' . $test['issue_status']
            . ' priority=' . $test['issue_priority']
            . ' warnings=' . implode(',', $test['warnings']) . "\n";
    }
    foreach ($issues as $issue) {
        echo 'ISSUE id=' . $issue['issue_id']
            . ' project=' . $issue['project_id']
            . ' status=' . $issue['status']
            . ' priority=' . $issue['priority']
            . ' current_version=' . $issue['current_version']
            . ' latest_test=' . ($issue['latest_test_id'] ?? '-')
            . ' latest_result=' . ($issue['latest_result'] ?? '-')
            . ' latest_test_version=' . ($issue['latest_test_version'] ?? '-')
            . ' latest_provenance=' . ($issue['latest_provenance'] ?? '-')
            . ' evidence=' . $issue['evidence_state']
            . ' valid_current_pass=' . ($issue['has_valid_current_pass'] ? 'yes' : 'no')
            . ' warnings=' . implode(',', $issue['warnings']) . "\n";
    }
    foreach ($warnings as $warning) {
        echo 'WARNING issue=' . $warning['issue_id']
            . ' project=' . $warning['project_id']
            . ' code=' . $warning['code'] . "\n";
    }
    exit(0);
} catch (Throwable $exception) {
    if ($connection instanceof PgSql\Connection) {
        @pg_query($connection, 'ROLLBACK');
    }
    fwrite(STDERR, 'LEGACY_REPORT_FAILED: ' . $exception->getMessage() . "\n");
    exit(2);
}
