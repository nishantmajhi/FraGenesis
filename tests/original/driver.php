<?php

/**
 * Runs the ORIGINAL question/index.php against a throwaway question bank.
 *
 * Usage: php tests/original/driver.php '<json object to use as $_GET>'
 *
 * The files beside this one are byte-exact copies of commit d70b64f, and the
 * original resolves everything relative to dirname(__DIR__) — so from
 * tests/original/question/ it finds tests/original/utils, tests/original/src
 * and tests/original/db, and never the fixed code or the real database.
 *
 * It exists because the fixed code wants the same function names as the
 * original, so the two cannot share a process. Each assertion in
 * tests/OriginalTest.php is one run of this script, and what that test looks at
 * is the exit code and the output: the bugs being reproduced are fatal errors,
 * so the interesting evidence is that the process died and what it printed on
 * its way out.
 *
 * The bank is rebuilt on every run, in tests/original/db/, which is gitignored.
 * Nothing here can see db/questions.sqlite3.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Base64, not raw JSON. Windows escapeshellarg() replaces a double quote with
// a space rather than escaping it, so a JSON argument arrives with its quotes
// gone and json_decode() throws before any of the original code runs.
$query  = json_decode(base64_decode($argv[1] ?? '') ?: '{}', true, 8, JSON_THROW_ON_ERROR);
$supply = json_decode(base64_decode($argv[2] ?? '') ?: '{}', true, 8, JSON_THROW_ON_ERROR);

seedThrowawayBank($supply);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) driver';
$_GET = $query;

require __DIR__ . DIRECTORY_SEPARATOR . 'question' . DIRECTORY_SEPARATOR . 'index.php';

/**
 * @param array $supply ['multipleChoice' => ['1.1' => 4], ...]
 */
function seedThrowawayBank(array $supply): void
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'db';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $path = $dir . DIRECTORY_SEPARATOR . 'questions.sqlite3';
    if (file_exists($path)) {
        unlink($path);
    }

    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixture.php';

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (Fixture::SCHEMA as $sql) {
        $pdo->exec($sql);
    }

    foreach ($supply as $table => $topics) {
        $hasOptions = $table === 'multipleChoice';
        $columns    = $hasOptions
            ? '(grade, unit, topic, question_id, question, options, marks)'
            : '(grade, unit, topic, question_id, question, marks)';
        $values     = $hasOptions ? '(?, ?, ?, ?, ?, ?, ?)' : '(?, ?, ?, ?, ?, ?)';
        $stmt       = $pdo->prepare("INSERT INTO $table $columns VALUES $values");

        foreach ($topics as $topic => $count) {
            $unit = (int) explode('.', (string) $topic)[0];
            for ($i = 1; $i <= $count; $i++) {
                $row = [12, $unit, (string) $topic, $i, "$table $topic #$i"];
                if ($hasOptions) {
                    $row[] = json_encode(["opt $i a", "opt $i b"]);
                }
                $row[] = Fixture::MARKS[$table];
                $stmt->execute($row);
            }
        }
    }
}
