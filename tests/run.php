<?php

/**
 * Test runner.
 *
 * Usage:  php tests/run.php  [filter]
 *
 * Loads every *Test.php in this directory and reports the total. An optional
 * argument limits the run to files whose name contains that substring.
 *
 * Nothing here touches db/questions.sqlite3. Every test that needs a question
 * bank builds one in memory from tests/Fixture.php, so the suite can be run
 * against a checkout that has never been seeded and cannot damage one that has.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Harness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'Fixture.php';

$filter = $argv[1] ?? '';
$files  = glob(__DIR__ . DIRECTORY_SEPARATOR . '*Test.php') ?: [];

sort($files);

foreach ($files as $file) {
    if ($filter !== '' && !str_contains(basename($file), $filter)) {
        continue;
    }

    echo basename($file, '.php') . PHP_EOL;

    require_once $file;
}

exit(Harness::summary());
