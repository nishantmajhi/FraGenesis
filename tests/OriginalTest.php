<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'validateRequest.php';

/**
 * Every bug this pass fixed, reproduced against the original code.
 *
 * A regression test that has never been seen to fail is testing nothing, and
 * the fixed code reuses the original's function names so the two cannot share a
 * process. So each case below runs tests/original/driver.php — which loads
 * byte-exact copies of commit d70b64f against a throwaway question bank — and
 * asserts that the original really did what it is accused of. Then it asserts
 * that validatePaperRequest() refuses the same input.
 */
final class Original
{
    /** The commit the copies in tests/original/ were taken from. */
    public const BASELINE = 'd70b64f';

    /**
     * Runs the original router with $query as $_GET.
     *
     * @param array $supply ['multipleChoice' => ['1.1' => 4], ...]
     * @return array{code:int, out:string}
     */
    public static function request(array $query, array $supply = [], string $iniFlags = ''): array
    {
        $command = sprintf(
            '%s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            $iniFlags,
            escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'original' . DIRECTORY_SEPARATOR . 'driver.php'),
            escapeshellarg(base64_encode(json_encode($query))),
            escapeshellarg(base64_encode(json_encode($supply)))
        );

        $out  = [];
        $code = 0;
        exec($command, $out, $code);

        return ['code' => $code, 'out' => implode("\n", $out)];
    }

    /** A bank with plenty of everything, for the cases that get that far. */
    public static function plenty(): array
    {
        return [
            'multipleChoice'       => ['1.1' => 20, '2.1' => 20],
            'shortAnswerQuestions' => ['1.1' => 20, '2.1' => 20],
            'longAnswerQuestions'  => ['1.1' => 20, '2.1' => 20],
        ];
    }
}

Harness::test('the copies in tests/original/ still match the baseline commit', function () {
    // A transcription that has drifted asserts nothing. If git is not available
    // this skips rather than failing, so the suite still runs from a tarball.
    $root = dirname(__DIR__);
    exec(sprintf('git -C %s rev-parse --verify %s 2>&1', escapeshellarg($root), Original::BASELINE), $out, $code);

    if ($code !== 0) {
        Harness::skip('git could not resolve ' . Original::BASELINE . ' — cannot verify the copies');
        return;
    }

    $files = [
        'utils/fetchQuestions.php',
        'utils/groupTopics.php',
        'utils/invalidQuery.php',
        'utils/intToRoman.php',
        'utils/osCheck.php',
        'src/unit.php',
        'src/internal.php',
        'src/terminal.php',
        'question/index.php',
        'question/question.html',
    ];

    foreach ($files as $file) {
        // shell_exec, not exec. PHP's exec() fills its output array by lines and
        // strips trailing whitespace from each one, and several of these files
        // have trailing spaces — so exec() reports a difference that is not
        // there and makes this check useless.
        $committed = shell_exec(sprintf(
            'git -C %s show %s:%s',
            escapeshellarg($root),
            Original::BASELINE,
            escapeshellarg($file)
        ));

        $copy = file_get_contents($root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'original'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));

        // Line endings are normalised because git hands back LF and the working
        // tree has CRLF; everything else is compared exactly.
        Harness::same(
            str_replace("\r\n", "\n", (string) $committed),
            str_replace("\r\n", "\n", $copy),
            $file
        );
    }
});

Harness::test('the original generated a paper, so the driver itself works', function () {
    // The control. If this failed, every reproduction below would "pass" for the
    // wrong reason.
    $r = Original::request(
        ['grade' => '12', 'fullMarks' => '30', 'topics' => '1.1,2.1'],
        Original::plenty()
    );
    Harness::same(0, $r['code'], 'the original exited cleanly');
    Harness::contains('class="marks">7x1=7', $r['out']);
    Harness::contains('class="marks">3x5=15', $r['out']);
    Harness::contains('class="marks">1x8=8', $r['out']);
    Harness::contains('Grade XII', $r['out']);
});

// ── P1: four uncaught fatal errors, each reachable from a URL ────────────────

