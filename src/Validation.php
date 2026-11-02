<?php

declare(strict_types=1);

require_once __DIR__ . '/Exceptions.php';

function textLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function normalizedText(string $value): string
{
    return str_replace(["\r\n", "\r"], "\n", trim($value));
}

function postString(
    array $input,
    string $key,
    int $max = 0,
    bool $required = true,
    string $label = 'Dữ liệu'
): string {
    $raw = $input[$key] ?? '';
    if (!is_string($raw)) {
        throw new ValidationException($label . ' không hợp lệ.');
    }
    $value = normalizedText($raw);
    if (str_contains($value, "\0")) {
        throw new ValidationException($label . ' chứa ký tự không hợp lệ.');
    }
    if ($required && $value === '') {
        throw new ValidationException($label . ' không được để trống.');
    }
    if ($max > 0 && textLength($value) > $max) {
        throw new ValidationException($label . ' vượt quá ' . $max . ' ký tự.');
    }
    return $value;
}

function postInt(
    array $input,
    string $key,
    int $min = 1,
    int $max = PHP_INT_MAX,
    string $label = 'ID'
): int {
    $raw = $input[$key] ?? null;
    if (is_int($raw)) {
        $value = $raw;
    } elseif (is_string($raw) && preg_match('/^-?\d+$/', trim($raw)) === 1) {
        $value = (int)trim($raw);
    } else {
        throw new ValidationException($label . ' không hợp lệ.');
    }
    if ($value < $min || $value > $max) {
        throw new ValidationException($label . ' nằm ngoài phạm vi cho phép.');
    }
    return $value;
}

function postOptionalInt(array $input, string $key, int $min = 1, int $max = PHP_INT_MAX, string $label = 'ID'): ?int
{
    $raw = $input[$key] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }
    return postInt($input, $key, $min, $max, $label);
}

function postEnum(array $input, string $key, array $allowed, string $label = 'Giá trị'): string
{
    $raw = $input[$key] ?? '';
    if (!is_string($raw) || !in_array($raw, $allowed, true)) {
        throw new ValidationException($label . ' không hợp lệ.');
    }
    return $raw;
}

function postUrl(array $input, string $key, int $max = 500, string $label = 'URL'): string
{
    $value = postString($input, $key, $max, false, $label);
    if ($value === '') {
        return '';
    }
    $valid = filter_var($value, FILTER_VALIDATE_URL) !== false;
    $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
    if (!$valid || !in_array($scheme, ['http', 'https'], true)) {
        throw new ValidationException($label . ' phải là URL http hoặc https hợp lệ.');
    }
    return $value;
}

function issuePriorities(): array
{
    return ['blocker', 'critical', 'high', 'medium', 'low'];
}

function issueStatuses(): array
{
    return ['open', 'in_progress', 'resolved', 'retest', 'verified', 'closed'];
}

function issueTypes(): array
{
    return ['bug', 'task', 'feature'];
}

function testResults(): array
{
    return ['passed', 'failed', 'blocked'];
}

function projectRoles(): array
{
    return ['developer', 'tester', 'release_manager', 'admin'];
}

function releaseStatuses(): array
{
    return ['planned', 'ready', 'released'];
}
