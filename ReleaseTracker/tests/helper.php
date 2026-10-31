<?php

declare(strict_types=1);

$GLOBALS['test_count'] = 0;
$GLOBALS['test_failed'] = 0;

function testCase(string $name, callable $callback): void
{
    $GLOBALS['test_count']++;
    try {
        $callback();
        echo 'PASS ' . $name . "\n";
    } catch (Throwable $exception) {
        $GLOBALS['test_failed']++;
        echo 'FAIL ' . $name . ': ' . get_class($exception) . ': '
            . $exception->getMessage() . "\n";
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' (expected=' . var_export($expected, true)
            . ', actual=' . var_export($actual, true) . ')'
        );
    }
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ' (không thấy ' . var_export($needle, true) . ')');
    }
}

function assertNotContains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ' (vẫn thấy ' . var_export($needle, true) . ')');
    }
}

function expectException(
    callable $callback,
    string $class,
    ?string $contains = null,
    string $message = 'Phải ném exception'
): Throwable {
    try {
        $callback();
    } catch (Throwable $exception) {
        if (!$exception instanceof $class) {
            throw new RuntimeException(
                $message . ': sai loại exception, nhận ' . get_class($exception)
                . ' (' . $exception->getMessage() . ')'
            );
        }
        if ($contains !== null && !str_contains($exception->getMessage(), $contains)) {
            throw new RuntimeException(
                $message . ': message không chứa ' . var_export($contains, true)
                . ' (nhận ' . var_export($exception->getMessage(), true) . ')'
            );
        }
        return $exception;
    }
    throw new RuntimeException($message . ': không có exception nào được ném.');
}

function testFinish(string $prefix = 'TESTS'): never
{
    echo $prefix . '=' . $GLOBALS['test_count']
        . ' FAILED=' . $GLOBALS['test_failed'] . "\n";
    exit($GLOBALS['test_failed'] === 0 ? 0 : 1);
}