Harness::test('?short=abc was an uncaught TypeError in the original', function () {
    // question/index.php:32 — echo unitTest($grade, $topics, $MCQ, $shortQuestions / 5)
    $query = ['grade' => '12', 'fullMarks' => '20', 'topics' => '1.1', 'short' => 'abc'];

    $r = Original::request($query, Original::plenty());
    Harness::contains('Uncaught TypeError', $r['out']);
    Harness::contains('Unsupported operand types: string / int', $r['out']);
    Harness::contains('question' . DIRECTORY_SEPARATOR . 'index.php', $r['out'], 'leaked the file path');
    Harness::contains('Stack trace', $r['out'], 'leaked a stack trace');

    Harness::same(false, validatePaperRequest($query)['ok'], 'refused now');
});

Harness::test('?grade=abc was an uncaught TypeError in the original', function () {
    // fetchAllDistributed(PDO, int $grade, ...) four calls further down.
    $query = ['grade' => 'abc', 'fullMarks' => '30', 'topics' => '1.1'];

    $r = Original::request($query, Original::plenty());
    Harness::contains('Uncaught TypeError', $r['out']);
    Harness::contains('must be of type int, string given', $r['out']);
    Harness::contains('Stack trace', $r['out']);

    Harness::same(false, validatePaperRequest($query)['ok'], 'refused now');
});

Harness::test('?topics[0][]=1 was an uncaught TypeError inside trim()', function () {
    // utils/groupTopics.php:18. Not in the first review.
    $query = ['grade' => '12', 'fullMarks' => '30', 'topics' => [['1']]];

    $r = Original::request($query, Original::plenty());
    Harness::contains('Uncaught TypeError', $r['out']);
    Harness::contains('trim(): Argument #1', $r['out']);
    Harness::contains('groupTopics.php', $r['out'], 'leaked the file path');

    Harness::same(false, validatePaperRequest($query)['ok'], 'refused now');
});

Harness::test('?grade=1e15 exhausted the memory limit in the original', function () {
    // intToRoman() appends one 'M' per thousand, so 1e15 asks for a string of a
    // million million characters. Not in the first review. Run with a small
    // limit so the reproduction costs a moment rather than four seconds.
    $query = ['grade' => '1e15', 'fullMarks' => '30', 'topics' => '1.1'];

    $r = Original::request($query, Original::plenty(), '-d memory_limit=32M');
    Harness::contains('Allowed memory size', $r['out']);
    Harness::contains('intToRoman.php', $r['out'], 'named the function that did it');

    Harness::same(false, validatePaperRequest($query)['ok'], 'refused now');
});

// ── P1: a fractional count reached a SQL LIMIT ───────────────────────────────

Harness::test('a fractional short count reached LIMIT and SQLite refused it', function () {
    // ?short=6 asked for 1.2 questions. The allocation loop ran twice, the topic
    // could only supply one, and the leftover 0.2 went into LIMIT. Not in the
    // first review — which said the interpolated LIMIT was "safe today".
    $query = ['grade' => '12', 'fullMarks' => '20', 'topics' => '1.1', 'mcq' => '0', 'short' => '6'];

    $r = Original::request($query, ['shortAnswerQuestions' => ['1.1' => 1]]);
    Harness::contains('datatype mismatch', $r['out']);

    Harness::same(false, validatePaperRequest($query)['ok'], 'refused now');
});

Harness::test('a fractional mcq count reached LIMIT too', function () {
    $query = ['grade' => '12', 'fullMarks' => '20', 'topics' => '1.1', 'mcq' => '3.5', 'short' => '0'];

    $r = Original::request($query, ['multipleChoice' => ['1.1' => 1]]);
    Harness::contains('datatype mismatch', $r['out']);

    Harness::same(false, validatePaperRequest($query)['ok'], 'refused now');
});

// ── P1: a paper that did not add up to its own full marks ────────────────────

Harness::test('?mcq=4&short=16 printed a 24-mark paper under a 20-mark title', function () {
    // 16 marks of five-mark questions is 3.2 questions. The loop ran four times,
    // so the paper carried four of them and announced "4x5=20" beside "4x1=4".
    // Not in the first review.
    $query = ['grade' => '12', 'fullMarks' => '20', 'topics' => '1.1', 'mcq' => '4', 'short' => '16'];

    $r = Original::request($query, Original::plenty());
    Harness::same(0, $r['code'], 'the original answered 200');
    Harness::contains('class="marks">4x1=4', $r['out']);
    Harness::contains('class="marks">4x5=20', $r['out']);

    preg_match_all('/class="marks">\d+x\d+=(\d+)</', $r['out'], $m);
    Harness::same(24, array_sum(array_map('intval', $m[1])), 'the original paper totalled');

    Harness::same(false, validatePaperRequest($query)['ok'], 'refused now');
});

