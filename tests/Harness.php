<?php

declare(strict_types=1);

/**
 * A plain assertion harness. No framework, because this project had none and
 * adding composer to it is a bigger imposition than a hundred lines of this.
 */
final class Harness
{
    private static int $tests      = 0;
    private static int $assertions = 0;
    /** @var string[] */
    private static array $failures = [];
    private static ?string $current = null;

    /** Runs one named test. A throw inside it is a failure, not a crash. */
    public static function test(string $name, callable $body): void
    {
        self::$tests++;
        self::$current = $name;
        $before = count(self::$failures);

        try {
            $body();
        } catch (Throwable $e) {
            self::$failures[] = sprintf('%s: threw %s: %s', $name, get_class($e), $e->getMessage());
        }

        $status = count(self::$failures) === $before ? 'ok  ' : 'FAIL';
        echo "  $status  $name" . PHP_EOL;
        self::$current = null;
    }

    public static function ok(bool $condition, string $message = ''): void
    {
        self::$assertions++;
        if (!$condition) {
            self::fail($message !== '' ? $message : 'expected true');
        }
    }

    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::$assertions++;
        if ($expected !== $actual) {
            self::fail(sprintf(
                '%sexpected %s, got %s',
                $message === '' ? '' : "$message: ",
                self::show($expected),
                self::show($actual)
            ));
        }
    }

    public static function contains(string $needle, string $haystack, string $message = ''): void
    {
        self::$assertions++;
        if (!str_contains($haystack, $needle)) {
            self::fail(sprintf(
                '%sexpected to find %s in %s',
                $message === '' ? '' : "$message: ",
                self::show($needle),
                self::show(self::clip($haystack))
            ));
        }
    }

    public static function notContains(string $needle, string $haystack, string $message = ''): void
    {
        self::$assertions++;
        if (str_contains($haystack, $needle)) {
            self::fail(sprintf(
                '%sexpected NOT to find %s in %s',
                $message === '' ? '' : "$message: ",
                self::show($needle),
                self::show(self::clip($haystack))
            ));
        }
    }

    /** Asserts that $body throws, and that the message mentions $expectPart. */
    public static function throws(string $expectPart, callable $body, string $message = ''): void
    {
        self::$assertions++;
        try {
            $body();
        } catch (Throwable $e) {
            if ($expectPart !== '' && !str_contains($e->getMessage(), $expectPart)) {
                self::fail(sprintf(
                    '%sthrew %s but its message %s does not mention %s',
                    $message === '' ? '' : "$message: ",
                    get_class($e),
                    self::show($e->getMessage()),
                    self::show($expectPart)
                ));
            }
            return;
        }
        self::fail(($message === '' ? '' : "$message: ") . 'expected a throw, nothing was thrown');
    }

    public static function skip(string $reason): void
    {
        echo "  skip  $reason" . PHP_EOL;
    }

    private static function fail(string $detail): void
    {
        self::$failures[] = (self::$current ?? '(outside a test)') . ': ' . $detail;
    }

    private static function show(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . $value . '"';
        }
        return var_export($value, true);
    }

    private static function clip(string $s): string
    {
        return strlen($s) > 400 ? substr($s, 0, 400) . '…' : $s;
    }

    /** Prints the totals and returns the process exit code. */
    public static function summary(): int
    {
        echo PHP_EOL;

        foreach (self::$failures as $failure) {
            echo "FAIL  $failure" . PHP_EOL;
        }

        if (self::$failures !== []) {
            echo PHP_EOL;
        }

        printf(
            '%d tests, %d assertions, %d failures%s',
            self::$tests,
            self::$assertions,
            count(self::$failures),
            PHP_EOL
        );

        return self::$failures === [] ? 0 : 1;
    }
}
