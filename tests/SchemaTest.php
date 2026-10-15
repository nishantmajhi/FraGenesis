<?php

declare(strict_types=1);

/**
 * The seeder and the test fixture have to agree about the schema, and the code
 * that reads the database has to agree with both.
 *
 * tests/Fixture.php holds a copy of the CREATE TABLE statements from
 * seed/DatabaseInitializer.php. A copy that drifts is a suite that passes
 * against a shape the real database does not have, so the copies are compared
 * here rather than trusted.
 */

Harness::test('the fixture schema matches the seeder, column for column', function () {
    $seeder = file_get_contents(
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'seed' . DIRECTORY_SEPARATOR . 'DatabaseInitializer.php'
    );
    Harness::ok($seeder !== false, 'seed/DatabaseInitializer.php is readable');

    foreach (array_keys(Fixture::SCHEMA) as $table) {
        // The seeder's statement for this table, reduced to a list of
        // "name TYPE" pairs so indentation and IF NOT EXISTS do not matter.
        Harness::same(
            columnsOf($seeder, $table),
            columnsOf(Fixture::SCHEMA[$table], $table),
            $table
        );
    }
});

Harness::test('the seeder creates the three tables the papers draw on', function () {
    $seeder = file_get_contents(
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'seed' . DIRECTORY_SEPARATOR . 'DatabaseInitializer.php'
    );
    foreach (['multipleChoice', 'shortAnswerQuestions', 'longAnswerQuestions'] as $table) {
        Harness::contains("CREATE TABLE IF NOT EXISTS $table", $seeder);
    }
});

Harness::test('the fixture really builds those tables', function () {
    $pdo    = Fixture::empty();
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
                  ->fetchAll(PDO::FETCH_COLUMN);
    // sqlite_sequence comes with AUTOINCREMENT and is why resolveQuestionTable()
    // checks for the question columns rather than accepting any table.
    Harness::same(
        ['longAnswerQuestions', 'multipleChoice', 'shortAnswerQuestions', 'sqlite_sequence'],
        $tables
    );
});

Harness::test('every column the code reads exists in the schema', function () {
    // fetchAllDistributed() filters on grade and topic and excludes on id;
    // buildArticle() reads question, marks and options.
    $pdo = Fixture::withSupply(['multipleChoice' => ['1.1' => 1]]);
    $row = $pdo->query('SELECT * FROM multipleChoice')->fetch(PDO::FETCH_ASSOC);

    foreach (['id', 'grade', 'unit', 'topic', 'question', 'options', 'marks'] as $column) {
        Harness::ok(array_key_exists($column, $row), $column);
    }
});

Harness::test('options is valid JSON, because the builder json_decodes it', function () {
    $pdo = Fixture::withSupply(['multipleChoice' => ['1.1' => 2]]);
    foreach ($pdo->query('SELECT options FROM multipleChoice') as $row) {
        Harness::ok(is_array(json_decode($row['options'], true)), $row['options']);
    }
});

/**
 * Extracts the column list of one CREATE TABLE statement, normalised.
 *
 * @return string[] e.g. ['id INTEGER PRIMARY KEY AUTOINCREMENT', 'grade INTEGER NOT NULL', ...]
 */
function columnsOf(string $sql, string $table): array
{
    if (preg_match('/CREATE TABLE (?:IF NOT EXISTS )?' . preg_quote($table, '/') . '\s*\((.*?)\)\s*"/s', $sql, $m) !== 1
        && preg_match('/CREATE TABLE (?:IF NOT EXISTS )?' . preg_quote($table, '/') . '\s*\((.*?)\)\s*$/s', $sql, $m) !== 1) {
        return ["no CREATE TABLE for $table"];
    }

    $columns = [];
    foreach (explode(',', $m[1]) as $column) {
        $column = preg_replace('/\s+/', ' ', trim($column));
        if ($column !== '') {
            $columns[] = $column;
        }
    }

    return $columns;
}