// ── P2: silently empty papers ────────────────────────────────────────────────

Harness::test('an empty topics list produced a paper with no questions in it', function () {
    // The front end sends topics= with nothing after it when no box is ticked.
    // explode(',', '') is [''], so the original built a paper for the topic ""
    // and said nothing was wrong.
    $query = ['grade' => '12', 'fullMarks' => '30', 'topics' => ''];

    $r = Original::request($query, Original::plenty());
    Harness::same(0, $r['code'], 'the original answered 200');
    Harness::contains('<article></article>', $r['out'], 'an empty paper');
    Harness::notContains('class="marks">', $r['out']);

    Harness::same(false, validatePaperRequest($query)['ok'], 'refused now');
});

Harness::test('a negative count dropped a whole group rather than reaching SQL', function () {
    // The first review said ?short=-50 became LIMIT -5 and that SQLite rejected
    // it. Neither half is true: src/unit.php guarded each type with
    // `if (... > 0)`, so the group was never asked for — and SQLite reads a
    // negative LIMIT as no limit at all.
    $query = ['grade' => '12', 'fullMarks' => '20', 'topics' => '1.1', 'mcq' => '5', 'short' => '-50'];

    $r = Original::request($query, Original::plenty());
    Harness::same(0, $r['code'], 'no error at all');
    Harness::contains('class="marks">5x1=5', $r['out'], 'Group A was generated');
    Harness::notContains('id="short"', $r['out'], 'Group B was silently dropped');
    Harness::notContains('LIMIT', $r['out']);

    Harness::same(false, validatePaperRequest($query)['ok'], 'refused now');
});

Harness::test('a leading zero in a topic quietly produced a shorter paper', function () {
    // "04.2" is not what the database stores, so it matched nothing and the
    // backfill had nowhere to go.
    $r = Original::request(
        ['grade' => '12', 'fullMarks' => '20', 'topics' => '04.2', 'mcq' => '5', 'short' => '15'],
        ['multipleChoice' => ['4.2' => 20], 'shortAnswerQuestions' => ['4.2' => 20]]
    );
    Harness::same(0, $r['code']);
    Harness::contains('<article></article>', $r['out'], 'nothing came back');

    Harness::same(false, validatePaperRequest(
        ['grade' => '12', 'fullMarks' => '20', 'topics' => '04.2']
    )['ok'], 'refused now');
});

// ── the original's own diagnostics leaked ────────────────────────────────────

Harness::test('a request with no User-Agent printed two PHP diagnostics', function () {
    // utils/osCheck.php:3 read $_SERVER['HTTP_USER_AGENT'] without checking.
    // index.php is the entry point for that one, so it is exercised directly.
    $script = <<<'PHP'
        <?php
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
        unset($_SERVER['HTTP_USER_AGENT']);
        require $argv[1];
        var_dump(isPcOS());
        PHP;

    $tmp = tempnam(sys_get_temp_dir(), 'fragenesis-ua-') . '.php';
    file_put_contents($tmp, $script);

    $original = __DIR__ . DIRECTORY_SEPARATOR . 'original' . DIRECTORY_SEPARATOR . 'utils'
        . DIRECTORY_SEPARATOR . 'osCheck.php';
    exec(sprintf('%s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($tmp), escapeshellarg($original)), $out);
    unlink($tmp);

    $text = implode("\n", $out);
    Harness::contains('Undefined array key "HTTP_USER_AGENT"', $text);
    Harness::contains('Passing null to parameter #1', $text);

    // And the fixed one is quiet.
    $fixed = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'osCheck.php';
    $tmp   = tempnam(sys_get_temp_dir(), 'fragenesis-ua-') . '.php';
    file_put_contents($tmp, $script);
    $out = [];
    exec(sprintf('%s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($tmp), escapeshellarg($fixed)), $out);
    unlink($tmp);

    $text = implode("\n", $out);
    Harness::notContains('Undefined array key', $text);
    Harness::notContains('Deprecated', $text);
    Harness::contains('bool(false)', $text, 'still answers false for an unknown client');
});
